<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regla de negocio configurable #1: campos obligatorios del confirmando.
 *
 * `ui.confirmando_obligatorios` — subconjunto de ['celular','fecha_nacimiento',
 * 'genero'] que la parroquia exige. Default: [] (todos opcionales, como hoy).
 *
 * Se hace cumplir con un trigger BEFORE INSERT/UPDATE en `confirmandos`, no en el
 * RPC: cubre cualquier camino de escritura y no hay que repintar
 * fn_guardar_confirmando. El trigger:
 *   - valida SIEMPRE en alta,
 *   - en edición solo si el UPDATE toca ese campo puntual (así el reparto de
 *     grupos, que solo cambia grupo_id, nunca se rompe por un confirmando sin
 *     celular),
 *   - lee la parroquia de NEW.parroquia_id, y si aún es NULL (orden de triggers
 *     en el INSERT) del claim del JWT. En la importación Excel (service_role, sin
 *     claim) queda NULL ⇒ la importación sigue siendo tolerante, a propósito.
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
        -- ── 1. `ui.confirmando_obligatorios` en el validador de config ─────────
        CREATE OR REPLACE FUNCTION public._ui_procesar(_prev jsonb, _in jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql IMMUTABLE AS $$
        DECLARE _ui jsonb := coalesce(_prev, '{}'::jsonb);
        BEGIN
            IF _in IS NULL THEN
                RETURN _ui;
            END IF;
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
            RETURN _ui;
        END;
        $$;

        -- ── 2. Trigger que exige los campos configurados ──────────────────────
        CREATE OR REPLACE FUNCTION public.confirmando_valida_obligatorios()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        DECLARE
            _oblig text[];
            _pid   bigint := coalesce(NEW.parroquia_id, public.app_current_parroquia_id());
        BEGIN
            IF _pid IS NULL THEN
                RETURN NEW;   -- sin contexto de parroquia (import): no se exige nada
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
                RAISE EXCEPTION 'El celular es obligatorio para esta parroquia'
                    USING ERRCODE = 'not_null_violation';
            END IF;

            IF 'fecha_nacimiento' = ANY(_oblig)
               AND NEW.fecha_nacimiento IS NULL
               AND (TG_OP = 'INSERT' OR NEW.fecha_nacimiento IS DISTINCT FROM OLD.fecha_nacimiento) THEN
                RAISE EXCEPTION 'La fecha de nacimiento es obligatoria para esta parroquia'
                    USING ERRCODE = 'not_null_violation';
            END IF;

            IF 'genero' = ANY(_oblig)
               AND coalesce(NEW.genero, '') = ''
               AND (TG_OP = 'INSERT' OR NEW.genero IS DISTINCT FROM OLD.genero) THEN
                RAISE EXCEPTION 'El género es obligatorio para esta parroquia'
                    USING ERRCODE = 'not_null_violation';
            END IF;

            RETURN NEW;
        END;
        $$;

        DROP TRIGGER IF EXISTS trg_confirmando_obligatorios ON public.confirmandos;
        CREATE TRIGGER trg_confirmando_obligatorios
            BEFORE INSERT OR UPDATE ON public.confirmandos
            FOR EACH ROW EXECUTE FUNCTION public.confirmando_valida_obligatorios();

        NOTIFY pgrst, 'reload schema';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS trg_confirmando_obligatorios ON public.confirmandos;
        DROP FUNCTION IF EXISTS public.confirmando_valida_obligatorios();

        CREATE OR REPLACE FUNCTION public._ui_procesar(_prev jsonb, _in jsonb)
        RETURNS jsonb
        LANGUAGE plpgsql IMMUTABLE AS $$
        DECLARE _ui jsonb := coalesce(_prev, '{}'::jsonb);
        BEGIN
            IF _in IS NULL THEN
                RETURN _ui;
            END IF;
            IF jsonb_typeof(_in) <> 'object' THEN
                RAISE EXCEPTION 'La sección "ui" debe ser un objeto' USING ERRCODE = 'check_violation';
            END IF;
            _ui := public._ui_merge_lista(_ui, _in, 'dashboard_kpis',
                                          ARRAY['confirmandos', 'usuarios', 'grupos']);
            _ui := public._ui_merge_lista(_ui, _in, 'dashboard_paneles',
                                          ARRAY['seguimiento_critico', 'proximos_encuentros', 'retencion']);
            _ui := public._ui_merge_lista(_ui, _in, 'modulos_ocultos',
                                          ARRAY['cronograma', 'cumpleanos', 'sacramentos', 'requisitos']);
            _ui := public._ui_merge_valor(_ui, _in, 'confirmandos_estado_default',
                                          ARRAY['en_preparacion', 'confirmado', 'retirado', 'todos']);
            RETURN _ui;
        END;
        $$;

        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
