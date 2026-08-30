#!/usr/bin/env bash
set -euo pipefail
API="http://127.0.0.1:54321"
SVC="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.EGIM96RAZx35lJzdJsyH-qQwv8Hdp7fsn3W0YpN81IU"
PSQL() { docker exec -i -e PGPASSWORD=postgres supabase_db_sistemaConfirmacionApi psql -U postgres -d postgres -v ON_ERROR_STOP=1 -q "$@"; }

echo "── limpiar datos de spike previos ──"
PSQL <<'SQL'
delete from public.catequista_grupo where user_id in (select id from public.users where email like '%@spike.test');
delete from public.confirmandos where nombres like 'SPIKE %';
delete from public.model_has_roles where model_id in (select id from public.users where email like '%@spike.test') and model_type = 'App\Models\User';
delete from public.users where email like '%@spike.test';
delete from public.grupos where nombre like 'SPIKE %';
delete from public.parroquias where slug = 'spike-parroquia-2';
SQL

echo "── crear parroquia 2 + su config ──"
PSQL <<'SQL'
insert into public.parroquias (nombre, slug, activa, created_at, updated_at)
values ('SPIKE Parroquia Dos', 'spike-parroquia-2', true, now(), now());
SQL
P1=1
P2=$(PSQL -t -A -c "select id from public.parroquias where slug='spike-parroquia-2'")
echo "   P1=$P1  P2=$P2"

echo "── grupos ──"
PSQL <<SQL
insert into public.grupos (nombre, periodo, color, procedencia, parroquia_id, created_at, updated_at) values
 ('SPIKE G1A','2025-2026','#111111','sede',$P1,now(),now()),
 ('SPIKE G1B','2025-2026','#222222','sede',$P1,now(),now()),
 ('SPIKE G2A','2025-2026','#333333','sede',$P2,now(),now());
SQL
G1A=$(PSQL -t -A -c "select id from public.grupos where nombre='SPIKE G1A'")
G1B=$(PSQL -t -A -c "select id from public.grupos where nombre='SPIKE G1B'")
G2A=$(PSQL -t -A -c "select id from public.grupos where nombre='SPIKE G2A'")
echo "   G1A=$G1A G1B=$G1B G2A=$G2A"

echo "── confirmandos ──"
PSQL <<SQL
insert into public.confirmandos (nombres, apellidos, estado, grupo_id, parroquia_id, created_at, updated_at) values
 ('SPIKE Ana',  'G1A', 'en_preparacion', $G1A,  $P1, now(), now()),
 ('SPIKE Beto', 'G1B', 'en_preparacion', $G1B,  $P1, now(), now()),
 ('SPIKE Caro', 'sin', 'en_preparacion', null,  $P1, now(), now()),
 ('SPIKE Dani', 'G2A', 'en_preparacion', $G2A,  $P2, now(), now());
SQL

echo "── usuarios (auth.users via admin API + public.users) ──"
mkuser() {  # email role parroquia_id
  local email="$1" role="$2" pid="$3"
  local uid
  uid=$(curl -s -X POST "$API/auth/v1/admin/users" \
    -H "apikey: $SVC" -H "Authorization: Bearer $SVC" -H "Content-Type: application/json" \
    -d "{\"email\":\"$email\",\"password\":\"password123\",\"email_confirm\":true}" \
    | python -c "import sys,json;print(json.load(sys.stdin)['id'])")
  PSQL <<SQL
insert into public.users (name, email, password, dni, parroquia_id, activo, auth_id, created_at, updated_at)
values ('$email', '$email', 'x', null, $pid, true, '$uid', now(), now());
insert into public.model_has_roles (role_id, model_type, model_id)
select r.id, 'App\Models\User', u.id
from public.roles r, public.users u
where r.name = '$role' and r.guard_name='api' and u.email = '$email';
SQL
  echo "   $email -> auth_id=$uid role=$role pid=$pid"
}
mkuser "admin1@spike.test"  "super-admin" "$P1"
mkuser "cat1@spike.test"    "catequista"  "$P1"
mkuser "admin2@spike.test"  "super-admin" "$P2"

echo "── catequista cat1 asignado SOLO a G1A ──"
PSQL <<SQL
insert into public.catequista_grupo (user_id, grupo_id)
select u.id, $G1A from public.users u where u.email = 'cat1@spike.test';
SQL

echo "── LISTO. Resumen esperado al consultar /rest/v1/confirmandos:"
echo "   admin1 (super-admin P1) -> Ana, Beto, Caro   (3)"
echo "   cat1   (catequista  P1) -> Ana               (1, solo G1A)"
echo "   admin2 (super-admin P2) -> Dani              (1)"
echo "   anon                    -> 0"
