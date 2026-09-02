<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stress test M10.
 *
 * `fn_importar_confirmandos` fija `parroquia_id` en el INSERT → el trigger
 * `confirmando_valida_obligatorios` se disparaba y, si la parroquia exigía
 * fecha de nacimiento o género (que el Excel no trae), FALLABA todo el import.
 *
 * El import es intrínsecamente parcial (solo nombres/apellidos/celular): se
 * marca un flag de sesión `app.importing` y el trigger salta la validación de
 * obligatorios durante el import. Los datos que falten se completan luego
 * editando el confirmando (ahí el trigger sí exige).
 *
 * Solo pgsql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.confirmando_valida_obligatorios()
        RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE
            _oblig text[];
            _pid   bigint := coalesce(NEW.parroquia_id, public.app_current_parroquia_id());
        BEGIN
            -- Import masivo (fn_importar_confirmandos marca el flag): parcial a propósito.
            IF coalesce(current_setting('app.importing', true), '') = 'on' THEN
                RETURN NEW;
            END IF;
            IF _pid IS NULL THEN
                RETURN NEW;
            END IF;

            SELECT coalesce(array(SELECT jsonb_array_elements_text(ui->'confirmando_obligatorios')), '{}')
              INTO _oblig
              FROM public.parroquia_configuraciones
             WHERE parroquia_id = _pid;

            IF _oblig IS NULL OR array_length(_oblig, 1) IS NULL THEN
                RETURN NEW;
            END IF;

            IF 'celular' = ANY(_oblig)
               AND coalesce(NEW.celular, '') = ''
               AND (TG_OP = 'INSERT' OR NEW.celular IS DISTINCT FROM OLD.celular) THEN
                RAISE EXCEPTION 'El celular es obligatorio para esta parroquia' USING ERRCODE = 'not_null_violation';
            END IF;
            IF 'fecha_nacimiento' = ANY(_oblig)
               AND NEW.fecha_nacimiento IS NULL
               AND (TG_OP = 'INSERT' OR NEW.fecha_nacimiento IS DISTINCT FROM OLD.fecha_nacimiento) THEN
                RAISE EXCEPTION 'La fecha de nacimiento es obligatoria para esta parroquia' USING ERRCODE = 'not_null_violation';
            END IF;
            IF 'genero' = ANY(_oblig)
               AND coalesce(NEW.genero, '') = ''
               AND (TG_OP = 'INSERT' OR NEW.genero IS DISTINCT FROM OLD.genero) THEN
                RAISE EXCEPTION 'El género es obligatorio para esta parroquia' USING ERRCODE = 'not_null_violation';
            END IF;

            RETURN NEW;
        END;
        $fn$;

        CREATE OR REPLACE FUNCTION public.fn_importar_confirmandos(p_actor_auth uuid, p_filas jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY DEFINER SET search_path = public
        AS $fn$
        DECLARE
            _pid  bigint;
            _perm text[];
            _n    int;
        BEGIN
            SELECT parroquia_id INTO _pid FROM public.users WHERE auth_id = p_actor_auth AND activo;
            IF _pid IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT public.app_actor_permisos(p_actor_auth) INTO _perm;
            IF NOT ('crear confirmandos' = ANY(_perm)) THEN
                RAISE EXCEPTION 'No autorizado para importar confirmandos' USING ERRCODE = 'insufficient_privilege';
            END IF;

            -- El import es parcial (solo 3 columnas): los obligatorios se completan luego.
            PERFORM set_config('app.importing', 'on', true);

            INSERT INTO public.confirmandos
                (nombres, apellidos, celular, fecha_nacimiento, estado, parroquia_id, created_at, updated_at)
            SELECT left(f.nombres, 255), left(f.apellidos, 255),
                   nullif(f.celular, ''), NULL, 'en_preparacion', _pid, now(), now()
              FROM jsonb_to_recordset(p_filas) AS f(nombres text, apellidos text, celular text)
             WHERE coalesce(btrim(f.nombres), '') <> '' OR coalesce(btrim(f.apellidos), '') <> '';

            GET DIAGNOSTICS _n = ROW_COUNT;

            PERFORM set_config('app.importing', 'off', true);
            RETURN jsonb_build_object('importados', _n);
        END;
        $fn$;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared("NOTIFY pgrst, 'reload schema';");
    }
};
