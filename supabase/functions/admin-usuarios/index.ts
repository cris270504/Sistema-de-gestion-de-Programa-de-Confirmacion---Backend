// Fase 5 · Edge Function `admin-usuarios`
//
// Reemplaza UserController (store / update / estado / destroy). Estas operaciones
// tocan `auth.users` (Auth Admin API), así que no pueden ser una RPC pura.
//
// Flujo: verifica el JWT del que llama, comprueba que exista y esté activo, y
// delega la parte de BD (public.users + model_has_roles + catequista_grupo +
// limpieza de historial) en funciones SECURITY DEFINER que solo `service_role`
// puede ejecutar, pasándoles el `auth.uid()` real del solicitante. La
// autorización fina (privilegiado, parroquia, no-tocar-tu-cuenta, rol proveedor)
// vive en esas funciones.
//
// Body: { action: 'create'|'update'|'estado'|'delete', ...campos }

import { createClient } from "npm:@supabase/supabase-js@2";
import { buildCors, origenBloqueado } from "../_shared/cors.ts";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;

// Errores de Postgres → mensaje para el usuario (misma idea que las 422 de Laravel).
function mapDbError(error: { code?: string; message?: string }): { message: string; status: number } {
  const msg = error.message ?? "Error";
  if (error.code === "23505") {
    if (msg.includes("users_email_unique") || msg.includes("email")) {
      return { message: "Ya existe un usuario con ese correo.", status: 422 };
    }
    if (msg.includes("users_dni_unique") || msg.includes("dni")) {
      return { message: "Ya existe un usuario con ese DNI.", status: 422 };
    }
    return { message: "Registro duplicado.", status: 422 };
  }
  if (error.code === "P0001" || error.code === "23514") return { message: msg, status: 422 };
  if (error.code === "42501") return { message: msg || "No autorizado.", status: 403 };
  if (error.code === "P0002") return { message: msg || "No encontrado.", status: 404 };
  return { message: msg, status: 400 };
}

function tempPassword(): string {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";
  const bytes = crypto.getRandomValues(new Uint8Array(14));
  return Array.from(bytes, (b) => chars[b % chars.length]).join("");
}

const asArray = (v: unknown): unknown[] | null => (Array.isArray(v) ? v : null);
const asInt = (v: unknown): number | null => {
  const n = Number(v);
  return Number.isInteger(n) ? n : null;
};

Deno.serve(async (req) => {
  const CORS = buildCors(req);
  const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), { status, headers: { ...CORS, "Content-Type": "application/json" } });

  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  if (origenBloqueado(req)) return json({ message: "Origen no permitido." }, 403);
  if (req.method !== "POST") return json({ message: "method_not_allowed" }, 405);

  const authHeader = req.headers.get("Authorization") ?? "";
  if (!authHeader.startsWith("Bearer ")) return json({ message: "No autenticado." }, 401);

  // 1. Identidad del solicitante
  const caller = createClient(SUPABASE_URL, ANON_KEY, {
    global: { headers: { Authorization: authHeader } },
  });
  const { data: userData, error: userErr } = await caller.auth.getUser();
  if (userErr || !userData?.user) return json({ message: "Sesión no válida." }, 401);
  const actorAuthId = userData.user.id;

  let body: Record<string, unknown>;
  try {
    body = await req.json();
  } catch {
    return json({ message: "Cuerpo inválido." }, 400);
  }
  const action = body.action;

  const admin = createClient(SUPABASE_URL, SERVICE_KEY, {
    auth: { autoRefreshToken: false, persistSession: false },
  });

  const datos = (b: Record<string, unknown>) => ({
    name: b.name,
    dni: b.dni ?? null,
    celular: b.celular ?? null,
    email: b.email,
    fecha_nacimiento: b.fecha_nacimiento ?? null,
  });

  try {
    // ─────────────────────────────────────────────────────────────── CREATE
    if (action === "create") {
      const email = String(body.email ?? "").trim().toLowerCase();
      const roles = asArray(body.roles)?.map(String) ?? [];
      const grupoIds = (asArray(body.grupo_ids) ?? []).map(asInt).filter((n): n is number => n !== null);
      if (!body.name || !email) return json({ message: "Nombre y correo son obligatorios." }, 422);
      if (roles.length === 0) return json({ message: "Selecciona al menos un rol." }, 422);

      const tmp = tempPassword();
      const { data: created, error: authErr } = await admin.auth.admin.createUser({
        email,
        password: tmp,
        email_confirm: true,
      });
      if (authErr || !created?.user) {
        const m = authErr?.message ?? "";
        const dup = m.includes("already been registered") || m.includes("already exists");
        return json({ message: dup ? "Ya existe un usuario con ese correo." : (m || "No se pudo crear la cuenta.") }, dup ? 422 : 400);
      }
      const newAuthId = created.user.id;

      const { data: row, error: rpcErr } = await admin.rpc("fn_admin_guardar_usuario", {
        p_actor_auth: actorAuthId,
        p_id: null,
        p_new_auth_id: newAuthId,
        p_datos: { ...datos(body), email },
        p_roles: roles,
        p_grupo_ids: grupoIds,
        p_temp_password: tmp,
      });
      if (rpcErr) {
        await admin.auth.admin.deleteUser(newAuthId); // rollback del auth.users huérfano
        const { message, status } = mapDbError(rpcErr);
        return json({ message }, status);
      }

      return json({ message: "Usuario creado con éxito", temp_password: tmp, user: row }, 201);
    }

    // ─────────────────────────────────────────────────────────────── UPDATE
    if (action === "update") {
      const id = asInt(body.id);
      if (!id) return json({ message: "id inválido." }, 400);

      const { data: target, error: tErr } = await admin.rpc("fn_admin_target_auth", {
        p_actor_auth: actorAuthId,
        p_id: id,
      });
      if (tErr) {
        const { message, status } = mapDbError(tErr);
        return json({ message }, status);
      }

      const newEmail = body.email !== undefined ? String(body.email).trim().toLowerCase() : undefined;
      const authPatch: Record<string, unknown> = {};
      if (newEmail && newEmail !== target.email) authPatch.email = newEmail;
      if (body.password) authPatch.password = String(body.password);
      if (Object.keys(authPatch).length > 0 && target.auth_id) {
        const { error: authErr } = await admin.auth.admin.updateUserById(target.auth_id, authPatch);
        if (authErr) {
          const m = authErr.message ?? "";
          const dup = m.includes("already been registered") || m.includes("already exists");
          return json({ message: dup ? "Ya existe un usuario con ese correo." : (m || "No se pudo actualizar la cuenta.") }, dup ? 422 : 400);
        }
      }

      const patch: Record<string, unknown> = {};
      for (const k of ["name", "dni", "celular", "fecha_nacimiento"]) {
        if (body[k] !== undefined) patch[k] = body[k] ?? null;
      }
      if (newEmail !== undefined) patch.email = newEmail;

      const { data: row, error: rpcErr } = await admin.rpc("fn_admin_guardar_usuario", {
        p_actor_auth: actorAuthId,
        p_id: id,
        p_new_auth_id: null,
        p_datos: patch,
        p_roles: body.roles !== undefined ? (asArray(body.roles)?.map(String) ?? []) : null,
        p_grupo_ids: body.grupo_ids !== undefined
          ? (asArray(body.grupo_ids) ?? []).map(asInt).filter((n): n is number => n !== null)
          : null,
        p_temp_password: null,
      });
      if (rpcErr) {
        const { message, status } = mapDbError(rpcErr);
        return json({ message }, status);
      }
      return json({ message: "Usuario actualizado con éxito", user: row });
    }

    // ─────────────────────────────────────────────────────────────── ESTADO
    if (action === "estado") {
      const id = asInt(body.id);
      const activo = Boolean(body.activo);
      if (!id) return json({ message: "id inválido." }, 400);

      const { data: res, error: rpcErr } = await admin.rpc("fn_admin_estado_usuario", {
        p_actor_auth: actorAuthId,
        p_id: id,
        p_activo: activo,
      });
      if (rpcErr) {
        const { message, status } = mapDbError(rpcErr);
        return json({ message }, status);
      }

      const { data: target } = await admin.rpc("fn_admin_target_auth", {
        p_actor_auth: actorAuthId,
        p_id: id,
      });
      if (target?.auth_id) {
        await admin.auth.admin.updateUserById(target.auth_id, {
          ban_duration: activo ? "none" : "876000h",
        });
      }
      return json({ message: activo ? "Usuario activado." : "Usuario desactivado.", user: res });
    }

    // ─────────────────────────────────────────────────────────────── DELETE
    if (action === "delete") {
      const id = asInt(body.id);
      if (!id) return json({ message: "id inválido." }, 400);

      const { data: res, error: rpcErr } = await admin.rpc("fn_admin_eliminar_usuario", {
        p_actor_auth: actorAuthId,
        p_id: id,
      });
      if (rpcErr) {
        const { message, status } = mapDbError(rpcErr);
        return json({ message }, status);
      }
      const authId = (res as { auth_id?: string } | null)?.auth_id;
      if (authId) await admin.auth.admin.deleteUser(authId);
      return new Response(null, { status: 204, headers: CORS });
    }

    return json({ message: "Acción no reconocida." }, 400);
  } catch (e) {
    console.error("admin-usuarios error", e);
    return json({ message: "Error del servidor." }, 500);
  }
});
