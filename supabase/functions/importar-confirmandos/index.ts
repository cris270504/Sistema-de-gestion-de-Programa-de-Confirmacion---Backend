// Fase 5 · Edge Function `importar-confirmandos`
//
// Reemplaza ConfirmandoController::importar. Recibe un .xlsx/.xls/.csv
// (multipart, campo `archivo`), lo parsea, separa el nombre completo
// ("Apellido1 Apellido2 Nombre1 Nombre2"), sanea y valida el celular (9 díg), y
// pasa las filas limpias a fn_importar_confirmandos (service_role).
//
// Devuelve { message } (200) o { message, errors: { archivo: [...] } } (422) —
// mismo contrato que el endpoint de Laravel.

import { createClient } from "npm:@supabase/supabase-js@2";
import * as XLSX from "npm:xlsx@0.18.5";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;

const CORS = {
  "Access-Control-Allow-Origin": Deno.env.get("IMPORTAR_CONFIRMANDOS_ALLOWED_ORIGIN") ?? "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { ...CORS, "Content-Type": "application/json" } });

const stripTags = (s: string) => s.replace(/<[^>]*>/g, "");

// "Apellido1 Apellido2 Nombre1 Nombre2" → { apellidos, nombres }  (== Laravel)
function separarNombre(completo: string): { apellidos: string; nombres: string } {
  const partes = completo.split(/\s+/).filter(Boolean);
  if (partes.length >= 3) return { apellidos: `${partes[0]} ${partes[1]}`, nombres: partes.slice(2).join(" ") };
  if (partes.length === 2) return { apellidos: partes[0], nombres: partes[1] };
  return { apellidos: "", nombres: partes[0] ?? "" };
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  if (req.method !== "POST") return json({ message: "method_not_allowed" }, 405);

  const authHeader = req.headers.get("Authorization") ?? "";
  if (!authHeader.startsWith("Bearer ")) return json({ message: "No autenticado." }, 401);

  const caller = createClient(SUPABASE_URL, ANON_KEY, { global: { headers: { Authorization: authHeader } } });
  const { data: userData, error: userErr } = await caller.auth.getUser();
  if (userErr || !userData?.user) return json({ message: "Sesión no válida." }, 401);

  let file: File | null = null;
  try {
    const form = await req.formData();
    const f = form.get("archivo");
    if (f instanceof File) file = f;
  } catch {
    return json({ message: "No se pudo leer el archivo." }, 400);
  }
  if (!file) return json({ message: "El campo `archivo` es obligatorio." }, 422);
  if (file.size > 5 * 1024 * 1024) return json({ message: "El archivo supera los 5 MB." }, 422);
  const ext = (file.name.split(".").pop() ?? "").toLowerCase();
  if (!["xlsx", "xls", "csv"].includes(ext)) {
    return json({ message: "Formato no soportado. Usa .xlsx, .xls o .csv" }, 422);
  }

  let rows: unknown[][];
  try {
    const wb = XLSX.read(new Uint8Array(await file.arrayBuffer()), { type: "array" });
    const sheet = wb.Sheets[wb.SheetNames[0]];
    rows = XLSX.utils.sheet_to_json(sheet, { header: 1, blankrows: false, defval: "" }) as unknown[][];
  } catch {
    return json({ message: "No se pudo leer el archivo: formato inválido o dañado." }, 500);
  }

  const erroresFatales: string[] = [];
  const advertencias: string[] = [];
  const filas: { nombres: string; apellidos: string; celular: string }[] = [];

  rows.forEach((row, index) => {
    if (index === 0 && String(row?.[0] ?? "").trim().toLowerCase() === "nombres") return;

    const nombreCompleto = stripTags(String(row?.[0] ?? "")).trim();
    let celular = stripTags(String(row?.[1] ?? "")).trim();
    const numeroFila = index + 1;

    if (!nombreCompleto) {
      erroresFatales.push(`Fila ${numeroFila}: El nombre está vacío (No se guardó).`);
      return;
    }

    let { apellidos, nombres } = separarNombre(nombreCompleto);
    nombres = nombres.slice(0, 255);
    apellidos = apellidos.slice(0, 255);

    if (celular) {
      celular = celular.replace(/\s/g, "");
      if (!/^[0-9]{9}$/.test(celular)) {
        advertencias.push(`- ${nombreCompleto} (Fila ${numeroFila}): Se guardó sin celular porque '${celular}' no es válido.`);
        celular = "";
      }
    }
    filas.push({ nombres, apellidos, celular });
  });

  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { autoRefreshToken: false, persistSession: false } });
  const { data: res, error: rpcErr } = await admin.rpc("fn_importar_confirmandos", {
    p_actor_auth: userData.user.id,
    p_filas: filas,
  });
  if (rpcErr) {
    const status = rpcErr.code === "42501" ? 403 : 400;
    return json({ message: rpcErr.message }, status);
  }

  const importados = (res as { importados: number } | null)?.importados ?? 0;

  if (erroresFatales.length > 0) {
    return json({
      message: `Se importaron ${importados} confirmandos. Hubo filas omitidas.`,
      errors: { archivo: [...erroresFatales, ...advertencias] },
    }, 422);
  }

  let message = `Se importaron ${importados} confirmandos correctamente.`;
  if (advertencias.length > 0) message += `\n\nOjo, se hicieron estos ajustes:\n${advertencias.join("\n")}`;
  return json({ message });
});
