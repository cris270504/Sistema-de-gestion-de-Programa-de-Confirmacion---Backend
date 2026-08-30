#!/usr/bin/env bash
set -euo pipefail
API="http://127.0.0.1:54321"
ANON="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0"

PSQL() { docker exec -e PGPASSWORD=postgres supabase_db_sistemaConfirmacionApi psql -U postgres -d postgres -t -A -c "$1"; }
echo "confirmandos SPIKE en BD: $(PSQL "select count(*) from public.confirmandos where nombres like 'SPIKE %'")"
echo

b64pad() { local s="$1"; local m=$(( ${#s} % 4 )); [ $m -eq 2 ] && s="$s=="; [ $m -eq 3 ] && s="$s="; echo "$s"; }
decode_claims() {
  local jwt="$1"; local payload="${jwt#*.}"; payload="${payload%.*}"
  b64pad "$payload" | tr '_-' '/+' | base64 -d 2>/dev/null | python -m json.tool
}

login() {  # email  -> echoes access_token
  curl -s -X POST "$API/auth/v1/token?grant_type=password" \
    -H "apikey: $ANON" -H "Content-Type: application/json" \
    -d "{\"email\":\"$1\",\"password\":\"password123\"}" \
    | python -c "import sys,json; d=json.load(sys.stdin); print(d.get('access_token',''), file=sys.stderr) if not d.get('access_token') else print(d['access_token'])"
}

query_confirmandos() {  # token
  curl -s "$API/rest/v1/confirmandos?select=nombres,apellidos,parroquia_id,grupo_id&nombres=like.SPIKE*&order=nombres" \
    -H "apikey: $ANON" -H "Authorization: Bearer $1"
}

for u in admin1@spike.test cat1@spike.test admin2@spike.test; do
  echo "══════════ $u ══════════"
  TOK=$(login "$u")
  echo "── claims del JWT ──"
  decode_claims "$TOK" | grep -E '"(app_user_id|parroquia_id|es_proveedor|roles|permisos|app_metadata|email)"' | head -20
  echo "── GET /rest/v1/confirmandos ──"
  query_confirmandos "$TOK" | python -c "import sys,json; d=json.load(sys.stdin); [print('  ',x['nombres'],x['apellidos'],'| parroquia',x['parroquia_id'],'| grupo',x['grupo_id']) for x in d]; print('   TOTAL:',len(d))"
  echo
done

echo "══════════ anon (sin login) ══════════"
query_confirmandos "$ANON" | python -c "import sys,json; d=json.load(sys.stdin); print('   TOTAL:',len(d) if isinstance(d,list) else d)"
