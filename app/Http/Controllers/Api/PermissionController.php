<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index()
    {
        return Permission::all();
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

        return response()->json($permission, 201);
    }

    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:150|unique:permissions,name,'.$permission->id,
        ]);

        $permission->update($data);

        return response()->json($permission);
    }

    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();

        return response()->json(['message' => 'Permiso eliminado correctamente']);
    }
}
