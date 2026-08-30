#!/usr/bin/env bash
set -euo pipefail
API="http://127.0.0.1:54321"
ANON="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0"
login() { curl -s -X POST "$API/auth/v1/token?grant_type=password" -H "apikey: $ANON" -H "Content-Type: application/json" -d "{\"email\":\"$1\",\"password\":\"password123\"}" | python -c "import sys,json;print(json.load(sys.stdin)['access_token'])"; }
A1=$(login admin1@spike.test); C1=$(login cat1@spike.test); A2=$(login admin2@spike.test)

show() { printf '%-70s -> HTTP %s  %s\n' "$1" "$2" "$3"; }

# 1. catequista intenta CREAR confirmando (no privilegiado) -> 401/403
r=$(curl -s -o /tmp/b -w '%{http_code}' -X POST "$API/rest/v1/confirmandos" -H "apikey: $ANON" -H "Authorization: Bearer $C1" -H "Content-Type: application/json" -H "Prefer: return=representation" -d '{"nombres":"SPIKE Hack","apellidos":"x","estado":"en_preparacion","parroquia_id":1}')
show "cat1 POST confirmando (espera fallo RLS)" "$r" "$(head -c120 /tmp/b)"

# 2. admin1 (P1) intenta LEER confirmandos de parroquia 3 explícitamente -> 0 filas
r=$(curl -s "$API/rest/v1/confirmandos?select=nombres&parroquia_id=eq.3" -H "apikey: $ANON" -H "Authorization: Bearer $A1")
show "admin1 GET confirmandos?parroquia_id=eq.3 (espera [])" "200" "$r"

# 3. admin1 (P1) intenta CREAR confirmando en parroquia 3 -> falla WITH CHECK
r=$(curl -s -o /tmp/b -w '%{http_code}' -X POST "$API/rest/v1/confirmandos" -H "apikey: $ANON" -H "Authorization: Bearer $A1" -H "Content-Type: application/json" -d '{"nombres":"SPIKE Cross","apellidos":"x","estado":"en_preparacion","parroquia_id":3}')
show "admin1 POST confirmando parroquia_id=3 (espera fallo WITH CHECK)" "$r" "$(head -c120 /tmp/b)"

# 4. admin1 (P1) CREAR confirmando en su propia parroquia -> OK
r=$(curl -s -o /tmp/b -w '%{http_code}' -X POST "$API/rest/v1/confirmandos" -H "apikey: $ANON" -H "Authorization: Bearer $A1" -H "Content-Type: application/json" -H "Prefer: return=representation" -d '{"nombres":"SPIKE Legit","apellidos":"x","estado":"en_preparacion","parroquia_id":1}')
show "admin1 POST confirmando parroquia_id=1 (espera 201)" "$r" "$(head -c120 /tmp/b)"
curl -s -X DELETE "$API/rest/v1/confirmandos?nombres=eq.SPIKE%20Legit" -H "apikey: $ANON" -H "Authorization: Bearer $A1" >/dev/null || true
