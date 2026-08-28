<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Confirmando;
use App\Models\Grupo;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Métricas numéricas básicas del panel (conteos + tasas). Es barato y lo usa
     * tanto el dashboard como la respuesta del login (para pintar los números al
     * instante, sin esperar el cálculo de alertas).
     */
    public static function metricasBasicas(): array
    {
        // 1 sola query para total + activos (antes eran 2 count() separados).
        // CASE en vez de FILTER para que corra igual en Postgres (prod) y MariaDB (dev).
        $row = Confirmando::selectRaw(
            "COUNT(*) as total, SUM(CASE WHEN estado <> 'retirado' THEN 1 ELSE 0 END) as activos"
        )->first();

        $total = (int) ($row->total ?? 0);
        $activos = (int) ($row->activos ?? 0);
        $retirados = $total - $activos;

        return [
            'cant_users' => User::count(),
            'cant_grupos' => Grupo::count(),
            'cant_confirmandos' => $total,
            'activos' => $activos,
            'retirados' => $retirados,
            'tasaRetencion' => $total > 0 ? round(($activos / $total) * 100, 1) : 0,
            'tasaDesercion' => $total > 0 ? round(($retirados / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Invalida el cache del dashboard de una parroquia (subiendo un contador de
     * versión que forma parte de la clave). Se llama al guardar asistencias,
     * justificaciones o cambios de estado de confirmandos.
     */
    public static function invalidate(?int $parroquiaId = null): void
    {
        $pid = $parroquiaId ?? Tenant::parroquiaId() ?? 'all';
        $key = "dash:ver:$pid";
        Cache::put($key, ((int) Cache::get($key, 1)) + 1, now()->addDays(30));
    }

    public function metricasYAlertas(Request $request)
    {
        $user = $request->user();
        $esGestor = $user->can('ver usuarios'); // Definimos si es admin/coordinador
        $gruposIds = $user->grupos->pluck('id')->sort()->values();

        // Clave de cache: por parroquia + versión (se sube en cada mutación relevante)
        // + alcance del usuario (un gestor ve todo; un catequista solo sus grupos).
        $parroquiaId = Tenant::parroquiaId() ?? 'all';
        $ver = (int) Cache::get("dash:ver:$parroquiaId", 1);
        $scope = $esGestor ? 'gestor' : ('g'.$gruposIds->implode('-'));
        $cacheKey = "dash:metricas:$parroquiaId:v$ver:$scope";

        $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($esGestor, $gruposIds) {
            return $this->calcular($esGestor, $gruposIds->all());
        });

        return response()->json($payload);
    }

    /**
     * Cálculo pesado: recorre los confirmandos activos y sus asistencias para
     * derivar el nivel de riesgo. Cacheado por metricasYAlertas().
     */
    private function calcular(bool $esGestor, array $gruposIds): array
    {
        $umbrales = Tenant::config()['umbrales_alerta'];

        $metricas = self::metricasBasicas();

        // Eager loading acotado a las columnas estrictamente necesarias.
        $query = Confirmando::with([
            'grupo:id,nombre',
            'apoderados:id,nombres,apellidos,celular',
            'asistencias:id,asistente_id,reunion_id,estado',
            'asistencias.reunion:id,fecha',
            'asistencias.justificacion:id,asistencia_id,estado',
        ])->where('estado', '!=', 'retirado');

        // SEGURIDAD: Si es catequista, filtramos desde la BD (Ahorra memoria)
        if (! $esGestor) {
            $query->whereIn('grupo_id', $gruposIds);
        }

        $confirmandos = $query->get();
        $alertas = collect();

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
                } elseif ($asistencia->estado === 'asistio' || $asistencia->estado === 'tardanza') {
                    // Solo asistir o llegar tarde corta la racha de injustificadas.
                    // 'falta justificada' (o una injustificada con acuerdo pendiente) NO la corta:
                    // el patrón de inasistencia sigue activo, solo se está gestionando esa falta puntual.
                    $rachaActiva = 0;
                }

                if ($asistencia->estado === 'falta justificada') {
                    $faltasJustificadas++;
                }
                if ($asistencia->estado === 'tardanza') {
                    $tardanzas++;
                }
            }

            // Tardanza en racha: solo alerta si llegó tarde en sus N últimas reuniones
            // (N configurable por parroquia, ya no un conteo acumulado del historial).
            $nTardanzas = $umbrales['bajo_tardanzas_seguidas'];
            $ultimasNAsistencias = $asistenciasOrdenadas->values()->slice(-$nTardanzas);
            $tardanzaEnUltimasN = $ultimasNAsistencias->count() === $nTardanzas
                && $ultimasNAsistencias->every(fn ($a) => $a->estado === 'tardanza');

            $nivelRiesgo = 'NINGUNO';
            $motivoAlerta = '';

            if ($faltasInjustificadas >= $umbrales['alto_injustificadas']) {
                $nivelRiesgo = 'ALTO';
                $motivoAlerta = "Alerta Crítica: {$faltasInjustificadas} faltas injustificadas ACUMULADAS.";
            } elseif ($rachaActiva >= $umbrales['alto_racha']) {
                $nivelRiesgo = 'ALTO';
                $motivoAlerta = "Alerta Crítica: {$rachaActiva} faltas injustificadas en sus ÚLTIMAS reuniones.";
            } elseif ($maxHistorico >= $umbrales['alto_seguidas_historicas']) {
                $nivelRiesgo = 'ALTO';
                $motivoAlerta = "Alerta Crítica: Tuvo {$maxHistorico} faltas seguidas en el pasado.";
            } elseif ($faltasJustificadas >= $umbrales['medio_justificadas']) {
                $nivelRiesgo = 'MEDIO';
                $motivoAlerta = "Alerta de Desconexión: Tiene {$faltasJustificadas} faltas justificadas.";
            } elseif ($tardanzaEnUltimasN) {
                $nivelRiesgo = 'BAJO';
                $motivoAlerta = "Alerta de Impuntualidad: Llegó tarde en sus últimas {$nTardanzas} reuniones.";
            }

            if ($nivelRiesgo !== 'NINGUNO') {
                $apoderado = $c->apoderados->first();

                $alertas->push([
                    'id' => $c->id,
                    'nombre_completo' => "{$c->apellidos}, {$c->nombres}",
                    'grupo' => $c->grupo ? $c->grupo->nombre : 'Sin grupo',
                    'grupo_id' => $c->grupo_id,
                    'total_faltas_injustificadas' => $faltasInjustificadas,
                    'total_faltas_justificadas' => $faltasJustificadas,
                    'total_tardanzas' => $tardanzas,
                    'injustificadas_seguidas' => $maxHistorico,
                    'nivel_riesgo' => $nivelRiesgo,
                    'motivo_alerta' => $motivoAlerta,
                    'nombre_apoderado' => $apoderado ? "{$apoderado->apellidos}, {$apoderado->nombres}" : 'No asignado',
                    'celular_apoderado' => $apoderado ? $apoderado->celular : $c->celular,
                ]);
            }
        }

        return [
            'metricas' => $metricas,
            'alertas' => $alertas->values()->all(),
        ];
    }
}
