<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stress test ronda 2 — R2-2, R2-13.
 *
 * R2-2: `fn_importar_confirmandos` insertaba en bloque, sin dedup → re-subir el
 *       mismo archivo duplicaba a todos. Ahora va fila por fila: salta y REPORTA
 *       los que ya existen (mismo nombre+apellido en la parroquia) y los que
 *       quedaron sin apellido tras separar el nombre. Devuelve
 *       { importados, omitidos: [{nombre, motivo}] }.
 *       (La celda `celular` malformada ya la sanea la Edge Function antes de llegar.)
 *
 * R2-13: CHECK que impide `nombres`/`apellidos` en blanco por cualquier vía.
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
        ALTER TABLE public.confirmandos
            DROP CONSTRAINT IF EXISTS confirmandos_nombres_no_vacios;
        ALTER TABLE public.confirmandos
            ADD CONSTRAINT confirmandos_nombres_no_vacios
            CHECK (btrim(nombres) <> '' AND btrim(apellidos) <> '');

        CREATE OR REPLACE FUNCTION public.fn_importar_confirmandos(p_actor_auth uuid, p_filas jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql
        SECURITY DEFINER SET search_path = public
        AS $fn$
        DECLARE
            _pid   bigint;
            _perm  text[];
            _n     int := 0;
            _omit  jsonb := '[]'::jsonb;
            _r     record;
            _cel   text;
        BEGIN
            SELECT parroquia_id INTO _pid FROM public.users WHERE auth_id = p_actor_auth AND activo;
            IF _pid IS NULL THEN
                RAISE EXCEPTION 'Sin parroquia en el contexto' USING ERRCODE = 'insufficient_privilege';
            END IF;

            SELECT public.app_actor_permisos(p_actor_auth) INTO _perm;
            IF NOT ('crear confirmandos' = ANY(_perm)) THEN
                RAISE EXCEPTION 'No autorizado para importar confirmandos' USING ERRCODE = 'insufficient_privilege';
            END IF;

            -- Import parcial (solo 3 columnas): el trigger de obligatorios se salta.
            PERFORM set_config('app.importing', 'on', true);

            FOR _r IN
                SELECT btrim(f.nombres)   AS nom,
                       btrim(f.apellidos) AS ape,
                       nullif(f.celular, '') AS cel
                  FROM jsonb_to_recordset(p_filas) AS f(nombres text, apellidos text, celular text)
            LOOP
                IF _r.nom = '' AND _r.ape = '' THEN
                    CONTINUE;  -- fila vacía: ni se reporta
                END IF;
                IF _r.nom = '' OR _r.ape = '' THEN
                    _omit := _omit || jsonb_build_object(
                        'nombre', trim(_r.ape || ' ' || _r.nom),
                        'motivo', 'nombre incompleto (falta nombre o apellido)');
                    CONTINUE;
                END IF;

                IF EXISTS (
                    SELECT 1 FROM public.confirmandos c
                     WHERE c.parroquia_id = _pid
                       AND lower(btrim(c.nombres))   = lower(_r.nom)
                       AND lower(btrim(c.apellidos)) = lower(_r.ape)
                ) THEN
                    _omit := _omit || jsonb_build_object(
                        'nombre', _r.ape || ' ' || _r.nom,
                        'motivo', 'ya existe en el padrón');
                    CONTINUE;
                END IF;

                -- deja solo dígitos; se guarda solo si quedan exactamente 9.
                _cel := regexp_replace(coalesce(_r.cel, ''), '\D', '', 'g');
                _cel := CASE WHEN _cel ~ '^[0-9]{9}$' THEN _cel ELSE NULL END;

                BEGIN
                    INSERT INTO public.confirmandos
                        (nombres, apellidos, celular, fecha_nacimiento, estado, parroquia_id, created_at, updated_at)
                    VALUES (left(_r.nom, 255), left(_r.ape, 255), _cel, NULL, 'en_preparacion', _pid, now(), now());
                    _n := _n + 1;
                EXCEPTION WHEN others THEN
                    _omit := _omit || jsonb_build_object(
                        'nombre', _r.ape || ' ' || _r.nom,
                        'motivo', 'no se pudo guardar');
                END;
            END LOOP;

            PERFORM set_config('app.importing', 'off', true);
            RETURN jsonb_build_object('importados', _n, 'omitidos', _omit);
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
        DB::unprepared(<<<'SQL'
        ALTER TABLE public.confirmandos DROP CONSTRAINT IF EXISTS confirmandos_nombres_no_vacios;
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
