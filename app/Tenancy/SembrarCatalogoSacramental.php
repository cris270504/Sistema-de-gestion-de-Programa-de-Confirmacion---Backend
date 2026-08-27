<?php

namespace App\Tenancy;

use App\Models\Requisito;
use App\Models\Sacramento;
use App\Tenancy\Facades\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Siembra el catálogo sacramental estándar (Bautismo → Primera Comunión →
 * Confirmación con sus requisitos y la cascada de documentos) en una parroquia.
 *
 * Es un punto de partida genérico y solo-documental (sin estipendios ni cosas
 * particulares de una parroquia); cada parroquia lo edita después desde la UI de
 * Sacramentos/Requisitos.
 *
 * Idempotente: si la parroquia ya tiene sacramentos, no hace nada.
 */
class SembrarCatalogoSacramental
{
    /** clave interna => nombre visible (editable por la parroquia). */
    private const REQUISITOS = [
        'acta_nacimiento' => 'Acta de nacimiento del confirmando',
        'dni_confirmando' => 'Copia de DNI del confirmando',
        'dni_apoderado' => 'Copia de DNI de los apoderados',
        'partida_bautismo' => 'Partida de Bautismo',
        'constancia_comunion' => 'Constancia de Primera Comunión',
        'constancia_padrino' => 'Constancia de Confirmación o Matrimonio del padrino/madrina',
        'dni_padrino' => 'Copia de DNI del padrino/madrina',
    ];

    /** clave del sacramento => nombre + requisitos que exige. */
    private const SACRAMENTOS = [
        'bautismo' => [
            'nombre' => 'Bautismo',
            'requisitos' => ['acta_nacimiento', 'dni_confirmando', 'dni_apoderado'],
        ],
        'comunion' => [
            'nombre' => 'Primera Comunión',
            'requisitos' => ['partida_bautismo', 'dni_confirmando'],
        ],
        'confirmacion' => [
            'nombre' => 'Confirmación',
            'requisitos' => ['partida_bautismo', 'constancia_comunion', 'dni_confirmando', 'constancia_padrino', 'dni_padrino'],
        ],
    ];

    public function paraParroquia(int $parroquiaId): void
    {
        Tenant::runFor($parroquiaId, function () {
            if (Sacramento::query()->exists()) {
                return; // ya tiene catálogo
            }

            DB::transaction(function () {
                $reqIds = [];
                foreach (self::REQUISITOS as $clave => $nombre) {
                    $reqIds[$clave] = Requisito::create(['nombre' => $nombre])->id;
                }

                foreach (self::SACRAMENTOS as $clave => $data) {
                    $sacramento = Sacramento::create(['nombre' => $data['nombre'], 'clave' => $clave]);
                    $sacramento->requisitos()->attach(
                        array_map(fn ($r) => $reqIds[$r], $data['requisitos'])
                    );
                }
            });
        });
    }
}
