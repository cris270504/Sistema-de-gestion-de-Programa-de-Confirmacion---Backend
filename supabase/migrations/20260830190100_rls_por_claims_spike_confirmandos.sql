-- Fase 0 · SPIKE: RLS que lee el JWT (claims del hook) en vez de las variables
-- de sesión app.current_*. Alcance del spike: solo la tabla `confirmandos`.
-- La reescritura completa de TODAS las políticas es la Fase 2 del plan.

-- ─────────────────────────────────────────────────────────────────────────────
-- Helpers: leen auth.jwt() (claims inyectados por custom_access_token_hook).
-- Sin JWT (anon) auth.jwt() = '{}' → todo NULL/false/vacío → no ve nada.
-- ─────────────────────────────────────────────────────────────────────────────
create schema if not exists app;
grant usage on schema app to anon, authenticated, service_role;

create or replace function app.jwt_parroquia_id() returns bigint
    language sql stable as $$
    select nullif(auth.jwt() ->> 'parroquia_id', '')::bigint
$$;

create or replace function app.jwt_app_user_id() returns bigint
    language sql stable as $$
    select nullif(auth.jwt() ->> 'app_user_id', '')::bigint
$$;

create or replace function app.jwt_es_proveedor() returns boolean
    language sql stable as $$
    select coalesce((auth.jwt() ->> 'es_proveedor')::boolean, false)
$$;

create or replace function app.jwt_roles() returns text[]
    language sql stable as $$
    select coalesce(
        array(select jsonb_array_elements_text(auth.jwt() -> 'roles')),
        '{}'::text[]
    )
$$;

-- coordinador / super-admin / proveedor ven toda su parroquia (sin filtro por grupo)
create or replace function app.jwt_is_privileged() returns boolean
    language sql stable as $$
    select app.jwt_es_proveedor()
        or (app.jwt_roles() && array['coordinador', 'super-admin']::text[])
$$;

-- grupos del catequista actual (resuelto desde el claim app_user_id)
create or replace function app.jwt_grupo_ids() returns setof bigint
    language sql stable as $$
    select cg.grupo_id
      from public.catequista_grupo cg
     where cg.user_id = app.jwt_app_user_id()
$$;

grant execute on function
    app.jwt_parroquia_id(), app.jwt_app_user_id(), app.jwt_es_proveedor(),
    app.jwt_roles(), app.jwt_is_privileged(), app.jwt_grupo_ids()
to anon, authenticated, service_role;

-- ─────────────────────────────────────────────────────────────────────────────
-- catequista_grupo: la RLS actual usa app_current_user_id() (variable de sesión
-- que ya no fijamos). Se reescribe a claims para que la subconsulta de
-- app.jwt_grupo_ids() vea las filas del catequista.
-- ─────────────────────────────────────────────────────────────────────────────
drop policy if exists catequista_grupo_select on public.catequista_grupo;
drop policy if exists catequista_grupo_insert on public.catequista_grupo;
drop policy if exists catequista_grupo_update on public.catequista_grupo;
drop policy if exists catequista_grupo_delete on public.catequista_grupo;

create policy catequista_grupo_select on public.catequista_grupo
    for select using (app.jwt_is_privileged() or user_id = app.jwt_app_user_id());
create policy catequista_grupo_insert on public.catequista_grupo
    for insert with check (app.jwt_is_privileged());
create policy catequista_grupo_update on public.catequista_grupo
    for update using (app.jwt_is_privileged()) with check (app.jwt_is_privileged());
create policy catequista_grupo_delete on public.catequista_grupo
    for delete using (app.jwt_is_privileged());

-- ─────────────────────────────────────────────────────────────────────────────
-- confirmandos: se reemplazan las políticas por versiones que leen el JWT.
-- ─────────────────────────────────────────────────────────────────────────────
drop policy if exists confirmandos_select     on public.confirmandos;
drop policy if exists confirmandos_insert     on public.confirmandos;
drop policy if exists confirmandos_update     on public.confirmandos;
drop policy if exists confirmandos_delete     on public.confirmandos;
drop policy if exists confirmandos_parroquia  on public.confirmandos;

-- Aislamiento por parroquia (RESTRICTIVE: AND con lo de abajo).
-- El proveedor no queda acotado (soporte multi-parroquia).
create policy confirmandos_parroquia on public.confirmandos
    as restrictive for all
    using      (app.jwt_es_proveedor() or parroquia_id = app.jwt_parroquia_id())
    with check (app.jwt_es_proveedor() or parroquia_id = app.jwt_parroquia_id());

-- Alcance por grupo para el catequista; privilegiados ven todo.
create policy confirmandos_select on public.confirmandos
    for select
    using (app.jwt_is_privileged() or grupo_id in (select app.jwt_grupo_ids()));

create policy confirmandos_insert on public.confirmandos
    for insert
    with check (app.jwt_is_privileged());

create policy confirmandos_update on public.confirmandos
    for update
    using      (app.jwt_is_privileged())
    with check (app.jwt_is_privileged());

create policy confirmandos_delete on public.confirmandos
    for delete
    using (app.jwt_is_privileged());

-- PostgREST necesita el GRANT de tabla además de la RLS.
grant select, insert, update, delete on public.confirmandos to authenticated;
