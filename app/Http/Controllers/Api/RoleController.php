<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public const CACHE_KEY = 'catalogo.roles';

    /**
     * Roles del sistema: son globales (compartidos por TODAS las parroquias, no
     * hay `parroquia_id` en `roles`). Solo el proveedor los toca; un super-admin
     * que pudiera editarlos afectaría a todas las parroquias del SaaS.
     */
    private const ROLES_SISTEMA = ['proveedor', 'super-admin', 'coordinador', 'catequista'];

    /**
     * Permisos que el usuario actual puede otorgar a un rol: solo los que él
     * mismo posee. Impide que un super-admin se conceda `administrar plataforma`
     * (u otro permiso que no tiene) editando un rol.
     * El proveedor pasa el Gate::before para todo y los tiene todos igual.
     */
    private function permisosAsignables(Request $request): Collection
    {
        return $request->user()->getAllPermissions()->pluck('name');
    }

    /**
     * Un no-proveedor no puede crear/editar/borrar roles del sistema.
     */
    private function assertPuedeGestionar(Request $request, ?Role $role): void
    {
        if ($request->user()->hasRole('proveedor')) {
            return;
        }

        if ($role && in_array($role->name, self::ROLES_SISTEMA, true)) {
            abort(403, 'Los roles del sistema solo los administra el proveedor de la plataforma.');
        }
    }

    public function index()
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function () {
            return Role::with('permissions')->get();
        });
    }

    public function show($id)
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json($role);
    }

    public function store(Request $request)
    {
        $asignables = $this->permisosAsignables($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:150', 'unique:roles,name',
                Rule::notIn(self::ROLES_SISTEMA),
            ],
            'permissions' => 'array',
            'permissions.*' => ['string', Rule::in($asignables->all())],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'api',
        ]);

        if (! empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        Cache::forget(self::CACHE_KEY);

        return response()->json($role, 201);
    }

    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $this->assertPuedeGestionar($request, $role);

        $asignables = $this->permisosAsignables($request);

        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                'unique:roles,name,'.$role->id,
                Rule::notIn(self::ROLES_SISTEMA),
            ],
            'permissions' => 'array',
            'permissions.*' => ['string', Rule::in($asignables->all())],
        ]);

        if (isset($data['name'])) {
            $role->name = $data['name'];
            $role->save();
        }

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        Cache::forget(self::CACHE_KEY);

        return response()->json($role);
    }

    public function destroy(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $this->assertPuedeGestionar($request, $role);

        if (in_array($role->name, self::ROLES_SISTEMA, true)) {
            return response()->json(['message' => 'No puedes eliminar roles del sistema'], 403);
        }

        $role->delete();

        Cache::forget(self::CACHE_KEY);

        return response()->json(null, 204);
    }
}
