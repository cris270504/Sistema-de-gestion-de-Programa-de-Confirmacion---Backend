<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 — vista `v_justificaciones_pendientes` ← JustificacionController::index.
 *
 * Faltas de confirmandos (no retirados) que están abiertas: injustificada "pura"
 * dentro de la ventana de N días (config de la parroquia, default 21), o con
 * trámite (pendiente/justificado). Las `no_cumplido` quedan fuera.
 *
 * `security_invoker=true` → la RLS de `asistencia` (parroquia + grupo del
 * catequista) acota qué filas ve cada quien, igual que el `esPrivilegiado` /
 * `whereHasMorph` del controlador.
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
        CREATE OR REPLACE VIEW public.v_justificaciones_pendientes
            WITH (security_invoker = true) AS
        WITH cfg AS (
            SELECT coalesce(pc.dias_ventana_justificacion, 21) AS dias
            FROM (SELECT 1) _
            LEFT JOIN public.parroquia_configuraciones pc ON true
            LIMIT 1
        )
        SELECT
            a.id                                         AS asistencia_id,
            r.fecha                                       AS fecha_falta,
            r.nombre_tema                                 AS tema_reunion,
            c.id                                          AS confirmando_id,
            c.apellidos || ', ' || c.nombres              AS confirmando,
            coalesce(g.nombre, 'Sin Grupo')               AS grupo,
            coalesce(ap.apellidos || ', ' || ap.nombres, 'No registrado') AS apoderado_nombre,
            coalesce(ap.celular, 'Sin celular')           AS apoderado_celular,
            j.id                                          AS justificacion_id,
            coalesce(j.motivo, '')                        AS motivo,
            coalesce(j.descripcion, '')                   AS descripcion,
            coalesce(j.fecha_acuerdo::text, '')           AS fecha_acuerdo,
            coalesce(j.estado, 'injustificado')           AS estado_justificacion
        FROM public.asistencia a
        JOIN public.confirmandos c
             ON c.id = a.asistente_id AND a.asistente_type = 'App\Models\Confirmando'
        JOIN public.reunions r ON r.id = a.reunion_id
        LEFT JOIN public.grupos g ON g.id = c.grupo_id
        LEFT JOIN public.justificaciones j ON j.asistencia_id = a.id
        LEFT JOIN LATERAL (
            SELECT ap.apellidos, ap.nombres, ap.celular
            FROM public.confirmando_apoderado ca
            JOIN public.apoderados ap ON ap.id = ca.apoderado_id
            WHERE ca.confirmando_id = c.id
            ORDER BY ca.id
            LIMIT 1
        ) ap ON true
        CROSS JOIN cfg
        WHERE c.estado <> 'retirado'
          AND (a.estado = 'falta injustificada'
               OR (j.id IS NOT NULL AND j.estado <> 'no_cumplido'))
          AND (
              (j.id IS NOT NULL AND j.estado IN ('pendiente', 'justificado'))
              OR (a.estado = 'falta injustificada'
                  AND r.fecha >= (now() - make_interval(days => cfg.dias))
                  AND r.fecha <= now())
          )
        ORDER BY r.fecha DESC;

        GRANT SELECT ON public.v_justificaciones_pendientes TO authenticated;
        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS public.v_justificaciones_pendientes;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
