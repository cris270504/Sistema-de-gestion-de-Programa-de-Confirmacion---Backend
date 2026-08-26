<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Confirmando;
use App\Models\Grupo;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function metricasYAlertas(Request $request)
    {
        $user = $request->user();
        $esGestor = $user->can('ver usuarios'); // Definimos si es admin/coordinador

        // 1. MÉTRICAS BÁSICAS (Consultas ultra rápidas)
        $totalConfirmandos = Confirmando::count();
        $activos = Confirmando::where('estado', '!=', 'retirado')->count();
        $retirados = $totalConfirmandos - $activos;

        $metricas = [
            'cant_users' => User::count(),
            'cant_grupos' => Grupo::count(),
            'cant_confirmandos' => $totalConfirmandos,
            'activos' => $activos,
            'retirados' => $retirados,
            'tasaRetencion' => $totalConfirmandos > 0 ? round(($activos / $totalConfirmandos) * 100, 1) : 0,
            'tasaDesercion' => $totalConfirmandos > 0 ? round(($retirados / $totalConfirmandos) * 100, 1) : 0,
        ];

        // 2. LÓGICA DE ALERTAS (Punto 3)
        // Usamos Eager Loading para traer SOLO las columnas estrictamente necesarias
        $query = Confirmando::with([
            'grupo:id,nombre',
            'apoderados:id,nombres,apellidos,celular',
            'asistencias:id,asistente_id,reunion_id,estado',
            'asistencias.reunion:id,fecha',
            'asistencias.justificacion:id,asistencia_id,estado',
        ])->where('estado', '!=', 'retirado');

        // SEGURIDAD: Si es catequista, filtramos desde la BD (Ahorra memoria)
        if (! $esGestor) {
            $query->whereIn('grupo_id', $user->grupos->pluck('id'));
        }

        $confirmandos = $query->get();
        $alertas = collect();

        // Trasladamos tu lógica iterativa de JS a PHP
        foreach ($confirmandos as $c) {
            // Ordenamos las asistencias por fecha
            $asistenciasOrdenadas = $c->asistencias->sortBy(function ($a) {
                return $a->reunion->fecha ?? '';
            });

            $maxHistorico = 0;
            $rachaActiva = 0;
            $faltasInjustificadas = 0;
            $faltasJustificadas = 0;
            $tardanzas = 0;

            foreach ($asistenciasOrdenadas as $asistencia) {
                $tieneAcuerdoPendiente = $asistencia->justificacion && $asistencia->justificacion->estado === 'pendiente';

                if ($asistencia->estado === 'falta injustificada' && ! $tieneAcuerdoPendiente) {
                    $faltasInjustificadas++;
                    $rachaActiva++;
                    if ($rachaActiva > $maxHistorico) {
                        $maxHistorico = $rachaActiva;
                    }
                } else {
                    $rachaActiva = 0;
                }

                if ($asistencia->estado === 'falta justificada') {
                    $faltasJustificadas++;
                }
                if ($asistencia->estado === 'tardanza') {
                    $tardanzas++;
                }
            }

            // Tardanza en racha: solo alerta si llegó tarde en sus 2 últimas reuniones
            // (ya no es un conteo acumulado de todo el historial)
            $ultimasDosAsistencias = $asistenciasOrdenadas->values()->slice(-2);
            $tardanzaEnUltimasDos = $ultimasDosAsistencias->count() === 2
                && $ultimasDosAsistencias->every(fn ($a) => $a->estado === 'tardanza');

            $nivelRiesgo = 'NINGUNO';
            $motivoAlerta = '';

            if ($faltasInjustificadas >= 4) {
                $nivelRiesgo = 'ALTO';
                $motivoAlerta = "Alerta Crítica: {$faltasInjustificadas} faltas injustificadas ACUMULADAS.";
            } elseif ($rachaActiva >= 2) {
                $nivelRiesgo = 'ALTO';
                $motivoAlerta = "Alerta Crítica: {$rachaActiva} faltas injustificadas en sus ÚLTIMAS reuniones.";
            } elseif ($maxHistorico >= 3) {
                $nivelRiesgo = 'ALTO';
                $motivoAlerta = "Alerta Crítica: Tuvo {$maxHistorico} faltas seguidas en el pasado.";
            } elseif ($faltasJustificadas >= 4) {
                $nivelRiesgo = 'MEDIO';
                $motivoAlerta = "Alerta de Desconexión: Tiene {$faltasJustificadas} faltas justificadas.";
            } elseif ($tardanzaEnUltimasDos) {
                $nivelRiesgo = 'BAJO';
                $motivoAlerta = 'Alerta de Impuntualidad: Llegó tarde en sus últimas 2 reuniones.';
            }

            if ($nivelRiesgo !== 'NINGUNO') {
                $apoderado = $c->apoderados->first();

                $alertas->push([
                    'id' => $c->id,
                    'nombre_completo' => "{$c->apellidos}, {$c->nombres}",
                    'grupo' => $c->grupo ? $c->grupo->nombre : 'Sin grupo',
                    'total_faltas_injustificadas' => $faltasInjustificadas,
                    'total_faltas_justificadas' => $faltasJustificadas,
                    'total_tardanzas' => $tardanzas,
                    'injustificadas_seguidas' => $rachaActiva,
                    'nivel_riesgo' => $nivelRiesgo,
                    'motivo_alerta' => $motivoAlerta,
                    'nombre_apoderado' => $apoderado ? "{$apoderado->apellidos}, {$apoderado->nombres}" : 'No asignado',
                    'celular_apoderado' => $apoderado ? $apoderado->celular : $c->celular,
                ]);
            }
        }

        return response()->json([
            'metricas' => $metricas,
            'alertas' => $alertas->values(), // Formatea a array limpio
        ]);
    }
}
