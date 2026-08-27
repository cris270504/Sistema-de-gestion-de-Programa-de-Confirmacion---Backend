<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Roles que el usuario actual puede asignar. Solo el proveedor puede otorgar
     * el rol `proveedor`.
     */
    private function rolesAsignables(Request $request): array
    {
        $roles = Role::where('guard_name', 'api')->pluck('name');

        if (! $request->user()->hasRole('proveedor')) {
            $roles = $roles->reject(fn ($r) => $r === 'proveedor');
        }

        return $roles->values()->all();
    }

    public function index()
    {
        return User::parroquiaActual()->with(['roles', 'grupos'])->latest()->get();
    }

    public function show($id)
    {
        return User::parroquiaActual()->with(['roles', 'grupos'])->findOrFail($id);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // DNI opcional (no todas las parroquias/países lo usan). El email queda como
            // identificador de acceso garantizado; el login acepta correo o DNI.
            'dni' => ['nullable', 'string', 'max:20', 'unique:users,dni'],
            'celular' => ['nullable', 'string', 'max:9'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'fecha_nacimiento' => ['date', 'nullable'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->rolesAsignables($request))],
            'grupo_ids' => ['nullable', 'array'],
            'grupo_ids.*' => ['exists:grupos,id'],
        ]);

        // 6. Crear el usuario con una contraseña temporal generada (no una constante
        // conocida): se devuelve una sola vez en la respuesta, para que el admin que
        // crea la cuenta se la pase al usuario nuevo.
        $tempPassword = Str::password(10);

        $user = User::create([
            'name' => $validatedData['name'],
            'dni' => $validatedData['dni'],
            'celular' => $validatedData['celular'],
            'email' => $validatedData['email'],
            'fecha_nacimiento' => $validatedData['fecha_nacimiento'],
            'password' => Hash::make($tempPassword),
        ]);

        // 7. Sincronizar los roles
        $user->syncRoles($validatedData['roles']);

        if (! empty($validatedData['grupo_ids'])) {
            $user->grupos()->sync($validatedData['grupo_ids']);
        }

        return response()->json([
            'message' => 'Usuario creado con éxito',
            'temp_password' => $tempPassword,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'dni' => $user->dni,
                'email' => $user->email,
                'fecha_nacimiento' => $user->fecha_nacimiento,
                'roles' => $user->roles->pluck('name'),
                'grupos' => $user->grupos,
            ],
        ], 201);

    }

    public function update(Request $request, $id)
    {
        $user = User::parroquiaActual()->findOrFail($id);

        // La validación aquí está bien, permite actualizar email y contraseña opcionalmente
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'dni' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users')->ignore($user->id)],
            'celular' => ['sometimes', 'nullable', 'string', 'max:9'],
            'email' => [
                'sometimes', 'required', 'email', 'max:150',
                Rule::unique('users')->ignore($user->id),
            ],
            'fecha_nacimiento' => ['sometimes', 'nullable', 'date'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'roles' => ['sometimes', 'array'], // 'sometimes' permite no enviar roles si no cambian
            'roles.*' => ['string', Rule::in($this->rolesAsignables($request))],
            'grupo_ids' => ['sometimes', 'nullable', 'array'],
            'grupo_ids.*' => ['exists:grupos,id'],
        ]);

        // Actualizar campos solo si vienen en la petición
        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (array_key_exists('dni', $data)) {
            $user->dni = $data['dni'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }

        if (isset($data['fecha_nacimiento'])) {
            $user->fecha_nacimiento = $data['fecha_nacimiento'];
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

        if (array_key_exists('grupo_ids', $data)) {
            $user->grupos()->sync($data['grupo_ids'] ?? []);
        }

        $user->load('roles', 'grupos');

        return response()->json([
            'message' => 'Usuario actualizado con éxito',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'celular' => $user->celular,
                'dni' => $user->dni,
                'email' => $user->email,
                'fecha_nacimiento' => $user->fecha_nacimiento,
                'roles' => $user->roles->pluck('name'),
                'grupos' => $user->grupos,
            ],
        ]);
    }

    public function destroy($id)
    {
        $user = User::parroquiaActual()->findOrFail($id);
        $user->delete();

        // Código 204: No Content es más apropiado para delete exitoso sin cuerpo
        return response()->json(null, 204);
    }
}
