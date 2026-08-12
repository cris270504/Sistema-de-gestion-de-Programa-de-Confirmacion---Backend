<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public const CACHE_KEY = 'catalogo.permissions';

    public function index()
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function () {
            return Permission::all();
        });
    }

    public function show($id)
    {
        return Permission::findOrFail($id);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150|unique:permissions,name',
        ]);

        $permission = Permission::create([
            'name' => $data['name'],
            'guard_name' => 'api',
        ]);

        Cache::forget(self::CACHE_KEY);

        return response()->json($permission, 201);
    }

    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:150|unique:permissions,name,'.$permission->id,
        ]);

        $permission->update($data);

        Cache::forget(self::CACHE_KEY);

        return response()->json($permission);
    }

    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();

        Cache::forget(self::CACHE_KEY);

        return response()->json(['message' => 'Permiso eliminado correctamente']);
    }
}
