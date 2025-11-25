<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return User::with(['roles','grupo'])->latest()->get();
    }

    public function show($id)
    {
        return User::with(['roles','grupo'])->findOrFail($id);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'dni' => ['required', 'string', 'max:8', 'unique:users,dni'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        // 6. Crear el usuario
        $user = User::create([
            'name' => $validatedData['name'],
            'dni' => $validatedData['dni'],
            'email' => $validatedData['email'],
            'password' => Hash::make('123456789'),
        ]);

        // 7. Sincronizar los roles
        $user->syncRoles($validatedData['roles']);

        return response()->json([
            'message' => 'Usuario creado con éxito',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'dni' => $user->dni,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ],
        ], 201);
        
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // La validación aquí está bien, permite actualizar email y contraseña opcionalmente
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'dni' => ['sometimes','required','string','max:8',Rule::unique('users')->ignore($user->id),],
            'email' => [
                'sometimes', 'required', 'email', 'max:150',
                Rule::unique('users')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'roles' => ['sometimes', 'array'], // 'sometimes' permite no enviar roles si no cambian
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        // Actualizar campos solo si vienen en la petición
        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['dni'])){
            $user->dni = $data['dni'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }

        // Actualizar contraseña solo si se envió una nueva
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        // Sincronizar roles solo si se enviaron en la petición
        if (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        $user->save();

        $user->load('roles');

        return response()->json([
            'message' => 'Usuario actualizado con éxito',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,  
                'dni' => $user->dni,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ],
        ]);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        // Código 204: No Content es más apropiado para delete exitoso sin cuerpo
        return response()->json(null, 204);
    }
}
