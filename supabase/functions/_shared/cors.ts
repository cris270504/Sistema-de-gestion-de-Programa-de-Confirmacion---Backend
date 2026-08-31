// CORS compartido de las Edge Functions.
//
// Config por secretos del proyecto:
//   ALLOWED_ORIGINS          CSV de orígenes exactos (p. ej.
//                            "https://miapp.com,http://localhost:5173")
//   ALLOWED_ORIGIN_PATTERNS  CSV de regex (p. ej. "^https://.*\\.vercel\\.app$")
//
// Sin ninguno de los dos → modo abierto ("*") con aviso en el log (útil en dev /
// mientras no se configuró). Con allowlist: se refleja el Origin de la petición
// solo si está permitido; si un navegador manda un Origin NO permitido, la
// petición se corta con 403 (no basta con omitir el header: el efecto lateral
// ya habría ocurrido).

const EXACT = (Deno.env.get("ALLOWED_ORIGINS") ?? "")
  .split(",").map((s) => s.trim()).filter(Boolean);

const PATTERNS = (Deno.env.get("ALLOWED_ORIGIN_PATTERNS") ?? "")
  .split(",").map((s) => s.trim()).filter(Boolean)
  .map((p) => {
    try { return new RegExp(p); } catch { return null; }
  })
  .filter((re): re is RegExp => re !== null);

const OPEN = EXACT.length === 0 && PATTERNS.length === 0;
if (OPEN) {
  console.warn("[cors] Sin ALLOWED_ORIGINS ni ALLOWED_ORIGIN_PATTERNS: CORS abierto ('*').");
}

const BASE: Record<string, string> = {
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
  "Vary": "Origin",
};

function permitido(origin: string): boolean {
  return EXACT.includes(origin) || PATTERNS.some((re) => re.test(origin));
}

/** Cabeceras CORS para esta petición. */
export function buildCors(req: Request): Record<string, string> {
  if (OPEN) return { ...BASE, "Access-Control-Allow-Origin": "*" };
  const origin = req.headers.get("Origin");
  if (origin && permitido(origin)) {
    return { ...BASE, "Access-Control-Allow-Origin": origin };
  }
  // Sin Origin (curl / server-to-server) o no permitido: no se emite ACAO.
  return { ...BASE };
}

/** true si es un fetch de navegador desde un Origin que NO está en la allowlist. */
export function origenBloqueado(req: Request): boolean {
  if (OPEN) return false;
  const origin = req.headers.get("Origin");
  return !!origin && !permitido(origin);
}
