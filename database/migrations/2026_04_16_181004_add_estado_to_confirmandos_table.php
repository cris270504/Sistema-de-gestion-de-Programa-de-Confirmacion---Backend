<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('confirmandos', function (Blueprint $table) {
            // Usamos enum para restringir los valores exactos
            $table->enum('estado', ['en_preparacion', 'retirado', 'confirmado'])
                  ->default('en_preparacion')
                  ->after('grupo_id');
        });
    }

    public function down()
    {
        Schema::table('confirmandos', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};