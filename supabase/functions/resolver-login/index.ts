// Fase 1 · Edge Function `resolver-login`
//
// Preserva el login por **correo O DNI** (decisión §6.2). Supabase Auth solo
// autentica por correo, así que el frontend llama primero acá con lo que tecleó
// el usuario y recibe el correo canónico de auth.users; luego hace
// signInWithPassword({ email, password }) normal.
//
// La contraseña NUNCA pasa por esta función.
//
// Consulta la BD directamente (SUPABASE_DB_URL) en vez de PostgREST: así funciona
// aunque `public.users` no esté expuesta al Data API (recomendado tenerla oculta).
//
// Anti-enumeración: para un identificador desconocido devuelve un correo
// sintético inexistente → el signInWithPassword posterior falla con
// invalid_credentials igual que con una contraseña incorrecta.

import postgres from "npm:postgres@3";

const CORS = {
  "Access-Control-Allow-Origin": Deno.env.get("RESOLVER_LOGIN_ALLOWED_ORIGIN") ?? "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { ...CORS, "Content-Type": "application/json" },
  });

const sql = postgres(Deno.env.get("SUPABASE_DB_URL")!, { prepare: false, max: 2 });

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  if (req.method !== "POST") return json({ error: "method_not_allowed" }, 405);

  let login: unknown;
  try {
    ({ login } = await req.json());
  } catch {
    return json({ error: "bad_request" }, 400);
  }
  if (typeof login !== "string" || login.trim().length === 0 || login.length > 150) {
    return json({ error: "bad_request" }, 400);
  }

  const value = login.trim();
  const esEmail = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value);

  try {
    // public.users está bajo RLS con FORCE (aplica también al rol `postgres`).
    // Esta función es un servicio de confianza: setea el claim que la RLS
    // interpreta como "proveedor" para poder resolver cualquier usuario.
    const rows = await sql.begin(async (sql) => {
      await sql`select set_config('request.jwt.claims', '{"es_proveedor":true}', true)`;
      return esEmail
        ? sql`select email, dni from public.users where lower(email) = lower(${value}) limit 1`
        : sql`select email, dni from public.users where dni = ${value} or lower(email) = lower(${value}) limit 1`;
    });

    if (rows.length > 0) {
      const u = rows[0];
      // Correo canónico = misma regla que el backfill (20260830200000_fase1_backfill_auth).
      const canonical = (u.email && String(u.email).trim())
        ? String(u.email).trim().toLowerCase()
        : `dni-${u.dni}@no-email.sistemaconfirmacion.local`;
      return json({ email: canonical });
    }
  } catch (e) {
    console.error("resolver-login db error", e);
    return json({ error: "server_error" }, 500);
  }

  const slug = value.toLowerCase().replace(/[^a-z0-9._-]+/g, "-").slice(0, 60);
  return json({ email: `${slug}@unknown.invalid` });
});
