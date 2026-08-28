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
            // `extensions` valida por extensión declarada (no por mime, que varía
            // entre SO). `mimetypes` como segunda barrera con los tipos reales de
            // xlsx/xls/csv. max:5000 = 5 MB. Sin esto, cualquier archivo llegaba
            // al parser de PhpSpreadsheet (riesgo de zip-bomb / consumo de memoria).
            'archivo' => [
                'required', 'file', 'max:5000',
                'extensions:xlsx,xls,csv',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv,text/plain,application/csv',
            ],
        ];
    }
}
