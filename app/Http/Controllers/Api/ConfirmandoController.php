<?php

namespace App\Http\Controllers\Api;

use App\Exports\ConfirmandosPorGruposExport;
use App\Http\Controllers\Controller;
use App\Models\Apoderado;
use App\Models\Confirmando;
use App\Models\Sacramento;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ConfirmandoController extends Controller
{
    public function index()
    {
        // Añadimos 'apoderados' al eager loading por si los necesitas en la lista
        return Confirmando::with(['grupo', 'sacramentos', 'apoderados','asistencias'])->latest()->get();
    }

    public function show($id)
    {
        // Cargamos 'apoderados' para que se vean al editar
        return Confirmando::with(['grupo', 'sacramentos', 'requisitos', 'apoderados'])->findOrFail($id);
    }

    public function store(Request $request)
    {
        $maxDate = now()->subYears(14)->format('Y-m-d');

        // 1. Validar Datos del Confirmando
        $validate = $request->validate([
            'nombres' => ['required', 'string', 'max:255'],
            'apellidos' => ['required', 'string', 'max:255'],
            'celular' => ['nullable', 'string', 'max:9'],
            'genero' => ['nullable', 'string', 'max:1'],
            'fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:'.$maxDate],
            'grupo_id' => ['nullable', 'exists:grupos,id'],
            'sacramento_faltante_id' => ['nullable', 'exists:sacramentos,id'],
            'estado' => ['nullable', 'in:en_preparacion,retirado,confirmado'],
        ]);

        // 2. Validar Apoderados (en un paso separado o junto, da igual)
        $apoderadosData = $request->validate([
            'apoderados' => ['nullable', 'array'],
            'apoderados.*.nombres' => ['required_with:apoderados', 'string'],
            'apoderados.*.apellidos' => ['required_with:apoderados', 'string'],
            'apoderados.*.tipo_apoderado_id' => ['required_with:apoderados', 'exists:tipo_apoderados,id'],
            'apoderados.*.celular' => ['nullable', 'string', 'max:9'],
        ]);

        // 3. Crear Confirmando
        $confirmando = Confirmando::create([
            'nombres' => $validate['nombres'],
            'apellidos' => $validate['apellidos'],
            'celular' => $validate['celular'] ?? null,
            'genero' => $validate['genero'] ?? null,
            'fecha_nacimiento' => $validate['fecha_nacimiento'] ?? null,
            'grupo_id' => $validate['grupo_id'] ?? null,
            'estado' => $validate['estado'] ?? 'en_preparacion',
        ]);

        // 4. Lógica de Negocio (Sacramentos y Requisitos)
        $this->asignarRutaSacramental($confirmando, $validate['sacramento_faltante_id']);

        // 5. Guardar Apoderados (NUEVO)
        if (! empty($apoderadosData['apoderados'])) {
            $this->syncApoderados($confirmando, $apoderadosData['apoderados']);
        }

        $confirmando->load('grupo', 'sacramentos', 'apoderados');

        return response()->json([
            'message' => 'Confirmando creado y ruta sacramental asignada',
            'confirmando' => $confirmando,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $confirmando = Confirmando::findOrFail($id);
        $maxDate = now()->subYears(14)->format('Y-m-d');

        // Validación Confirmando
        $data = $request->validate([
            'nombres' => ['sometimes', 'string'],
            'apellidos' => ['sometimes', 'string'],
            'celular' => ['sometimes', 'nullable', 'string', 'max:9'],
            'genero' => ['sometimes', 'nullable', 'string', 'max:1'],
            'fecha_nacimiento' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.$maxDate],
            'grupo_id' => ['sometimes', 'nullable', 'exists:grupos,id'],
            'sacramento_faltante_id' => ['sometimes', 'nullable', 'exists:sacramentos,id'],
            'estado' => ['sometimes', 'required', 'in:en_preparacion,retirado,confirmado'],

            'requisitos_actualizar' => ['nullable', 'array'],
        ]);

        // Validación Apoderados
        $apoderadosData = $request->validate([
            'apoderados' => ['nullable', 'array'],
            'apoderados.*.nombres' => ['required_with:apoderados', 'string'],
            'apoderados.*.apellidos' => ['required_with:apoderados', 'string'],
            'apoderados.*.tipo_apoderado_id' => ['required_with:apoderados', 'exists:tipo_apoderados,id'],
            'apoderados.*.celular' => ['nullable', 'string', 'max:9'],
        ]);

        // Actualizar Confirmando
        $confirmando->update([
            'nombres' => $data['nombres'] ?? $confirmando->nombres,
            'apellidos' => $data['apellidos'] ?? $confirmando->apellidos,
            'celular' => array_key_exists('celular', $data) ? $data['celular'] : $confirmando->celular,
            'genero' => $data['genero'] ?? $confirmando->genero,
            'fecha_nacimiento' => $data['fecha_nacimiento'] ?? $confirmando->fecha_nacimiento,
            'grupo_id' => array_key_exists('grupo_id', $data) ? $data['grupo_id'] : $confirmando->grupo_id,
            'estado' => $data['estado'] ?? $confirmando->estado,
        ]);

        // Re-calcular Ruta Sacramental
        if (isset($data['sacramento_faltante_id'])) {
            $this->asignarRutaSacramental($confirmando, $data['sacramento_faltante_id']);
        }

        // Actualizar Apoderados (NUEVO)
        if (isset($apoderadosData['apoderados'])) {
            $this->syncApoderados($confirmando, $apoderadosData['apoderados']);
        }

        if ($request->has('requisitos_actualizar')) {
            $requisitos = $request->input('requisitos_actualizar');

            foreach ($requisitos as $req) {
                // Actualizamos la tabla pivote 'requisito_confirmando'
                // Solo actualizamos el estado y la fecha de entrega
                $confirmando->requisitos()->updateExistingPivot($req['id'], [
                    'estado' => $req['estado'],
                    'fecha_entrega' => $req['estado'] === 'entregado' ? now() : null,
                ]);
            }
        }

        $confirmando->load('grupo', 'sacramentos', 'apoderados');

        return response()->json([
            'message' => 'Confirmando actualizado correctamente',
            'confirmando' => $confirmando,
        ]);
    }

    public function destroy($id)
    {
        $confirmando = Confirmando::findOrFail($id);
        $confirmando->delete();

        return response()->json(null, 204);
    }

    private function asignarRutaSacramental(Confirmando $confirmando, $faltanteId)
    {
        // Busca modelos (Asegúrate de que los nombres coincidan con tu BD)
        $bautismo = Sacramento::with('requisitos')->where('nombre', 'Bautismo')->first();
        $comunion = Sacramento::with('requisitos')->where('nombre', 'Primera Comunión')->first();
        $confirmacion = Sacramento::with('requisitos')->where('nombre', 'Confirmación')->first();

        if (! $bautismo || ! $comunion || ! $confirmacion) {
            return;
        }

        $requisitosAcumulados = collect();
        $sacramentosSyncData = [];

        // Lógica en cascada
        if ($faltanteId == $bautismo->id) {
            // Falta todo
            $sacramentosSyncData = [
                $bautismo->id => ['estado' => 'pendiente'],
                $comunion->id => ['estado' => 'pendiente'],
                $confirmacion->id => ['estado' => 'pendiente'],
            ];
            $requisitosAcumulados = $requisitosAcumulados
                ->merge($bautismo->requisitos)->merge($comunion->requisitos)->merge($confirmacion->requisitos);

        } elseif ($faltanteId == $comunion->id) {
            // Tiene Bautismo
            $sacramentosSyncData = [
                $bautismo->id => ['estado' => 'recibido'],
                $comunion->id => ['estado' => 'pendiente'],
                $confirmacion->id => ['estado' => 'pendiente'],
            ];
            $requisitosAcumulados = $requisitosAcumulados
                ->merge($comunion->requisitos)->merge($confirmacion->requisitos);

        } elseif ($faltanteId == $confirmacion->id) {
            // Solo falta Confirmación
            $sacramentosSyncData = [
                $bautismo->id => ['estado' => 'recibido'],
                $comunion->id => ['estado' => 'recibido'],
                $confirmacion->id => ['estado' => 'pendiente'],
            ];
            $requisitosAcumulados = $requisitosAcumulados
                ->merge($confirmacion->requisitos);
        }

        $confirmando->sacramentos()->sync($sacramentosSyncData);

        // Sincronización de requisitos inteligente (mantiene avance si es posible)
        $idsUnicos = $requisitosAcumulados->pluck('id')->unique();
        $requisitosActuales = $confirmando->requisitos()->get()->keyBy('id');
        $reqsSyncData = [];

        foreach ($idsUnicos as $idReq) {
            // Si ya existe, mantenemos su estado ('Entregado')
            if ($requisitosActuales->has($idReq)) {
                $reqsSyncData[$idReq] = [
                    'estado' => $requisitosActuales[$idReq]->pivot->estado,
                    'fecha_entrega' => $requisitosActuales[$idReq]->pivot->fecha_entrega,
                ];
            } else {
                // Si es nuevo, Pendiente
                $reqsSyncData[$idReq] = ['estado' => 'pendiente'];
            }
        }

        $confirmando->requisitos()->sync($reqsSyncData);
    }

    /**
     * Sincroniza los apoderados evitando duplicados por nombre.
     */
    private function syncApoderados(Confirmando $confirmando, array $listaApoderados)
    {
        $idsParaSincronizar = [];

        foreach ($listaApoderados as $datosAp) {
            // Buscamos o creamos al apoderado (por nombre y apellido)
            // Si ya existe alguien con ese nombre, lo reutilizamos
            $apoderado = Apoderado::firstOrCreate(
                [
                    'nombres' => $datosAp['nombres'],
                    'apellidos' => $datosAp['apellidos'],
                ],
                [
                    'celular' => $datosAp['celular'] ?? null,
                ]
            );

            // Si el apoderado ya existía, actualizamos su celular si nos mandaron uno nuevo
            if (isset($datosAp['celular'])) {
                $apoderado->update(['celular' => $datosAp['celular']]);
            }

            // Preparamos el ID y el dato pivote (tipo de parentesco)
            $idsParaSincronizar[$apoderado->id] = [
                'tipo_apoderado_id' => $datosAp['tipo_apoderado_id'],
            ];
        }

        // Sincronizamos (esto borra relaciones antiguas de este confirmando y pone las nuevas)
        $confirmando->apoderados()->sync($idsParaSincronizar);
    }

    public function buscarApoderados(Request $request)
    {
        $query = $request->get('q');
        if (strlen($query) < 3) {
            return response()->json([]);
        }

        return \App\Models\Apoderado::where('apellidos', 'LIKE', "%{$query}%")
            ->orWhere('nombres', 'LIKE', "%{$query}%")
            ->limit(5)
            ->get();
    }

    public function importar(Request $request)
    {
        // Cambié "mimes" por "file" porque a veces Excel y Laravel tienen problemas
        // reconociendo el mime type exacto desde distintos sistemas operativos.
        $request->validate([
            'archivo' => 'required|file|max:5000',
        ]);

        try {
            $data = Excel::toArray(new \stdClass, $request->file('archivo'))[0];

            $erroresFatales = [];
            $advertencias = [];
            $importados = 0;

            foreach ($data as $index => $row) {
                if ($index === 0 && strtolower(trim($row[0] ?? '')) === 'nombres') {
                    continue;
                }

                $nombreCompleto = trim($row[0] ?? '');
                $celular = trim($row[1] ?? '');
                $numeroFila = $index + 1;

                if (empty($nombreCompleto)) {
                    $erroresFatales[] = "Fila $numeroFila: El nombre está vacío (No se guardó).";

                    continue;
                }

                // 2. Separar Apellidos y Nombres (Formato: Apellido1 Apellido2 Nombre1 Nombre2)
                // Usamos array_filter para eliminar espacios dobles accidentales entre palabras
                $partes = array_values(array_filter(explode(' ', $nombreCompleto)));
                $cantidadPalabras = count($partes);

                if ($cantidadPalabras >= 3) {
                    // Tomamos las dos primeras palabras como apellidos
                    $apellidos = $partes[0].' '.$partes[1];
                    // Todo lo que sobra desde la posición 2 en adelante son los nombres
                    $nombres = implode(' ', array_slice($partes, 2));
                } elseif ($cantidadPalabras == 2) {
                    // Si solo puso 2 palabras, asumimos Apellido y Nombre
                    $apellidos = $partes[0];
                    $nombres = $partes[1];
                } else {
                    // Si solo hay 1 palabra, se va a nombres por defecto
                    $nombres = $partes[0] ?? '';
                    $apellidos = '';
                }

                // 3. Validar Celular (NUEVA LÓGICA)
                if (! empty($celular)) {
                    // Quitamos espacios en blanco accidentales
                    $celular = str_replace(' ', '', $celular);

                    if (! preg_match('/^[0-9]{9}$/', $celular)) {
                        // Guardamos la advertencia, pero permitimos que continúe
                        $advertencias[] = "- $nombreCompleto (Fila $numeroFila): Se guardó sin celular porque '$celular' no es válido.";
                        $celular = null;
                    }
                } else {
                    $celular = null;
                }

                // 4. Guardar en BD
                Confirmando::create([
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'celular' => $celular,
                    // IMPORTANTE: Tu BD seguramente pide fecha de nacimiento (según tu método store).
                    // Ponemos una por defecto para que no falle la BD. Luego la editan.
                    'fecha_nacimiento' => null,
                ]);

                $importados++;
            }

            // Si hubo errores que impidieron guardar ALGUNAS filas (ej: sin nombre)
            if (count($erroresFatales) > 0) {
                return response()->json([
                    'message' => "Se importaron $importados confirmandos. Hubo filas omitidas.",
                    'errors' => ['archivo' => array_merge($erroresFatales, $advertencias)],
                ], 422);
            }

            // Si TODO se guardó, pero hubo advertencias de celulares
            $mensajeFinal = "Se importaron $importados confirmandos correctamente.";

            if (count($advertencias) > 0) {
                $mensajeFinal .= "\n\nOjo, se hicieron estos ajustes:\n".implode("\n", $advertencias);
            }

            return response()->json(['message' => $mensajeFinal], 200);

        } catch (Exception $e) {
            return response()->json(['message' => 'Error al leer el archivo: '.$e->getMessage()], 500);
        }
    }

    public function exportarExcel()
    {
        return Excel::download(new ConfirmandosPorGruposExport, 'Confirmandos_por_Grupos.xlsx');
    }

    public function getRetentionStats()
    {
        $stats = Confirmando::select('estado', DB::raw('count(*) as total'))
            ->groupBy('estado')
            ->get()
            ->pluck('total', 'estado');

        // Aseguramos que los índices existan para evitar errores en el front
        return response()->json([
            'en_preparacion' => $stats['en_preparacion'] ?? 0,
            'retirado' => $stats['retirado'] ?? 0,
            'confirmado' => $stats['confirmado'] ?? 0,
            'total' => $stats->sum(),
        ]);
    }
}
