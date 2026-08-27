<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParroquiaConfiguracion;
use App\Tenancy\Facades\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ParroquiaConfiguracionController extends Controller
{
    public function show()
    {
        return response()->json(Tenant::config());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'programa_inicio' => ['nullable', 'date', 'required_with:programa_fin'],
            'programa_fin' => ['nullable', 'date', 'after_or_equal:programa_inicio'],

            'dias_ventana_justificacion' => ['required', 'integer', 'min:1', 'max:365'],

            'tipos_reunion' => ['required', 'array', 'min:1'],
            'tipos_reunion.*' => ['string', Rule::in(['Confirmandos', 'Catequistas', 'Apoderados'])],

            'umbrales_alerta' => ['required', 'array'],
            'umbrales_alerta.alto_injustificadas' => ['required', 'integer', 'min:1', 'max:99'],
            'umbrales_alerta.alto_racha' => ['required', 'integer', 'min:1', 'max:99'],
            'umbrales_alerta.alto_seguidas_historicas' => ['required', 'integer', 'min:1', 'max:99'],
            'umbrales_alerta.medio_justificadas' => ['required', 'integer', 'min:1', 'max:99'],
            'umbrales_alerta.bajo_tardanzas_seguidas' => ['required', 'integer', 'min:1', 'max:99'],

            'procedencias' => ['required', 'array', 'min:1'],
            'procedencias.*' => ['string', 'max:30'],

            'branding' => ['required', 'array'],
            'branding.nombre_publico' => ['nullable', 'string', 'max:120'],
            'branding.logo_url' => ['nullable', 'url', 'max:500'],
            'branding.color_primario' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        // Normaliza los tipos de reunión al orden canónico y sin duplicados.
        $data['tipos_reunion'] = array_values(array_unique($data['tipos_reunion']));
        $data['procedencias'] = array_values(array_unique(array_map('trim', $data['procedencias'])));

        $parroquiaId = Tenant::parroquiaId();

        ParroquiaConfiguracion::updateOrCreate(
            ['parroquia_id' => $parroquiaId],
            $data
        );

        Tenant::forgetConfig($parroquiaId);

        return response()->json([
            'message' => 'Configuración actualizada',
            'configuracion' => Tenant::config(),
        ]);
    }
}
