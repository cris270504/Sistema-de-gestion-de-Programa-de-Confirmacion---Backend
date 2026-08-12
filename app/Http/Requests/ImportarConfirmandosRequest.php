<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarConfirmandosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // "file" en vez de "mimes": el mime type de .xlsx varía entre sistemas
            // operativos y a veces Laravel no lo reconoce correctamente.
            'archivo' => ['required', 'file', 'max:5000'],
        ];
    }
}
