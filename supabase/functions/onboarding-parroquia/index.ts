// Fase 5 · Edge Function `onboarding-parroquia`
//
// Reemplaza ProveedorParroquiaController::store: alta de una parroquia + su
// configuración por defecto + el primer usuario admin (super-admin) + el catálogo
// sacramental estándar. Crea un `auth.users` (el admin), por eso no puede ser una
// RPC pura.
//
// Verifica que quien llama tenga el permiso "administrar plataforma" (claim
// `permisos`), crea el auth.users del admin, y delega la BD (transacción) en
// fn_crear_parroquia, que solo ejecuta `service_role`. Si la RPC falla, borra el
// auth.users huérfano.
//
// Body: { nombre, admin_nombre, admin_email, slug?, zona_horaria?, admin_dni? }

import { createClient } from "npm:@supabase/supabase-js@2";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;

const CORS = {
  "Access-Control-Allow-Origin": Deno.env.get("ONBOARDING_PARROQUIA_ALLOWED_ORIGIN") ?? "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { ...CORS, "Content-Type": "application/json" } });

function mapDbError(error: { code?: string; message?: string }): { message: string; status: number } {
  const msg = error.message ?? "Error";
  if (error.code === "23505") {
    if (msg.includes("parroquias_slug")) return { message: "Ya existe una parroquia con ese identificador (slug).", status: 422 };
    if (msg.includes("users_email")) return { message: "Ya existe un usuario con ese correo.", status: 422 };
    if (msg.includes("users_dni")) return { message: "Ya existe un usuario con ese DNI.", status: 422 };
    return { message: "Registro duplicado.", status: 422 };
  }
  if (error.code === "P0001" || error.code === "23514") return { message: msg, status: 422 };
  if (error.code === "42501") return { message: msg || "No autorizado.", status: 403 };
  return { message: msg, status: 400 };
}

function tempPassword(): string {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";
  return Array.from(crypto.getRandomValues(new Uint8Array(14)), (b) => chars[b % chars.length]).join("");
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  if (req.method !== "POST") return json({ message: "method_not_allowed" }, 405);

  const authHeader = req.headers.get("Authorization") ?? "";
  if (!authHeader.startsWith("Bearer ")) return json({ message: "No autenticado." }, 401);

  const caller = createClient(SUPABASE_URL, ANON_KEY, { global: { headers: { Authorization: authHeader } } });
  const { data: userData, error: userErr } = await caller.auth.getUser();
  if (userErr || !userData?.user) return json({ message: "Sesión no válida." }, 401);
  const actorAuthId = userData.user.id;

  // Gate rápido por los claims del JWT (el hook los mete ahí, no en la fila de
  // auth.users). La RPC vuelve a chequear contra la BD con service_role.
  try {
    const b64 = authHeader.slice(7).split(".")[1].replace(/-/g, "+").replace(/_/g, "/");
    const claims = JSON.parse(atob(b64.padEnd(Math.ceil(b64.length / 4) * 4, "=")));
    const permisos = (claims.permisos ?? []) as string[];
    if (claims.es_proveedor !== true && !permisos.includes("administrar plataforma")) {
      return json({ message: "No autorizado para administrar la plataforma." }, 403);
    }
  } catch {
    return json({ message: "Token ilegible." }, 401);
  }

  let body: Record<string, unknown>;
  try {
    body = await req.json();
  } catch {
    return json({ message: "Cuerpo inválido." }, 400);
  }

  const nombre = String(body.nombre ?? "").trim();
  const adminNombre = String(body.admin_nombre ?? "").trim();
  const adminEmail = String(body.admin_email ?? "").trim().toLowerCase();
  if (!nombre || !adminNombre || !adminEmail) {
    return json({ message: "Nombre de la parroquia, y nombre y correo del admin son obligatorios." }, 422);
  }

  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { autoRefreshToken: false, persistSession: false } });

  try {
    const tmp = tempPassword();
    const { data: created, error: authErr } = await admin.auth.admin.createUser({
      email: adminEmail,
      password: tmp,
      email_confirm: true,
    });
    if (authErr || !created?.user) {
      const m = authErr?.message ?? "";
      const dup = m.includes("already been registered") || m.includes("already exists");
      return json({ message: dup ? "Ya existe un usuario con ese correo." : (m || "No se pudo crear la cuenta del admin.") }, dup ? 422 : 400);
    }
    const newAuthId = created.user.id;

    const { data: res, error: rpcErr } = await admin.rpc("fn_crear_parroquia", {
      p_actor_auth: actorAuthId,
      p_nombre: nombre,
      p_slug: body.slug ? String(body.slug).trim() : null,
      p_zona_horaria: body.zona_horaria ? String(body.zona_horaria).trim() : null,
      p_admin_nombre: adminNombre,
      p_admin_email: adminEmail,
      p_admin_dni: body.admin_dni ? String(body.admin_dni).trim() : null,
      p_admin_auth_id: newAuthId,
      p_temp_password: tmp,
    });
    if (rpcErr) {
      await admin.auth.admin.deleteUser(newAuthId);
      const { message, status } = mapDbError(rpcErr);
      return json({ message }, status);
    }

    const r = res as { parroquia: unknown; admin_email: string };
    return json({
      message: "Parroquia creada.",
      parroquia: r.parroquia,
      admin: { email: r.admin_email, temp_password: tmp },
    }, 201);
  } catch (e) {
    console.error("onboarding-parroquia error", e);
    return json({ message: "Error del servidor." }, 500);
  }
});
