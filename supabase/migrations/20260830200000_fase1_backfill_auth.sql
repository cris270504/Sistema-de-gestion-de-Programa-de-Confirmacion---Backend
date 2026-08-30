-- Fase 1 del plan de migración a Supabase — AUTH.
--
-- Backfill: por cada fila de public.users sin auth_id, crea su identidad en
-- Supabase Auth (auth.users + auth.identities) reutilizando el hash bcrypt de
-- Laravel, y enlaza public.users.auth_id.
--
-- Idempotente: solo procesa filas con auth_id IS NULL. Se corre en local ahora
-- y en producción durante el cutover (Fase 6), después de un backup.
--
-- Contraseñas: Laravel guarda bcrypt con prefijo `$2y$`; la librería bcrypt de Go
-- (GoTrue) espera `$2a$`/`$2b$`. Los tres prefijos son el MISMO algoritmo — solo
-- cambia la etiqueta — así que reescribir el prefijo es seguro y preserva la
-- contraseña del usuario (entra con la misma que ya tenía).
--
-- Email: si la fila no tiene email (no debería pasar hoy), se usa uno sintético
-- determinista a partir del DNI. El identificador que el usuario teclea (correo o
-- DNI) lo resuelve la Edge Function `resolver-login` a este email canónico.

create or replace function public.fase1_backfill_auth_users()
returns table (procesados int, ya_existian int)
language plpgsql
security definer
set search_path = public, auth, pg_temp
as $$
declare
    _row        record;
    _auth_id    uuid;
    _email      text;
    _pwd        text;
    _procesados int := 0;
    _saltados   int := 0;
begin
    for _row in
        select id, email, dni, password
          from public.users
         where auth_id is null
         order by id
    loop
        _email := lower(coalesce(
            nullif(trim(_row.email), ''),
            'dni-' || _row.dni || '@no-email.sistemaconfirmacion.local'
        ));

        -- Si ya hay un auth.users con ese email (re-corrida parcial), solo enlazamos.
        select id into _auth_id from auth.users where lower(email) = _email;

        if _auth_id is not null then
            update public.users set auth_id = _auth_id where id = _row.id;
            _saltados := _saltados + 1;
            continue;
        end if;

        _auth_id := gen_random_uuid();
        _pwd := replace(replace(_row.password, '$2y$', '$2a$'), '$2b$', '$2a$');

        insert into auth.users (
            id, instance_id, aud, role, email, encrypted_password,
            email_confirmed_at, created_at, updated_at,
            raw_app_meta_data, raw_user_meta_data,
            is_sso_user, is_anonymous,
            -- GoTrue escanea estas columnas como string no-nullable: deben ser '' (no NULL)
            confirmation_token, recovery_token, email_change_token_new,
            email_change, email_change_token_current, phone_change,
            phone_change_token, reauthentication_token
        ) values (
            _auth_id, '00000000-0000-0000-0000-000000000000', 'authenticated',
            'authenticated', _email, _pwd,
            now(), now(), now(),
            jsonb_build_object('provider', 'email', 'providers', jsonb_build_array('email')),
            '{}'::jsonb,
            false, false,
            '', '', '', '', '', '', '', ''
        );

        insert into auth.identities (
            id, user_id, provider_id, provider, identity_data,
            last_sign_in_at, created_at, updated_at
        ) values (
            gen_random_uuid(), _auth_id, _auth_id::text, 'email',
            jsonb_build_object('sub', _auth_id::text, 'email', _email, 'email_verified', true),
            now(), now(), now()
        );

        update public.users set auth_id = _auth_id where id = _row.id;
        _procesados := _procesados + 1;
    end loop;

    return query select _procesados, _saltados;
end;
$$;

comment on function public.fase1_backfill_auth_users() is
    'Fase 1: crea auth.users + auth.identities por cada public.users sin auth_id (reusa el hash bcrypt de Laravel). Idempotente.';

revoke execute on function public.fase1_backfill_auth_users() from anon, authenticated, public;
