<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 — vistas para PostgREST.
 *
 * Todas `security_invoker=true` → corren con los permisos del que consulta, así
 * que la RLS de las tablas base (confirmandos/asistencia/... ya acotadas por
 * parroquia + grupo) aplica y la vista se auto-filtra por usuario.
 *
 * - v_dashboard_metricas   ← DashboardController::metricasBasicas (conteos + tasas)
 * - v_confirmando_perfil   ← ConfirmandoController::obtenerPerfilCompleto
 * - v_dashboard_alertas    ← DashboardController::calcular (rachas / umbrales)
 *
 * Los umbrales salen de `parroquia_configuraciones.umbrales_alerta` (json) con
 * los mismos defaults que `App\Tenancy\TenantConfig::DEFAULTS`.
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
        -- ── 1. Métricas numéricas (1 fila) ────────────────────────────────────
        CREATE OR REPLACE VIEW public.v_dashboard_metricas
            WITH (security_invoker = true) AS
        SELECT
            (SELECT count(*) FROM public.users)  AS cant_users,
            (SELECT count(*) FROM public.grupos) AS cant_grupos,
            count(c.*)                                       AS cant_confirmandos,
            count(c.*) FILTER (WHERE c.estado <> 'retirado') AS activos,
            count(c.*) FILTER (WHERE c.estado =  'retirado') AS retirados,
            coalesce(round(100.0 * count(c.*) FILTER (WHERE c.estado <> 'retirado')
                     / NULLIF(count(c.*), 0), 1), 0) AS "tasaRetencion",
            coalesce(round(100.0 * count(c.*) FILTER (WHERE c.estado =  'retirado')
                     / NULLIF(count(c.*), 0), 1), 0) AS "tasaDesercion"
        FROM public.confirmandos c;

        GRANT SELECT ON public.v_dashboard_metricas TO authenticated;

        -- ── 2. Perfil completo por confirmando ───────────────────────────────
        CREATE OR REPLACE VIEW public.v_confirmando_perfil
            WITH (security_invoker = true) AS
        WITH asis AS (
            SELECT
                a.asistente_id AS confirmando_id,
                a.id, a.estado, r.fecha, r.nombre_tema,
                j.estado AS just_estado
            FROM public.asistencia a
            JOIN public.reunions r ON r.id = a.reunion_id
            LEFT JOIN public.justificaciones j ON j.asistencia_id = a.id
            WHERE a.asistente_type = 'App\Models\Confirmando'
        )
        SELECT
            c.id,
            c.nombres,
            c.apellidos,
            coalesce(g.nombre, 'Sin Grupo') AS grupo,
            coalesce((
                SELECT string_agg(s.nombre, ' y ' ORDER BY s.id)
                FROM public.confirmando_sacramento cs
                JOIN public.sacramentos s ON s.id = cs.sacramento_id
                WHERE cs.confirmando_id = c.id
                  AND cs.estado = 'pendiente'
                  AND s.nombre <> 'Confirmación'
            ), 'Ninguno (Tiene todos)') AS sacramentos_faltantes,
            (
                SELECT jsonb_build_object(
                    'nombres', ap.nombres, 'apellidos', ap.apellidos, 'celular', ap.celular)
                FROM public.confirmando_apoderado ca
                JOIN public.apoderados ap ON ap.id = ca.apoderado_id
                WHERE ca.confirmando_id = c.id
                ORDER BY ca.id
                LIMIT 1
            ) AS apoderado,
            count(x.id) FILTER (WHERE x.estado = 'asistio')           AS stat_asistencias,
            count(x.id) FILTER (WHERE x.estado = 'tardanza')          AS stat_tardanzas,
            count(x.id) FILTER (WHERE x.estado = 'falta justificada') AS stat_justificadas,
            count(x.id) FILTER (WHERE x.estado = 'falta injustificada'
                                AND coalesce(x.just_estado, '') <> 'pendiente') AS stat_injustificadas,
            coalesce(
                jsonb_agg(
                    jsonb_build_object(
                        'fecha', x.fecha,
                        'tema', coalesce(x.nombre_tema, 'Reunión sin tema'),
                        'estado', x.estado,
                        'justificacion_estado', x.just_estado
                    ) ORDER BY x.fecha DESC NULLS LAST
                ) FILTER (WHERE x.id IS NOT NULL),
                '[]'::jsonb
            ) AS historial_asistencias
        FROM public.confirmandos c
        LEFT JOIN public.grupos g ON g.id = c.grupo_id
        LEFT JOIN asis x ON x.confirmando_id = c.id
        GROUP BY c.id, c.nombres, c.apellidos, g.nombre;

        GRANT SELECT ON public.v_confirmando_perfil TO authenticated;

        -- ── 3. Alertas de riesgo del dashboard ──────────────────────────────
        CREATE OR REPLACE VIEW public.v_dashboard_alertas
            WITH (security_invoker = true) AS
        WITH cfg1 AS (  -- umbrales de la parroquia (RLS da 1 fila) o defaults
            SELECT
                coalesce((pc.umbrales_alerta ->> 'alto_injustificadas')::int,      4) AS alto_injustificadas,
                coalesce((pc.umbrales_alerta ->> 'alto_racha')::int,               2) AS alto_racha,
                coalesce((pc.umbrales_alerta ->> 'alto_seguidas_historicas')::int, 3) AS alto_seguidas,
                coalesce((pc.umbrales_alerta ->> 'medio_justificadas')::int,       4) AS medio_justificadas,
                coalesce((pc.umbrales_alerta ->> 'bajo_tardanzas_seguidas')::int,  2) AS bajo_tardanzas
            FROM (SELECT 1) _
            LEFT JOIN public.parroquia_configuraciones pc ON true
            LIMIT 1
        ),
        base AS (
            SELECT
                a.asistente_id AS confirmando_id,
                a.id,
                a.estado,
                (a.estado = 'falta injustificada'
                 AND coalesce(j.estado, '') <> 'pendiente') AS es_inj,
                row_number() OVER w AS rn,
                count(*) OVER (PARTITION BY a.asistente_id) AS total_rn,
                count(*) FILTER (WHERE a.estado IN ('asistio', 'tardanza')) OVER w AS isla
            FROM public.asistencia a
            JOIN public.reunions r ON r.id = a.reunion_id
            LEFT JOIN public.justificaciones j ON j.asistencia_id = a.id
            WHERE a.asistente_type = 'App\Models\Confirmando'
            WINDOW w AS (PARTITION BY a.asistente_id ORDER BY r.fecha, a.id
                         ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
        ),
        base2 AS (
            SELECT b.*, (SELECT bajo_tardanzas FROM cfg1) AS n_tard
            FROM base b
        ),
        islas AS (
            SELECT confirmando_id, isla,
                   count(*) FILTER (WHERE es_inj) AS inj_en_isla
            FROM base2
            GROUP BY confirmando_id, isla
        ),
        agg AS (
            SELECT
                b.confirmando_id,
                count(*) FILTER (WHERE b.es_inj)                       AS faltas_injustificadas,
                count(*) FILTER (WHERE b.estado = 'falta justificada') AS faltas_justificadas,
                count(*) FILTER (WHERE b.estado = 'tardanza')          AS tardanzas,
                coalesce((SELECT max(inj_en_isla) FROM islas i WHERE i.confirmando_id = b.confirmando_id), 0)
                    AS max_historico,
                coalesce((SELECT inj_en_isla FROM islas i
                          WHERE i.confirmando_id = b.confirmando_id
                          ORDER BY i.isla DESC LIMIT 1), 0)            AS racha_activa,
                (
                    max(b.total_rn) >= max(b.n_tard)
                    AND bool_and(b.estado = 'tardanza') FILTER (WHERE b.rn > b.total_rn - b.n_tard)
                )                                                     AS tardanza_ultimas_n
            FROM base2 b
            GROUP BY b.confirmando_id
        )
        SELECT
            c.id,
            c.apellidos || ', ' || c.nombres AS nombre_completo,
            coalesce(g.nombre, 'Sin grupo')  AS grupo,
            c.grupo_id,
            coalesce(ag.faltas_injustificadas, 0) AS total_faltas_injustificadas,
            coalesce(ag.faltas_justificadas, 0)   AS total_faltas_justificadas,
            coalesce(ag.tardanzas, 0)             AS total_tardanzas,
            coalesce(ag.max_historico, 0)         AS injustificadas_seguidas,
            CASE
                WHEN coalesce(ag.faltas_injustificadas,0) >= (SELECT alto_injustificadas FROM cfg1) THEN 'ALTO'
                WHEN coalesce(ag.racha_activa,0)          >= (SELECT alto_racha FROM cfg1)          THEN 'ALTO'
                WHEN coalesce(ag.max_historico,0)         >= (SELECT alto_seguidas FROM cfg1)       THEN 'ALTO'
                WHEN coalesce(ag.faltas_justificadas,0)   >= (SELECT medio_justificadas FROM cfg1)  THEN 'MEDIO'
                WHEN coalesce(ag.tardanza_ultimas_n, false)                                          THEN 'BAJO'
                ELSE 'NINGUNO'
            END AS nivel_riesgo,
            CASE
                WHEN coalesce(ag.faltas_injustificadas,0) >= (SELECT alto_injustificadas FROM cfg1)
                    THEN 'Alerta Crítica: ' || ag.faltas_injustificadas || ' faltas injustificadas ACUMULADAS.'
                WHEN coalesce(ag.racha_activa,0) >= (SELECT alto_racha FROM cfg1)
                    THEN 'Alerta Crítica: ' || ag.racha_activa || ' faltas injustificadas en sus ÚLTIMAS reuniones.'
                WHEN coalesce(ag.max_historico,0) >= (SELECT alto_seguidas FROM cfg1)
                    THEN 'Alerta Crítica: Tuvo ' || ag.max_historico || ' faltas seguidas en el pasado.'
                WHEN coalesce(ag.faltas_justificadas,0) >= (SELECT medio_justificadas FROM cfg1)
                    THEN 'Alerta de Desconexión: Tiene ' || ag.faltas_justificadas || ' faltas justificadas.'
                WHEN coalesce(ag.tardanza_ultimas_n, false)
                    THEN 'Alerta de Impuntualidad: Llegó tarde en sus últimas ' || (SELECT bajo_tardanzas FROM cfg1) || ' reuniones.'
                ELSE ''
            END AS motivo_alerta,
            coalesce(
                (SELECT ap.apellidos || ', ' || ap.nombres
                 FROM public.confirmando_apoderado ca
                 JOIN public.apoderados ap ON ap.id = ca.apoderado_id
                 WHERE ca.confirmando_id = c.id ORDER BY ca.id LIMIT 1),
                'No asignado') AS nombre_apoderado,
            coalesce(
                (SELECT ap.celular
                 FROM public.confirmando_apoderado ca
                 JOIN public.apoderados ap ON ap.id = ca.apoderado_id
                 WHERE ca.confirmando_id = c.id ORDER BY ca.id LIMIT 1),
                c.celular) AS celular_apoderado
        FROM public.confirmandos c
        LEFT JOIN public.grupos g ON g.id = c.grupo_id
        LEFT JOIN agg ag ON ag.confirmando_id = c.id
        WHERE c.estado <> 'retirado';

        GRANT SELECT ON public.v_dashboard_alertas TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS public.v_dashboard_alertas;
            DROP VIEW IF EXISTS public.v_confirmando_perfil;
            DROP VIEW IF EXISTS public.v_dashboard_metricas;
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
