<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Config `ui` — cuarta rebanada: `ui.confirmandos_estado_default` (con qué filtro
 * de estado arranca la lista de Confirmandos). Valores: 'en_preparacion',
 * 'confirmado', 'retirado', 'todos'. Ausente ⇒ 'en_preparacion' (default actual).
 *
 * Es escalar, no lista → nuevo helper `_ui_merge_valor` (hermano de
 * `_ui_merge_lista`). `_ui_procesar` suma una línea; `fn_guardar_configuracion`
 * NO se toca.
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
        -- Helper para valores escalares enum de `ui` (vs _ui_merge_lista para arrays).
        CREATE OR REPLACE FUNCTION public._ui_merge_valor(
            _ui jsonb, _in jsonb, _key text, _allowed text[]
        ) RETURNS jsonb
        LANGUAGE plpgsql IMMUTABLE AS $$
        DECLARE _v text;
        BEGIN
            IF _in IS NULL OR NOT (_in ? _key) THEN
                RETURN coalesce(_ui, '{}'::jsonb);
            END IF;
            _v := _in ->> _key;
            IF _v IS NULL OR _v <> ALL(_allowed) THEN
                RAISE EXCEPTION 'Valor no válido en %', _key USING ERRCODE = 'check_violation';
            END IF;
            RETURN jsonb_set(coalesce(_ui, '{}'::jsonb), ARRAY[_key], to_jsonb(_v));
        END;
        $$;

        REVOKE ALL ON FUNCTION public._ui_merge_valor(jsonb, jsonb, text, text[]) FROM public;
        GRANT EXECUTE ON FUNCTION public._ui_merge_valor(jsonb, jsonb, text, text[]) TO authenticated;

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

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
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
            RETURN _ui;
        END;
        $$;

        DROP FUNCTION IF EXISTS public._ui_merge_valor(jsonb, jsonb, text, text[]);
        NOTIFY pgrst, 'reload schema';
        SQL);
    }
};
