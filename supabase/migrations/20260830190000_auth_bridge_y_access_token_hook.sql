-- Fase 0 del plan de migración a Supabase (docs/PLAN-MIGRACION-SUPABASE.md).
--
-- Puente entre auth.users (Supabase) y public.users (app, PK bigint) + el
-- Custom Access Token Hook que mete parroquia_id / roles / permisos en el JWT.
-- Objetivo: reemplazar ResolveTenant + SetPostgresRlsContext + ParroquiaScope
-- por claims firmados que la RLS lee con auth.jwt().

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Puente de identidad: public.users.auth_id → auth.users.id
--    (decisión §6.1: se conserva la PK bigint, no se toca ninguna FK)
-- ─────────────────────────────────────────────────────────────────────────────
alter table public.users
    add column if not exists auth_id uuid unique
        references auth.users (id) on delete set null;

comment on column public.users.auth_id is
    'FK a auth.users. La app sigue usando users.id (bigint); auth_id enlaza la identidad de Supabase Auth.';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Custom Access Token Hook
--    Contrato Supabase: recibe {user_id, claims, ...}, devuelve el event con
--    claims modificados. Se registra en config.toml [auth.hook.custom_access_token].
-- ─────────────────────────────────────────────────────────────────────────────
create or replace function public.custom_access_token_hook(event jsonb)
returns jsonb
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
    _auth_uid   uuid    := (event ->> 'user_id')::uuid;
    _uid        bigint;
    _parroquia  bigint;
    _roles      text[];
    _permisos   text[];
    _proveedor  boolean;
    _claims     jsonb   := coalesce(event -> 'claims', '{}'::jsonb);
begin
    select u.id, u.parroquia_id
      into _uid, _parroquia
      from public.users u
     where u.auth_id = _auth_uid;

    -- Sin fila en public.users (backfill pendiente / usuario ajeno): no tocamos nada.
    if _uid is null then
        return event;
    end if;

    select coalesce(array_agg(distinct r.name), '{}')
      into _roles
      from public.model_has_roles mhr
      join public.roles r on r.id = mhr.role_id
     where mhr.model_type = 'App\Models\User'
       and mhr.model_id   = _uid
       and r.guard_name   = 'api';

    _proveedor := 'proveedor' = any(_roles);

    select coalesce(array_agg(distinct p.name), '{}')
      into _permisos
      from public.permissions p
     where p.guard_name = 'api'
       and (
            -- permisos vía rol
            p.id in (
                select rhp.permission_id
                  from public.role_has_permissions rhp
                  join public.model_has_roles mhr on mhr.role_id = rhp.role_id
                 where mhr.model_type = 'App\Models\User' and mhr.model_id = _uid
            )
            -- permisos directos
            or p.id in (
                select mhp.permission_id
                  from public.model_has_permissions mhp
                 where mhp.model_type = 'App\Models\User' and mhp.model_id = _uid
            )
       );

    _claims := _claims
        || jsonb_build_object(
            'app_user_id',  _uid,
            'parroquia_id', _parroquia,
            'es_proveedor', _proveedor,
            'roles',        to_jsonb(_roles),
            'permisos',     to_jsonb(_permisos)
        );

    -- también en app_metadata (algunas libs lo leen de ahí)
    _claims := jsonb_set(
        _claims,
        '{app_metadata}',
        coalesce(_claims -> 'app_metadata', '{}'::jsonb)
            || jsonb_build_object(
                'parroquia_id', _parroquia,
                'es_proveedor', _proveedor,
                'roles',        to_jsonb(_roles)
            )
    );

    return jsonb_set(event, '{claims}', _claims);
end;
$$;

comment on function public.custom_access_token_hook(jsonb) is
    'Auth hook: inyecta app_user_id / parroquia_id / es_proveedor / roles / permisos en el JWT (guard api de Spatie).';

-- Permisos: solo el rol interno de GoTrue puede ejecutarlo.
grant execute on function public.custom_access_token_hook(jsonb) to supabase_auth_admin;
revoke execute on function public.custom_access_token_hook(jsonb) from authenticated, anon, public;

-- El hook corre como supabase_auth_admin; con security definer se ejecuta como
-- owner (postgres), pero igual le damos lectura explícita por si se quita el
-- security definer más adelante.
grant usage on schema public to supabase_auth_admin;
grant select on
    public.users, public.roles, public.permissions,
    public.model_has_roles, public.model_has_permissions, public.role_has_permissions
to supabase_auth_admin;
