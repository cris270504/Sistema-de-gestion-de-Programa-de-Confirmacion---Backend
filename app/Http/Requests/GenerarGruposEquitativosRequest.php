<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerarGruposEquitativosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombres_grupos' => ['required', 'array', 'min:1'],
            'nombres_grupos.*' => ['required', 'string', 'max:255', 'distinct'],
            'periodo' => ['required', 'string', 'max:255'],
        ];
    }
}
