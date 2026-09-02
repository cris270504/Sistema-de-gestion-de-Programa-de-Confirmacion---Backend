<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Storage — bucket `branding` para los logos de las parroquias.
 *
 * Público (URL fija cacheable por CDN; la carpeta lleva el parroquia_id, así que
 * no se puede adivinar el logo de otra parroquia). Límite 512 KB, solo imágenes
 * rasterizadas (el frontend normaliza todo a WebP antes de subir).
 *
 * RLS sobre storage.objects (Supabase la deja activada; los GRANT base ya vienen):
 *   - lectura: cualquiera, para el bucket branding.
 *   - escritura: el proveedor en cualquier carpeta; el admin de la parroquia solo
 *     en `branding/<su parroquia_id>/…`.
 *
 * Ruta esperada: `branding/{parroquia_id}/{proveedor|parroquia}-{epoch}.webp`
 *
 * Solo pgsql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
        VALUES ('branding', 'branding', true, 524288,
                ARRAY['image/webp', 'image/png', 'image/jpeg'])
        ON CONFLICT (id) DO UPDATE SET
            public             = EXCLUDED.public,
            file_size_limit    = EXCLUDED.file_size_limit,
            allowed_mime_types = EXCLUDED.allowed_mime_types;

        -- Condición de escritura reutilizada por INSERT / UPDATE / DELETE.
        --   proveedor  → cualquier carpeta del bucket
        --   admin      → solo la carpeta de su parroquia
        DROP POLICY IF EXISTS "branding_public_read"  ON storage.objects;
        DROP POLICY IF EXISTS "branding_write_insert" ON storage.objects;
        DROP POLICY IF EXISTS "branding_write_update" ON storage.objects;
        DROP POLICY IF EXISTS "branding_write_delete" ON storage.objects;

        CREATE POLICY "branding_public_read" ON storage.objects
            FOR SELECT TO anon, authenticated
            USING (bucket_id = 'branding');

        CREATE POLICY "branding_write_insert" ON storage.objects
            FOR INSERT TO authenticated
            WITH CHECK (
                bucket_id = 'branding'
                AND (
                    public.app_es_proveedor()
                    OR (public.app_is_privileged()
                        AND (storage.foldername(name))[1] = public.app_current_parroquia_id()::text)
                )
            );

        CREATE POLICY "branding_write_update" ON storage.objects
            FOR UPDATE TO authenticated
            USING (
                bucket_id = 'branding'
                AND (
                    public.app_es_proveedor()
                    OR (public.app_is_privileged()
                        AND (storage.foldername(name))[1] = public.app_current_parroquia_id()::text)
                )
            )
            WITH CHECK (
                bucket_id = 'branding'
                AND (
                    public.app_es_proveedor()
                    OR (public.app_is_privileged()
                        AND (storage.foldername(name))[1] = public.app_current_parroquia_id()::text)
                )
            );

        CREATE POLICY "branding_write_delete" ON storage.objects
            FOR DELETE TO authenticated
            USING (
                bucket_id = 'branding'
                AND (
                    public.app_es_proveedor()
                    OR (public.app_is_privileged()
                        AND (storage.foldername(name))[1] = public.app_current_parroquia_id()::text)
                )
            );
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
        DROP POLICY IF EXISTS "branding_public_read"  ON storage.objects;
        DROP POLICY IF EXISTS "branding_write_insert" ON storage.objects;
        DROP POLICY IF EXISTS "branding_write_update" ON storage.objects;
        DROP POLICY IF EXISTS "branding_write_delete" ON storage.objects;

        DELETE FROM storage.objects WHERE bucket_id = 'branding';
        DELETE FROM storage.buckets WHERE id = 'branding';
        SQL);
    }
};
