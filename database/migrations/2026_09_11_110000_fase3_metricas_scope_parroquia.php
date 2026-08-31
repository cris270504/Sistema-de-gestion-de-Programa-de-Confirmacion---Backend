<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 — ajuste: `v_dashboard_metricas` debe contar a nivel PARROQUIA, no del
 * alcance-por-grupo del que consulta.
 *
 * Con `security_invoker` un catequista veía `cant_users=1 / cant_grupos=1 /
 * cant_confirmandos=1` (solo su fila / su grupo). El `DashboardController`
 * original contaba a nivel parroquia para todos (los tiles del panel muestran el
 * total de la parroquia). Las ALERTAS sí siguen acotadas por grupo
 * (v_dashboard_alertas queda como está).
 *
 * Se respalda la vista con una función SECURITY DEFINER que filtra explícito por
 * `app_current_parroquia_id()`.
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
        CREATE OR REPLACE FUNCTION public.fn_dashboard_metricas()
        RETURNS TABLE (
            cant_users bigint, cant_grupos bigint, cant_confirmandos bigint,
            activos bigint, retirados bigint,
            "tasaRetencion" numeric, "tasaDesercion" numeric
        )
        LANGUAGE sql
        STABLE
        SECURITY DEFINER
        SET search_path = public, pg_temp
        AS $$
            WITH pid AS (SELECT public.app_current_parroquia_id() AS v),
            c AS (
                SELECT
                    count(*) AS total,
                    count(*) FILTER (WHERE estado <> 'retirado') AS act,
                    count(*) FILTER (WHERE estado =  'retirado') AS ret
                FROM public.confirmandos, pid
                WHERE pid.v IS NULL OR confirmandos.parroquia_id = pid.v
            )
            SELECT
                (SELECT count(*) FROM public.users, pid WHERE pid.v IS NULL OR users.parroquia_id = pid.v),
                (SELECT count(*) FROM public.grupos, pid WHERE pid.v IS NULL OR grupos.parroquia_id = pid.v),
                c.total, c.act, c.ret,
                coalesce(round(100.0 * c.act / NULLIF(c.total, 0), 1), 0),
                coalesce(round(100.0 * c.ret / NULLIF(c.total, 0), 1), 0)
            FROM c;
        $$;

        REVOKE ALL ON FUNCTION public.fn_dashboard_metricas() FROM public;
        GRANT EXECUTE ON FUNCTION public.fn_dashboard_metricas() TO authenticated;

        CREATE OR REPLACE VIEW public.v_dashboard_metricas
            WITH (security_invoker = true) AS
        SELECT * FROM public.fn_dashboard_metricas();

        GRANT SELECT ON public.v_dashboard_metricas TO authenticated;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
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
            DROP FUNCTION IF EXISTS public.fn_dashboard_metricas();
            NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
