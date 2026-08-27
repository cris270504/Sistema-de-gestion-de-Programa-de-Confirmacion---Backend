<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Parroquia;
use App\Models\User;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\SembrarCatalogoSacramental;
use App\Tenancy\TenantConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Panel del proveedor: alta y administración de parroquias. Todas las rutas están
 * bajo `permission:administrar plataforma`.
 */
class ProveedorParroquiaController extends Controller
{
    public function index()
    {
        return Tenant::runPrivileged(fn () => Parroquia::query()
            ->withCount(['users', 'grupos', 'confirmandos'])
            ->orderBy('nombre')
            ->get());
    }

    public function store(Request $request, SembrarCatalogoSacramental $sembrador)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:80', 'alpha_dash', 'unique:parroquias,slug'],
            'zona_horaria' => ['nullable', 'string', 'max:64'],
            'admin_nombre' => ['required', 'string', 'max:100'],
            'admin_email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'admin_dni' => ['nullable', 'string', 'max:20', 'unique:users,dni'],
        ]);

        return Tenant::runPrivileged(function () use ($data, $sembrador) {
            $resultado = DB::transaction(function () use ($data, $sembrador) {
                $parroquia = Parroquia::create([
                    'nombre' => $data['nombre'],
                    'slug' => ($data['slug'] ?? null) ?: Str::slug($data['nombre']).'-'.Str::lower(Str::random(4)),
                    'zona_horaria' => ($data['zona_horaria'] ?? null) ?: 'America/Lima',
                    'activa' => true,
                ]);

                // Configuración por defecto
                $d = TenantConfig::DEFAULTS;
                $parroquia->configuracion()->create([
                    'dias_ventana_justificacion' => $d['dias_ventana_justificacion'],
                    'tipos_reunion' => $d['tipos_reunion'],
                    'umbrales_alerta' => $d['umbrales_alerta'],
                    'procedencias' => $d['procedencias'],
                    'branding' => array_merge($d['branding'], ['nombre_publico' => $data['nombre']]),
                    'roles_labels' => [],
                ]);

                // Primer usuario admin de la parroquia
                $tempPassword = Str::password(10);
                $admin = User::forceCreate([
                    'parroquia_id' => $parroquia->id,
                    'name' => $data['admin_nombre'],
                    'email' => $data['admin_email'],
                    'dni' => $data['admin_dni'] ?? null,
                    'password' => $tempPassword, // el cast 'hashed' del modelo lo hashea
                ]);
                $admin->assignRole('super-admin');

                // Catálogo sacramental estándar
                $sembrador->paraParroquia($parroquia->id);

                return ['parroquia' => $parroquia, 'admin' => $admin, 'temp_password' => $tempPassword];
            });

            return response()->json([
                'message' => 'Parroquia creada.',
                'parroquia' => $resultado['parroquia'],
                'admin' => [
                    'email' => $resultado['admin']->email,
                    'temp_password' => $resultado['temp_password'], // se muestra una sola vez
                ],
            ], 201);
        });
    }

    public function update(Request $request, Parroquia $parroquia)
    {
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:80', 'alpha_dash', Rule::unique('parroquias', 'slug')->ignore($parroquia->id)],
            'activa' => ['sometimes', 'boolean'],
            'zona_horaria' => ['sometimes', 'string', 'max:64'],
        ]);

        $parroquia->update($data);

        return response()->json(['message' => 'Parroquia actualizada.', 'parroquia' => $parroquia]);
    }
}
