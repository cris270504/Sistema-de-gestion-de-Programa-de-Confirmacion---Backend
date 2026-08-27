<?php

namespace App\Console\Commands;

use App\Models\Parroquia;
use App\Tenancy\SembrarCatalogoSacramental;
use Illuminate\Console\Command;

class SembrarCatalogoParroquia extends Command
{
    protected $signature = 'parroquia:sembrar-catalogo {parroquia? : ID o slug de la parroquia (por defecto, todas las que no tengan catálogo)}';

    protected $description = 'Siembra el catálogo sacramental estándar en una parroquia (idempotente).';

    public function handle(SembrarCatalogoSacramental $sembrador): int
    {
        $arg = $this->argument('parroquia');

        $parroquias = $arg
            ? Parroquia::where('id', $arg)->orWhere('slug', $arg)->get()
            : Parroquia::all();

        if ($parroquias->isEmpty()) {
            $this->error('No se encontró la parroquia.');

            return self::FAILURE;
        }

        foreach ($parroquias as $parroquia) {
            $sembrador->paraParroquia($parroquia->id);
            $this->info("Catálogo verificado/sembrado para «{$parroquia->nombre}».");
        }

        return self::SUCCESS;
    }
}
