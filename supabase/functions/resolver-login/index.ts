// Fase 1 · Edge Function `resolver-login`
//
// Preserva el login por **correo O DNI** (decisión §6.2). Supabase Auth solo
// autentica por correo, así que el frontend llama primero acá con lo que tecleó
// el usuario y recibe el correo canónico de auth.users; luego hace
// signInWithPassword({ email, password }) normal.
//
// La contraseña NUNCA pasa por esta función.
//
// Anti-enumeración: para un identificador desconocido devuelve un correo
// sintético inexistente → el signInWithPassword posterior falla con
// invalid_credentials igual que con una contraseña incorrecta, sin revelar si
// el identificador existe.

import { createClient } from "jsr:@supabase/supabase-js@2";

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

  const admin = createClient(
    Deno.env.get("SUPABASE_URL")!,
    Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!,
    { auth: { persistSession: false } },
  );

  // Buscamos la fila de la app por correo o por DNI. `esEmail` acota la condición
  // para no filtrar por DNI algo que claramente es un correo (y viceversa).
  const filtro = esEmail ? `email.eq.${value}` : `dni.eq.${value},email.eq.${value}`;
  const { data, error } = await admin
    .from("users")
    .select("email, dni")
    .or(filtro)
    .limit(1)
    .maybeSingle();

  if (error) {
    console.error("resolver-login query error", error);
    return json({ error: "server_error" }, 500);
  }

  if (data) {
    // Correo canónico = misma regla que el backfill (20260830200000_fase1_backfill_auth).
    const canonical = (data.email?.trim())
      ? data.email.trim().toLowerCase()
      : `dni-${data.dni}@no-email.sistemaconfirmacion.local`;
    return json({ email: canonical });
  }

  // Desconocido: correo sintético inerte (mismo shape de respuesta) → el
  // signInWithPassword posterior falla con invalid_credentials.
  const slug = value.toLowerCase().replace(/[^a-z0-9._-]+/g, "-").slice(0, 60);
  return json({ email: `${slug}@unknown.invalid` });
});
