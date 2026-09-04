<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stress test ronda 2 — R2-4, R2-6, R2-9.
 *
 * R2-4: `ui.confirmado_exige_requisitos` ('si'|'no', default no). Si 'si', un
 *       trigger impide pasar a estado 'confirmado' con requisitos o sacramentos
 *       aún 'pendiente'.
 * R2-6: `fn_asignar_ruta_sacramental` ya no borra `confirmando_requisito` en
 *       estado 'entregado' al recalcular la ruta (conserva el historial).
 * R2-9: `fn_generar_grupos_equitativo` aborta con mensaje si no logró
 *       crear/encontrar todos los grupos (evitaba grupo_id = NULL).
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
        -- ── R2-4: toggle en ui + trigger ──────────────────────────────────
        CREATE OR REPLACE FUNCTION public._ui_procesar(_prev jsonb, _in jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql IMMUTABLE
        AS $fn$
        DECLARE _ui jsonb := coalesce(_prev, '{}'::jsonb);
        BEGIN
            IF _in IS NULL THEN RETURN _ui; END IF;
            IF jsonb_typeof(_in) <> 'object' THEN
                RAISE EXCEPTION 'La sección "ui" debe ser un objeto' USING ERRCODE = 'check_violation';
            END IF;
            _ui := public._ui_merge_lista(_ui, _in, 'dashboard_kpis',
                                          ARRAY['confirmandos', 'usuarios', 'grupos']);
            _ui := public._ui_merge_lista(_ui, _in, 'dashboard_paneles',
                                          ARRAY['seguimiento_critico', 'proximos_encuentros', 'retencion']);
            _ui := public._ui_merge_lista(_ui, _in, 'modulos_ocultos',
                                          ARRAY['cronograma', 'cumpleanos', 'sacramentos', 'requisitos']);
            _ui := public._ui_merge_lista(_ui, _in, 'confirmando_obligatorios',
                                          ARRAY['celular', 'fecha_nacimiento', 'genero']);
            _ui := public._ui_merge_valor(_ui, _in, 'confirmandos_estado_default',
                                          ARRAY['en_preparacion', 'confirmado', 'retirado', 'todos']);
            _ui := public._ui_merge_valor(_ui, _in, 'confirmado_exige_requisitos',
                                          ARRAY['si', 'no']);
            RETURN _ui;
        END;
        $fn$;

        CREATE OR REPLACE FUNCTION public.confirmando_valida_confirmado()
        RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE _exige boolean; _npend int;
        BEGIN
            IF NEW.estado <> 'confirmado' OR OLD.estado = 'confirmado' THEN
                RETURN NEW;
            END IF;

            SELECT (ui->>'confirmado_exige_requisitos') = 'si' INTO _exige
              FROM public.parroquia_configuraciones
             WHERE parroquia_id = coalesce(NEW.parroquia_id, public.app_current_parroquia_id());

            IF NOT coalesce(_exige, false) THEN
                RETURN NEW;
            END IF;

            SELECT count(*) INTO _npend FROM public.confirmando_requisito
             WHERE confirmando_id = NEW.id AND estado = 'pendiente';
            IF _npend > 0 THEN
                RAISE EXCEPTION 'No se puede marcar como confirmado: tiene % documento(s) pendiente(s).', _npend
                    USING ERRCODE = 'check_violation';
            END IF;

            SELECT count(*) INTO _npend FROM public.confirmando_sacramento
             WHERE confirmando_id = NEW.id AND estado = 'pendiente';
            IF _npend > 0 THEN
                RAISE EXCEPTION 'No se puede marcar como confirmado: tiene % sacramento(s) pendiente(s) en su ruta.', _npend
                    USING ERRCODE = 'check_violation';
            END IF;

            RETURN NEW;
        END;
        $fn$;

        DROP TRIGGER IF EXISTS trg_confirmando_valida_confirmado ON public.confirmandos;
        CREATE TRIGGER trg_confirmando_valida_confirmado
            BEFORE UPDATE OF estado ON public.confirmandos
            FOR EACH ROW EXECUTE FUNCTION public.confirmando_valida_confirmado();

        -- ── R2-6: no borrar requisitos 'entregado' al recalcular la ruta ──
        CREATE OR REPLACE FUNCTION public.fn_asignar_ruta_sacramental(
            p_confirmando_id bigint, p_sacramento_faltante_id bigint
        ) RETURNS void
        LANGUAGE plpgsql
        AS $fn$
        DECLARE
            _clave_falta text;
            _ord_falta   int;
            _sac_ids     bigint[];
            _pend_ids    bigint[];
            _req_ids     bigint[];
        BEGIN
            IF p_sacramento_faltante_id IS NULL THEN RETURN; END IF;

            SELECT array_agg(s.id) INTO _sac_ids
              FROM public.sacramentos s
             WHERE s.clave IN ('bautismo', 'comunion', 'confirmacion');
            IF _sac_ids IS NULL THEN
                RAISE EXCEPTION 'La parroquia no tiene configurada una ruta sacramental (Bautismo / Primera Comunión / Confirmación)'
                    USING ERRCODE = 'no_data_found';
            END IF;

            SELECT s.clave INTO _clave_falta
              FROM public.sacramentos s
             WHERE s.id = p_sacramento_faltante_id AND s.clave IN ('bautismo', 'comunion', 'confirmacion');
            IF _clave_falta IS NULL THEN
                RAISE EXCEPTION 'El sacramento indicado no pertenece a la ruta sacramental de esta parroquia'
                    USING ERRCODE = 'invalid_parameter_value';
            END IF;

            _ord_falta := CASE _clave_falta WHEN 'bautismo' THEN 1 WHEN 'comunion' THEN 2 WHEN 'confirmacion' THEN 3 END;

            SELECT array_agg(s.id) INTO _pend_ids
              FROM public.sacramentos s
             WHERE s.clave IN ('bautismo', 'comunion', 'confirmacion')
               AND (CASE s.clave WHEN 'bautismo' THEN 1 WHEN 'comunion' THEN 2 WHEN 'confirmacion' THEN 3 END) >= _ord_falta;

            INSERT INTO public.confirmando_sacramento (confirmando_id, sacramento_id, estado)
            SELECT p_confirmando_id, sid,
                   CASE WHEN sid = ANY(_pend_ids) THEN 'pendiente' ELSE 'recibido' END
              FROM unnest(_sac_ids) sid
            ON CONFLICT (confirmando_id, sacramento_id) DO UPDATE SET estado = EXCLUDED.estado;

            DELETE FROM public.confirmando_sacramento
             WHERE confirmando_id = p_confirmando_id AND sacramento_id <> ALL(_sac_ids);

            SELECT coalesce(array_agg(DISTINCT sr.requisito_id), '{}') INTO _req_ids
              FROM public.sacramento_requisito sr WHERE sr.sacramento_id = ANY(_pend_ids);

            INSERT INTO public.confirmando_requisito (confirmando_id, requisito_id, estado)
            SELECT p_confirmando_id, r, 'pendiente' FROM unnest(_req_ids) r
            ON CONFLICT (confirmando_id, requisito_id) DO NOTHING;

            -- Solo se quitan los que están 'pendiente'; los 'entregado' se conservan
            -- como historial aunque la ruta ya no los pida (R2-6).
            DELETE FROM public.confirmando_requisito
             WHERE confirmando_id = p_confirmando_id
               AND estado = 'pendiente'
               AND requisito_id <> ALL(_req_ids);
        END;
        $fn$;

        -- ── R2-9: fn_generar_grupos_equitativo con guard de _grupo_ids ────
        CREATE OR REPLACE FUNCTION public.fn_generar_grupos_equitativo(
            p_nombres text[], p_periodo text, p_estrategia text DEFAULT 'genero'
        ) RETURNS jsonb
        LANGUAGE plpgsql
        AS $fn$
        DECLARE
            _parroquia    bigint := public.app_current_parroquia_id();
            _n            int    := array_length(p_nombres, 1);
            _periodo      text   := btrim(coalesce(p_periodo, ''));
            _proc         text;
            _emin         int;
            _emax         int;
            _ins_ids      bigint[];
            _grupo_ids    bigint[];
            _nuevos       int;
            _total        int;
            _asignaciones jsonb;
            _grupos       jsonb;
            _no_asig      jsonb;
        BEGIN
            IF NOT public.app_is_privileged() THEN
                RAISE EXCEPTION 'No autorizado para generar grupos' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF _n IS NULL OR _n = 0 THEN
                RAISE EXCEPTION 'Debe indicar al menos un nombre de grupo' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF p_estrategia NOT IN ('genero', 'edad', 'ninguno') THEN
                RAISE EXCEPTION 'Estrategia de reparto no válida' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF _periodo = '' OR length(_periodo) > 20 THEN
                RAISE EXCEPTION 'Indica un periodo válido (ej: 2025-2026)' USING ERRCODE = 'invalid_parameter_value';
            END IF;
            IF (SELECT count(*) <> count(DISTINCT trim(x)) FROM unnest(p_nombres) AS x) THEN
                RAISE EXCEPTION 'Los nombres de grupo no pueden repetirse' USING ERRCODE = 'invalid_parameter_value';
            END IF;

            PERFORM pg_advisory_xact_lock(hashtext('gen_grupos:' || _parroquia || ':' || _periodo));

            SELECT grupos_edad_min, grupos_edad_max, procedencias->>0
              INTO _emin, _emax, _proc
              FROM public.parroquia_configuraciones WHERE parroquia_id = _parroquia;
            _proc := coalesce(_proc, 'sede');

            WITH input AS (SELECT DISTINCT trim(n) AS nombre FROM unnest(p_nombres) AS t(n)),
            ins AS (
                INSERT INTO public.grupos (nombre, periodo, color, procedencia)
                SELECT i.nombre, _periodo,
                       '#' || lpad(to_hex((random() * 16777215)::int), 6, '0'), _proc
                  FROM input i
                ON CONFLICT (parroquia_id, nombre, periodo) DO NOTHING
                RETURNING id
            )
            SELECT coalesce(array_agg(id), '{}') INTO _ins_ids FROM ins;
            _nuevos := coalesce(array_length(_ins_ids, 1), 0);

            SELECT array_agg(g.id ORDER BY t.ord) INTO _grupo_ids
              FROM unnest(p_nombres) WITH ORDINALITY AS t(n, ord)
              JOIN public.grupos g
                ON g.parroquia_id = _parroquia AND g.nombre = trim(t.n) AND g.periodo = _periodo;

            IF _grupo_ids IS NULL OR array_length(_grupo_ids, 1) <> _n THEN
                RAISE EXCEPTION 'No se pudieron preparar todos los grupos; vuelve a intentarlo'
                    USING ERRCODE = 'no_data_found';
            END IF;

            WITH elegibles AS (
                SELECT id,
                       row_number() OVER (
                           PARTITION BY CASE WHEN p_estrategia = 'genero' THEN lower(genero) END
                           ORDER BY
                               CASE WHEN p_estrategia = 'edad'   THEN fecha_nacimiento END ASC  NULLS LAST,
                               CASE WHEN p_estrategia = 'genero' THEN fecha_nacimiento END DESC NULLS LAST,
                               id
                       ) - 1 AS rn
                  FROM public.confirmandos
                 WHERE grupo_id IS NULL
                   AND estado = 'en_preparacion'
                   AND (p_estrategia <> 'genero' OR lower(genero) IN ('m', 'f'))
                   AND (fecha_nacimiento IS NULL OR (
                        (_emin IS NULL OR extract(year FROM age(current_date, fecha_nacimiento)) >= _emin)
                    AND (_emax IS NULL OR extract(year FROM age(current_date, fecha_nacimiento)) <= _emax)
                   ))
            ),
            asign AS (SELECT id, _grupo_ids[(rn % _n) + 1] AS grupo_id FROM elegibles),
            upd AS (
                UPDATE public.confirmandos c SET grupo_id = a.grupo_id
                  FROM asign a WHERE c.id = a.id
                RETURNING c.id, a.grupo_id
            )
            SELECT count(*)::int, coalesce(jsonb_object_agg(id, grupo_id), '{}'::jsonb)
              INTO _total, _asignaciones FROM upd;

            SELECT coalesce(jsonb_agg(jsonb_build_object(
                       'id', id, 'nombres', nombres, 'apellidos', apellidos, 'motivo', motivo
                   ) ORDER BY apellidos, nombres), '[]'::jsonb)
              INTO _no_asig
              FROM (
                SELECT id, nombres, apellidos,
                       CASE
                         WHEN p_estrategia = 'genero' AND (genero IS NULL OR lower(genero) NOT IN ('m', 'f'))
                              THEN 'sin género definido (requiere M/F para esta estrategia)'
                         WHEN fecha_nacimiento IS NOT NULL AND _emin IS NOT NULL
                              AND extract(year FROM age(current_date, fecha_nacimiento)) < _emin
                              THEN 'menor de ' || _emin || ' años'
                         WHEN fecha_nacimiento IS NOT NULL AND _emax IS NOT NULL
                              AND extract(year FROM age(current_date, fecha_nacimiento)) > _emax
                              THEN 'mayor de ' || _emax || ' años'
                         ELSE 'no se pudo asignar'
                       END AS motivo
                  FROM public.confirmandos
                 WHERE grupo_id IS NULL AND estado = 'en_preparacion'
              ) x;

            SELECT coalesce(jsonb_agg(to_jsonb(g) ORDER BY g.id), '[]'::jsonb) INTO _grupos
              FROM (SELECT id, nombre, periodo, color, procedencia
                      FROM public.grupos
                     WHERE parroquia_id = _parroquia AND periodo = _periodo) g;

            RETURN jsonb_build_object(
                'total_asignados',   _total,
                'grupos_nuevos',     _nuevos,
                'grupos_existentes', _n - _nuevos,
                'asignaciones',      _asignaciones,
                'grupos',            _grupos,
                'no_asignados',      _no_asig
            );
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
        DROP TRIGGER IF EXISTS trg_confirmando_valida_confirmado ON public.confirmandos;
        DROP FUNCTION IF EXISTS public.confirmando_valida_confirmado();
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
