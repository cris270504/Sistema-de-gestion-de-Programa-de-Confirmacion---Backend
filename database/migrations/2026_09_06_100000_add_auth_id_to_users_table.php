<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 de la migración a Supabase: enlaza cada usuario de la app con su
 * identidad en Supabase Auth (auth.users).
 *
 * La app sigue usando users.id (bigint); auth_id es el puente. Lo llena el
 * backfill (supabase/migrations/20260830200000_fase1_backfill_auth.sql en
 * pgsql/Supabase; en otros entornos queda null hasta que exista Auth).
 *
 * La FK a auth.users solo se declara en Postgres (en sqlite de los tests no
 * existe el esquema auth).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'auth_id')) {
                $table->uuid('auth_id')->nullable()->unique();
            }
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_name = 'users_auth_id_fkey' AND table_name = 'users'
                    ) THEN
                        ALTER TABLE public.users
                            ADD CONSTRAINT users_auth_id_fkey
                            FOREIGN KEY (auth_id) REFERENCES auth.users (id) ON DELETE SET NULL;
                    END IF;
                END $$;
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('ALTER TABLE public.users DROP CONSTRAINT IF EXISTS users_auth_id_fkey');
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'auth_id')) {
                $table->dropUnique(['auth_id']);
                $table->dropColumn('auth_id');
            }
        });
    }
};
