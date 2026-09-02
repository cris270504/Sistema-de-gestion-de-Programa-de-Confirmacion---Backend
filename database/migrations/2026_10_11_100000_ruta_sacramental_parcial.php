<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `fn_asignar_ruta_sacramental` robusta ante parroquias que gestionan un
 * subconjunto de {Bautismo, Primera Comunión, Confirmación} — stress test A2/B4.
 *
 * Antes: buscaba las 3 claves y hacía `RETURN` mudo si faltaba alguna → con la
 * feature de "elegir sacramentos", una parroquia que solo gestiona Confirmación
 * no podía asignar ruta y el confirmando quedaba sin `confirmando_sacramento`
 * ni checklist, sin ningún aviso.
 *
 * Ahora: opera sobre los sacramentos canónicos que la parroquia REALMENTE tiene,
 * en el orden de la ruta; `RAISE` explícito si no hay ninguno o si el faltante no
 * pertenece a la ruta de la parroquia. Solo se apoya en `clave` (estable), no en
 * el nombre (editable).
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
        CREATE OR REPLACE FUNCTION public.fn_asignar_ruta_sacramental(
            p_confirmando_id bigint,
            p_sacramento_faltante_id bigint
        ) RETURNS void
        LANGUAGE plpgsql
        AS $fn$
        DECLARE
            _clave_falta text;
            _ord_falta   int;
            _sac_ids     bigint[];   -- todos los sacramentos de la ruta de la parroquia
            _pend_ids    bigint[];   -- el faltante y los posteriores → 'pendiente'
            _req_ids     bigint[];
        BEGIN
            IF p_sacramento_faltante_id IS NULL THEN
                RETURN;
            END IF;

            -- Sacramentos canónicos que ESTA parroquia gestiona (RLS ⇒ su parroquia).
            SELECT array_agg(s.id) INTO _sac_ids
              FROM public.sacramentos s
             WHERE s.clave IN ('bautismo', 'comunion', 'confirmacion');

            IF _sac_ids IS NULL THEN
                RAISE EXCEPTION 'La parroquia no tiene configurada una ruta sacramental (Bautismo / Primera Comunión / Confirmación)'
                    USING ERRCODE = 'no_data_found';
            END IF;

            SELECT s.clave INTO _clave_falta
              FROM public.sacramentos s
             WHERE s.id = p_sacramento_faltante_id
               AND s.clave IN ('bautismo', 'comunion', 'confirmacion');

            IF _clave_falta IS NULL THEN
                RAISE EXCEPTION 'El sacramento indicado no pertenece a la ruta sacramental de esta parroquia'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            _ord_falta := CASE _clave_falta
                            WHEN 'bautismo' THEN 1 WHEN 'comunion' THEN 2 WHEN 'confirmacion' THEN 3 END;

            SELECT array_agg(s.id) INTO _pend_ids
              FROM public.sacramentos s
             WHERE s.clave IN ('bautismo', 'comunion', 'confirmacion')
               AND (CASE s.clave WHEN 'bautismo' THEN 1 WHEN 'comunion' THEN 2 WHEN 'confirmacion' THEN 3 END)
                   >= _ord_falta;

            -- Sacramentos de la ruta: 'recibido' los previos, 'pendiente' desde el faltante.
            INSERT INTO public.confirmando_sacramento (confirmando_id, sacramento_id, estado)
            SELECT p_confirmando_id, sid,
                   CASE WHEN sid = ANY(_pend_ids) THEN 'pendiente' ELSE 'recibido' END
              FROM unnest(_sac_ids) sid
            ON CONFLICT (confirmando_id, sacramento_id) DO UPDATE SET estado = EXCLUDED.estado;

            -- Fuera de la ruta (config cambió, o venía de otra parroquia).
            DELETE FROM public.confirmando_sacramento
             WHERE confirmando_id = p_confirmando_id
               AND sacramento_id <> ALL(_sac_ids);

            -- Requisitos acumulados de los sacramentos pendientes.
            SELECT coalesce(array_agg(DISTINCT sr.requisito_id), '{}') INTO _req_ids
              FROM public.sacramento_requisito sr
             WHERE sr.sacramento_id = ANY(_pend_ids);

            INSERT INTO public.confirmando_requisito (confirmando_id, requisito_id, estado)
            SELECT p_confirmando_id, r, 'pendiente'
              FROM unnest(_req_ids) r
            ON CONFLICT (confirmando_id, requisito_id) DO NOTHING;

            DELETE FROM public.confirmando_requisito
             WHERE confirmando_id = p_confirmando_id
               AND requisito_id <> ALL(_req_ids);
        END;
        $fn$;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        // La versión anterior queda en 2026_09_15_100000; no la restauramos porque
        // esta es estrictamente más correcta.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared("NOTIFY pgrst, 'reload schema';");
    }
};
