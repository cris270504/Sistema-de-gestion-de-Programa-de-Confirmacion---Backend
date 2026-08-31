<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leftover de Fase 3: la matriz persona × reunión (AsistenciaController::matrix)
 * como RPC en vez de PostgREST, porque:
 *  - es polimórfica (tipo = Confirmandos | Catequistas | Apoderados), y
 *  - la variante Catequistas necesita los roles de Spatie, cuyas tablas tienen
 *    REVOKE de `authenticated` (Fase 2). Se resuelve con un helper SECURITY
 *    DEFINER acotado por parroquia.
 *
 * fn_asistencia_matriz(p_tipo, p_fecha 'YYYY-MM'|NULL) → { reuniones, personas }.
 * SECURITY INVOKER: la RLS de confirmandos/apoderados/asistencia acota por
 * parroquia y por grupo del catequista igual que el `whereIn(grupo_id)` de Laravel.
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
        -- Helper: user_ids de la parroquia actual con alguno de los roles dados.
        -- SECURITY DEFINER para leer model_has_roles/roles (REVOCADAS a authenticated).
        CREATE OR REPLACE FUNCTION public.app_user_ids_por_rol(p_roles text[])
        RETURNS SETOF bigint
        LANGUAGE sql
        STABLE
        SECURITY DEFINER
        SET search_path = public
        AS $$
            SELECT u.id
              FROM public.users u
              JOIN public.model_has_roles mhr
                ON mhr.model_id = u.id
               AND mhr.model_type = 'App\Models\User'
              JOIN public.roles r ON r.id = mhr.role_id
             WHERE r.name = ANY(p_roles)
               AND (public.app_current_parroquia_id() IS NULL
                    OR u.parroquia_id = public.app_current_parroquia_id())
        $$;
        REVOKE ALL ON FUNCTION public.app_user_ids_por_rol(text[]) FROM public;
        GRANT EXECUTE ON FUNCTION public.app_user_ids_por_rol(text[]) TO authenticated;

        -- roles (Spatie, REVOCADAS) + grupos de un usuario, para la matriz de
        -- catequistas. SECURITY DEFINER; sin gate propio — solo expone nombres de
        -- rol y de grupo, y quien la llama (fn_asistencia_matriz) ya está acotado.
        CREATE OR REPLACE FUNCTION public.app_user_roles_grupos(p_user_id bigint)
        RETURNS jsonb
        LANGUAGE sql
        STABLE
        SECURITY DEFINER
        SET search_path = public
        AS $$
            SELECT jsonb_build_object(
                'roles', (SELECT coalesce(jsonb_agg(jsonb_build_object('name', r.name)), '[]'::jsonb)
                            FROM public.model_has_roles mhr
                            JOIN public.roles r ON r.id = mhr.role_id
                           WHERE mhr.model_id = p_user_id AND mhr.model_type = 'App\Models\User'),
                'grupos', (SELECT coalesce(jsonb_agg(jsonb_build_object('id', g.id, 'nombre', g.nombre)), '[]'::jsonb)
                             FROM public.catequista_grupo cg
                             JOIN public.grupos g ON g.id = cg.grupo_id
                            WHERE cg.user_id = p_user_id)
            )
        $$;
        REVOKE ALL ON FUNCTION public.app_user_roles_grupos(bigint) FROM public;
        GRANT EXECUTE ON FUNCTION public.app_user_roles_grupos(bigint) TO authenticated;

        -- Asistencias de una persona (polimórfica) acotadas a un set de reuniones.
        CREATE OR REPLACE FUNCTION public.app_asistencias_persona(
            p_type text, p_id bigint, p_reunion_ids bigint[]
        ) RETURNS jsonb
        LANGUAGE sql
        STABLE
        SECURITY INVOKER
        AS $$
            SELECT coalesce(jsonb_agg(jsonb_build_object(
                       'reunion_id', a.reunion_id, 'estado', a.estado, 'nota', a.nota)), '[]'::jsonb)
              FROM public.asistencia a
             WHERE a.asistente_type = p_type
               AND a.asistente_id = p_id
               AND a.reunion_id = ANY(p_reunion_ids)
        $$;
        REVOKE ALL ON FUNCTION public.app_asistencias_persona(text, bigint, bigint[]) FROM public;
        GRANT EXECUTE ON FUNCTION public.app_asistencias_persona(text, bigint, bigint[]) TO authenticated;

        CREATE OR REPLACE FUNCTION public.fn_asistencia_matriz(
            p_tipo  text,
            p_fecha text DEFAULT NULL
        ) RETURNS jsonb
        LANGUAGE plpgsql
        STABLE
        SECURITY INVOKER
        AS $$
        DECLARE
            _reunion_ids bigint[];
            _reuniones   jsonb;
            _personas    jsonb;
        BEGIN
            SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY r.fecha), '[]'::jsonb),
                   coalesce(array_agg(r.id ORDER BY r.fecha), '{}')
              INTO _reuniones, _reunion_ids
              FROM (
                SELECT id, nombre_tema, fecha, tipo
                  FROM public.reunions
                 WHERE tipo = p_tipo
                   AND (p_fecha IS NULL OR to_char(fecha, 'YYYY-MM') = p_fecha)
              ) r;

            IF _reunion_ids = '{}' THEN
                RETURN jsonb_build_object('reuniones', '[]'::jsonb, 'personas', '[]'::jsonb);
            END IF;

            IF p_tipo = 'Confirmandos' THEN
                SELECT coalesce(jsonb_agg(p ORDER BY ap, nom), '[]'::jsonb) INTO _personas
                  FROM (
                    SELECT c.apellidos AS ap, c.nombres AS nom,
                           jsonb_build_object(
                               'id', c.id, 'nombres', c.nombres, 'apellidos', c.apellidos,
                               'estado', c.estado, 'grupo_id', c.grupo_id,
                               'grupo', CASE WHEN g.id IS NOT NULL
                                             THEN jsonb_build_object('id', g.id, 'nombre', g.nombre, 'color', g.color) END,
                               'asistencias', public.app_asistencias_persona('App\Models\Confirmando', c.id, _reunion_ids)
                           ) AS p
                      FROM public.confirmandos c
                      LEFT JOIN public.grupos g ON g.id = c.grupo_id
                     WHERE c.estado <> 'retirado'
                  ) x;

            ELSIF p_tipo = 'Catequistas' THEN
                SELECT coalesce(jsonb_agg(p ORDER BY nm), '[]'::jsonb) INTO _personas
                  FROM (
                    SELECT u.name AS nm,
                           jsonb_build_object(
                               'id', u.id, 'name', u.name, 'email', u.email, 'grupo_id', u.grupo_id,
                               'asistencias', public.app_asistencias_persona('App\Models\User', u.id, _reunion_ids)
                           ) || public.app_user_roles_grupos(u.id) AS p
                      FROM public.users u
                     WHERE u.id IN (SELECT public.app_user_ids_por_rol(ARRAY['catequista', 'coordinador']))
                  ) x;

            ELSIF p_tipo = 'Apoderados' THEN
                SELECT coalesce(jsonb_agg(p ORDER BY ap, nom), '[]'::jsonb) INTO _personas
                  FROM (
                    SELECT a.apellidos AS ap, a.nombres AS nom,
                           jsonb_build_object(
                               'id', a.id, 'nombres', a.nombres, 'apellidos', a.apellidos, 'celular', a.celular,
                               'confirmandos', (SELECT coalesce(jsonb_agg(jsonb_build_object(
                                                        'id', c.id, 'nombres', c.nombres, 'apellidos', c.apellidos,
                                                        'estado', c.estado, 'grupo_id', c.grupo_id,
                                                        'grupo', CASE WHEN g.id IS NOT NULL
                                                                      THEN jsonb_build_object('id', g.id, 'nombre', g.nombre) END)), '[]'::jsonb)
                                                  FROM public.confirmando_apoderado ca
                                                  JOIN public.confirmandos c ON c.id = ca.confirmando_id
                                                  LEFT JOIN public.grupos g ON g.id = c.grupo_id
                                                 WHERE ca.apoderado_id = a.id),
                               'asistencias', public.app_asistencias_persona('App\Models\Apoderado', a.id, _reunion_ids)
                           ) AS p
                      FROM public.apoderados a
                  ) x;
            ELSE
                RAISE EXCEPTION 'Tipo de matriz no válido: %', p_tipo USING ERRCODE = 'invalid_parameter_value';
            END IF;

            RETURN jsonb_build_object('reuniones', _reuniones, 'personas', coalesce(_personas, '[]'::jsonb));
        END;
        $$;

        REVOKE ALL ON FUNCTION public.fn_asistencia_matriz(text, text) FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_asistencia_matriz(text, text) TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.fn_asistencia_matriz(text, text);
            DROP FUNCTION IF EXISTS public.app_asistencias_persona(text, bigint, bigint[]);
            DROP FUNCTION IF EXISTS public.app_user_ids_por_rol(text[]);
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
