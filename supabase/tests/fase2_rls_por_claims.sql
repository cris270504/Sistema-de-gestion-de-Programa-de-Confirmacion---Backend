-- Verificación de la RLS por claims (Fase 2). Ejecutar contra el stack local:
--   docker exec -i -e PGPASSWORD=postgres supabase_db_<proj> psql -U postgres -d postgres < este_archivo
--
-- Simula lo que hace PostgREST: SET ROLE + request.jwt.claims. (El rol `postgres`
-- local es superuser y saltea RLS, así que hay que bajar a authenticated/anon.)
--
-- Requiere los datos del spike (supabase/spike/01_seed.sh): parroquia 1 con grupo
-- SPIKE G1A y confirmando "SPIKE Ana"; parroquia 2 (id 3) con "SPIKE Dani";
-- usuarios cat1@spike.test (catequista, id 3) y admin2@spike.test (super-admin).

\set ON_ERROR_STOP on
\echo '── catequista cat1: solo su grupo y su parroquia ──'
set role authenticated;
select set_config('request.jwt.claims',
  '{"app_user_id":"3","parroquia_id":"1","roles":["catequista"],"es_proveedor":false}', false);
do $$ begin
  assert (select count(*) from public.confirmandos) = 1, 'catequista deberia ver 1 confirmando';
  assert (select count(*) from public.grupos) = 1, 'catequista deberia ver 1 grupo';
  assert (select count(*) from public.users) = 1, 'catequista deberia verse solo a si mismo';
  assert (select count(*) from public.parroquias) = 1, 'catequista deberia ver 1 parroquia';
end $$;
reset role;

\echo '── super-admin parroquia 3: aislado de la 1 ──'
set role authenticated;
select set_config('request.jwt.claims',
  '{"app_user_id":"4","parroquia_id":"3","roles":["super-admin"],"es_proveedor":false}', false);
do $$ begin
  assert (select count(*) from public.confirmandos) = 1, 'super-admin P3 deberia ver 1';
  assert (select count(*) from public.parroquias) = 1, 'super-admin P3 ve solo su parroquia';
  assert not exists (select 1 from public.confirmandos where parroquia_id <> 3), 'fuga cross-parroquia';
end $$;
reset role;

\echo '── proveedor global: ve todo ──'
set role authenticated;
select set_config('request.jwt.claims',
  '{"app_user_id":"1","roles":["proveedor"],"es_proveedor":true}', false);
do $$ begin
  assert (select count(*) from public.parroquias) >= 2, 'proveedor ve todas las parroquias';
  assert (select count(distinct parroquia_id) from public.confirmandos) >= 2, 'proveedor ve confirmandos de varias parroquias';
end $$;
reset role;

\echo '── anon: nada de dominio, sin acceso a infra ──'
set role anon;
select set_config('request.jwt.claims', '{}', false);
do $$ begin
  assert (select count(*) from public.confirmandos) = 0, 'anon no ve confirmandos';
  assert (select count(*) from public.parroquias) = 0, 'anon no ve parroquias';
end $$;
do $$ begin
  perform 1 from public.roles limit 1;
  raise exception 'anon NO deberia poder leer public.roles';
exception when insufficient_privilege then null;
end $$;
reset role;

\echo 'OK — RLS por claims (Fase 2) verde.'
