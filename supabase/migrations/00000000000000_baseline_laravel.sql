


SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;


COMMENT ON SCHEMA "public" IS 'standard public schema';



CREATE EXTENSION IF NOT EXISTS "pg_stat_statements" WITH SCHEMA "extensions";






CREATE EXTENSION IF NOT EXISTS "pgcrypto" WITH SCHEMA "extensions";






CREATE EXTENSION IF NOT EXISTS "supabase_vault" WITH SCHEMA "vault";






CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA "extensions";






CREATE OR REPLACE FUNCTION "public"."app_can_access_asistente"("p_type" "text", "p_id" bigint) RETURNS boolean
    LANGUAGE "sql" STABLE
    AS $$
        SELECT CASE p_type
            WHEN 'App\Models\Confirmando' THEN EXISTS (
                SELECT 1 FROM confirmandos
                WHERE id = p_id AND grupo_id IN (SELECT app_user_grupo_ids())
            )
            WHEN 'App\Models\Apoderado' THEN EXISTS (
                SELECT 1 FROM confirmando_apoderado ca
                JOIN confirmandos c ON c.id = ca.confirmando_id
                WHERE ca.apoderado_id = p_id AND c.grupo_id IN (SELECT app_user_grupo_ids())
            )
            WHEN 'App\Models\User' THEN p_id = app_current_user_id()
            ELSE false
        END
    $$;


ALTER FUNCTION "public"."app_can_access_asistente"("p_type" "text", "p_id" bigint) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."app_current_parroquia_id"() RETURNS bigint
    LANGUAGE "sql" STABLE
    AS $$
        SELECT NULLIF(current_setting('app.current_parroquia_id', true), '')::bigint
    $$;


ALTER FUNCTION "public"."app_current_parroquia_id"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."app_current_user_id"() RETURNS bigint
    LANGUAGE "sql" STABLE
    AS $$
        SELECT NULLIF(current_setting('app.current_user_id', true), '')::bigint
    $$;


ALTER FUNCTION "public"."app_current_user_id"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."app_is_privileged"() RETURNS boolean
    LANGUAGE "sql" STABLE
    AS $$
        SELECT COALESCE(NULLIF(current_setting('app.current_user_privileged', true), ''), 'false')::boolean
    $$;


ALTER FUNCTION "public"."app_is_privileged"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."app_parroquia_ok"("p_parroquia_id" bigint) RETURNS boolean
    LANGUAGE "sql" STABLE
    AS $$
        SELECT app_current_parroquia_id() IS NULL OR p_parroquia_id = app_current_parroquia_id()
    $$;


ALTER FUNCTION "public"."app_parroquia_ok"("p_parroquia_id" bigint) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."app_user_grupo_ids"() RETURNS SETOF bigint
    LANGUAGE "sql" STABLE
    AS $$
        SELECT grupo_id FROM catequista_grupo WHERE user_id = app_current_user_id()
    $$;


ALTER FUNCTION "public"."app_user_grupo_ids"() OWNER TO "postgres";

SET default_tablespace = '';

SET default_table_access_method = "heap";


CREATE TABLE IF NOT EXISTS "public"."apoderados" (
    "id" bigint NOT NULL,
    "nombres" character varying(255) NOT NULL,
    "apellidos" character varying(255) NOT NULL,
    "celular" character(9),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "parroquia_id" bigint NOT NULL
);

ALTER TABLE ONLY "public"."apoderados" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."apoderados" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."apoderados_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."apoderados_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."apoderados_id_seq" OWNED BY "public"."apoderados"."id";



CREATE TABLE IF NOT EXISTS "public"."asistencia" (
    "id" bigint NOT NULL,
    "reunion_id" bigint NOT NULL,
    "estado" character varying(255) NOT NULL,
    "asistente_type" character varying(255) NOT NULL,
    "asistente_id" bigint NOT NULL,
    "nota" character varying(255),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "asistencia_estado_check" CHECK ((("estado")::"text" = ANY ((ARRAY['asistio'::character varying, 'tardanza'::character varying, 'falta justificada'::character varying, 'falta injustificada'::character varying])::"text"[])))
);

ALTER TABLE ONLY "public"."asistencia" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."asistencia" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."asistencia_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."asistencia_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."asistencia_id_seq" OWNED BY "public"."asistencia"."id";



CREATE TABLE IF NOT EXISTS "public"."cache" (
    "key" character varying(255) NOT NULL,
    "value" "text" NOT NULL,
    "expiration" integer NOT NULL
);


ALTER TABLE "public"."cache" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."cache_locks" (
    "key" character varying(255) NOT NULL,
    "owner" character varying(255) NOT NULL,
    "expiration" integer NOT NULL
);


ALTER TABLE "public"."cache_locks" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."catequista_grupo" (
    "id" bigint NOT NULL,
    "user_id" bigint NOT NULL,
    "grupo_id" bigint NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);

ALTER TABLE ONLY "public"."catequista_grupo" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."catequista_grupo" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."catequista_grupo_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."catequista_grupo_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."catequista_grupo_id_seq" OWNED BY "public"."catequista_grupo"."id";



CREATE TABLE IF NOT EXISTS "public"."confirmando_apoderado" (
    "id" bigint NOT NULL,
    "confirmando_id" bigint NOT NULL,
    "apoderado_id" bigint NOT NULL,
    "tipo_apoderado_id" bigint NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);

ALTER TABLE ONLY "public"."confirmando_apoderado" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."confirmando_apoderado" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."confirmando_apoderado_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."confirmando_apoderado_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."confirmando_apoderado_id_seq" OWNED BY "public"."confirmando_apoderado"."id";



CREATE TABLE IF NOT EXISTS "public"."confirmando_requisito" (
    "id" bigint NOT NULL,
    "confirmando_id" bigint NOT NULL,
    "requisito_id" bigint NOT NULL,
    "estado" character varying(255) DEFAULT 'pendiente'::character varying NOT NULL,
    "fecha_entrega" "date",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "confirmando_requisito_estado_check" CHECK ((("estado")::"text" = ANY ((ARRAY['pendiente'::character varying, 'entregado'::character varying])::"text"[])))
);


ALTER TABLE "public"."confirmando_requisito" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."confirmando_requisito_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."confirmando_requisito_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."confirmando_requisito_id_seq" OWNED BY "public"."confirmando_requisito"."id";



CREATE TABLE IF NOT EXISTS "public"."confirmando_sacramento" (
    "id" bigint NOT NULL,
    "confirmando_id" bigint NOT NULL,
    "sacramento_id" bigint NOT NULL,
    "estado" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "confirmando_sacramento_estado_check" CHECK ((("estado")::"text" = ANY ((ARRAY['pendiente'::character varying, 'recibido'::character varying])::"text"[])))
);


ALTER TABLE "public"."confirmando_sacramento" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."confirmando_sacramento_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."confirmando_sacramento_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."confirmando_sacramento_id_seq" OWNED BY "public"."confirmando_sacramento"."id";



CREATE TABLE IF NOT EXISTS "public"."confirmandos" (
    "id" bigint NOT NULL,
    "nombres" character varying(255) NOT NULL,
    "apellidos" character varying(255) NOT NULL,
    "celular" character(9),
    "genero" character(1),
    "fecha_nacimiento" "date",
    "grupo_id" bigint,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "estado" character varying(255) DEFAULT 'en_preparacion'::character varying NOT NULL,
    "parroquia_id" bigint NOT NULL,
    CONSTRAINT "confirmandos_estado_check" CHECK ((("estado")::"text" = ANY ((ARRAY['en_preparacion'::character varying, 'retirado'::character varying, 'confirmado'::character varying])::"text"[])))
);

ALTER TABLE ONLY "public"."confirmandos" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."confirmandos" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."confirmandos_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."confirmandos_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."confirmandos_id_seq" OWNED BY "public"."confirmandos"."id";



CREATE TABLE IF NOT EXISTS "public"."failed_jobs" (
    "id" bigint NOT NULL,
    "uuid" character varying(255) NOT NULL,
    "connection" "text" NOT NULL,
    "queue" "text" NOT NULL,
    "payload" "text" NOT NULL,
    "exception" "text" NOT NULL,
    "failed_at" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE "public"."failed_jobs" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."failed_jobs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNED BY "public"."failed_jobs"."id";



CREATE TABLE IF NOT EXISTS "public"."frontend_error_logs" (
    "id" bigint NOT NULL,
    "user_id" bigint,
    "message" "text" NOT NULL,
    "stack" "text",
    "url" character varying(255),
    "user_agent" character varying(255),
    "created_at" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "parroquia_id" bigint NOT NULL
);

ALTER TABLE ONLY "public"."frontend_error_logs" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."frontend_error_logs" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."frontend_error_logs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."frontend_error_logs_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."frontend_error_logs_id_seq" OWNED BY "public"."frontend_error_logs"."id";



CREATE TABLE IF NOT EXISTS "public"."grupos" (
    "id" bigint NOT NULL,
    "nombre" character varying(255) NOT NULL,
    "periodo" character varying(255) NOT NULL,
    "color" character varying(9) NOT NULL,
    "procedencia" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "parroquia_id" bigint NOT NULL,
    CONSTRAINT "grupos_procedencia_check" CHECK ((("procedencia")::"text" = ANY ((ARRAY['sede'::character varying, 'caserio'::character varying])::"text"[])))
);

ALTER TABLE ONLY "public"."grupos" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."grupos" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."grupos_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."grupos_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."grupos_id_seq" OWNED BY "public"."grupos"."id";



CREATE TABLE IF NOT EXISTS "public"."job_batches" (
    "id" character varying(255) NOT NULL,
    "name" character varying(255) NOT NULL,
    "total_jobs" integer NOT NULL,
    "pending_jobs" integer NOT NULL,
    "failed_jobs" integer NOT NULL,
    "failed_job_ids" "text" NOT NULL,
    "options" "text",
    "cancelled_at" integer,
    "created_at" integer NOT NULL,
    "finished_at" integer
);


ALTER TABLE "public"."job_batches" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."jobs" (
    "id" bigint NOT NULL,
    "queue" character varying(255) NOT NULL,
    "payload" "text" NOT NULL,
    "attempts" smallint NOT NULL,
    "reserved_at" integer,
    "available_at" integer NOT NULL,
    "created_at" integer NOT NULL
);


ALTER TABLE "public"."jobs" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."jobs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."jobs_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."jobs_id_seq" OWNED BY "public"."jobs"."id";



CREATE TABLE IF NOT EXISTS "public"."justificaciones" (
    "id" bigint NOT NULL,
    "asistencia_id" bigint NOT NULL,
    "motivo" character varying(255) NOT NULL,
    "descripcion" "text",
    "estado" character varying(255) DEFAULT 'injustificado'::character varying NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "fecha_acuerdo" "date",
    CONSTRAINT "justificaciones_estado_check" CHECK ((("estado")::"text" = ANY ((ARRAY['injustificado'::character varying, 'pendiente'::character varying, 'justificado'::character varying, 'no_cumplido'::character varying])::"text"[])))
);

ALTER TABLE ONLY "public"."justificaciones" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."justificaciones" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."justificaciones_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."justificaciones_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."justificaciones_id_seq" OWNED BY "public"."justificaciones"."id";



CREATE TABLE IF NOT EXISTS "public"."migrations" (
    "id" integer NOT NULL,
    "migration" character varying(255) NOT NULL,
    "batch" integer NOT NULL
);


ALTER TABLE "public"."migrations" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."migrations_id_seq"
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."migrations_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."migrations_id_seq" OWNED BY "public"."migrations"."id";



CREATE TABLE IF NOT EXISTS "public"."model_has_permissions" (
    "permission_id" bigint NOT NULL,
    "model_type" character varying(255) NOT NULL,
    "model_id" bigint NOT NULL
);


ALTER TABLE "public"."model_has_permissions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."model_has_roles" (
    "role_id" bigint NOT NULL,
    "model_type" character varying(255) NOT NULL,
    "model_id" bigint NOT NULL
);


ALTER TABLE "public"."model_has_roles" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."oauth_access_tokens" (
    "id" character(80) NOT NULL,
    "user_id" bigint,
    "client_id" "uuid" NOT NULL,
    "name" character varying(255),
    "scopes" "text",
    "revoked" boolean NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "expires_at" timestamp(0) without time zone
);


ALTER TABLE "public"."oauth_access_tokens" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."oauth_auth_codes" (
    "id" character(80) NOT NULL,
    "user_id" bigint NOT NULL,
    "client_id" "uuid" NOT NULL,
    "scopes" "text",
    "revoked" boolean NOT NULL,
    "expires_at" timestamp(0) without time zone
);


ALTER TABLE "public"."oauth_auth_codes" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."oauth_clients" (
    "id" "uuid" NOT NULL,
    "owner_type" character varying(255),
    "owner_id" bigint,
    "name" character varying(255) NOT NULL,
    "secret" character varying(255),
    "provider" character varying(255),
    "redirect_uris" "text" NOT NULL,
    "grant_types" "text" NOT NULL,
    "revoked" boolean NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."oauth_clients" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."oauth_device_codes" (
    "id" character(80) NOT NULL,
    "user_id" bigint,
    "client_id" "uuid" NOT NULL,
    "user_code" character(8) NOT NULL,
    "scopes" "text" NOT NULL,
    "revoked" boolean NOT NULL,
    "user_approved_at" timestamp(0) without time zone,
    "last_polled_at" timestamp(0) without time zone,
    "expires_at" timestamp(0) without time zone
);


ALTER TABLE "public"."oauth_device_codes" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."oauth_refresh_tokens" (
    "id" character(80) NOT NULL,
    "access_token_id" character(80) NOT NULL,
    "revoked" boolean NOT NULL,
    "expires_at" timestamp(0) without time zone
);


ALTER TABLE "public"."oauth_refresh_tokens" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."parroquia_configuraciones" (
    "id" bigint NOT NULL,
    "parroquia_id" bigint NOT NULL,
    "programa_inicio" "date",
    "programa_fin" "date",
    "dias_ventana_justificacion" smallint DEFAULT '21'::smallint NOT NULL,
    "tipos_reunion" json NOT NULL,
    "umbrales_alerta" json NOT NULL,
    "procedencias" json NOT NULL,
    "branding" json NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "roles_labels" json
);

ALTER TABLE ONLY "public"."parroquia_configuraciones" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."parroquia_configuraciones" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."parroquia_configuraciones_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."parroquia_configuraciones_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."parroquia_configuraciones_id_seq" OWNED BY "public"."parroquia_configuraciones"."id";



CREATE TABLE IF NOT EXISTS "public"."parroquias" (
    "id" bigint NOT NULL,
    "nombre" character varying(255) NOT NULL,
    "slug" character varying(255) NOT NULL,
    "activa" boolean DEFAULT true NOT NULL,
    "zona_horaria" character varying(255) DEFAULT 'America/Lima'::character varying NOT NULL,
    "contacto_email" character varying(255),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."parroquias" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."parroquias_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."parroquias_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."parroquias_id_seq" OWNED BY "public"."parroquias"."id";



CREATE TABLE IF NOT EXISTS "public"."password_reset_tokens" (
    "email" character varying(255) NOT NULL,
    "token" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone
);


ALTER TABLE "public"."password_reset_tokens" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."permissions" (
    "id" bigint NOT NULL,
    "name" character varying(255) NOT NULL,
    "guard_name" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."permissions" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."permissions_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."permissions_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."permissions_id_seq" OWNED BY "public"."permissions"."id";



CREATE TABLE IF NOT EXISTS "public"."requisitos" (
    "id" bigint NOT NULL,
    "nombre" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "parroquia_id" bigint NOT NULL
);

ALTER TABLE ONLY "public"."requisitos" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."requisitos" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."requisitos_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."requisitos_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."requisitos_id_seq" OWNED BY "public"."requisitos"."id";



CREATE TABLE IF NOT EXISTS "public"."reunion_user" (
    "id" bigint NOT NULL,
    "reunion_id" bigint NOT NULL,
    "user_id" bigint NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."reunion_user" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."reunion_user_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."reunion_user_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."reunion_user_id_seq" OWNED BY "public"."reunion_user"."id";



CREATE TABLE IF NOT EXISTS "public"."reunions" (
    "id" bigint NOT NULL,
    "nombre_tema" character varying(255) NOT NULL,
    "fecha" timestamp(0) without time zone NOT NULL,
    "descripcion" "text",
    "tipo" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "parroquia_id" bigint NOT NULL,
    CONSTRAINT "reunions_tipo_check" CHECK ((("tipo")::"text" = ANY ((ARRAY['Catequistas'::character varying, 'Confirmandos'::character varying, 'Apoderados'::character varying])::"text"[])))
);

ALTER TABLE ONLY "public"."reunions" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."reunions" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."reunions_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."reunions_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."reunions_id_seq" OWNED BY "public"."reunions"."id";



CREATE TABLE IF NOT EXISTS "public"."role_has_permissions" (
    "permission_id" bigint NOT NULL,
    "role_id" bigint NOT NULL
);


ALTER TABLE "public"."role_has_permissions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."roles" (
    "id" bigint NOT NULL,
    "name" character varying(255) NOT NULL,
    "guard_name" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."roles" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."roles_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."roles_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."roles_id_seq" OWNED BY "public"."roles"."id";



CREATE TABLE IF NOT EXISTS "public"."sacramento_requisito" (
    "id" bigint NOT NULL,
    "sacramento_id" bigint NOT NULL,
    "requisito_id" bigint NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."sacramento_requisito" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."sacramento_requisito_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."sacramento_requisito_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."sacramento_requisito_id_seq" OWNED BY "public"."sacramento_requisito"."id";



CREATE TABLE IF NOT EXISTS "public"."sacramentos" (
    "id" bigint NOT NULL,
    "nombre" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "clave" character varying(30),
    "parroquia_id" bigint NOT NULL
);

ALTER TABLE ONLY "public"."sacramentos" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."sacramentos" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."sacramentos_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."sacramentos_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."sacramentos_id_seq" OWNED BY "public"."sacramentos"."id";



CREATE TABLE IF NOT EXISTS "public"."sessions" (
    "id" character varying(255) NOT NULL,
    "user_id" bigint,
    "ip_address" character varying(45),
    "user_agent" "text",
    "payload" "text" NOT NULL,
    "last_activity" integer NOT NULL
);


ALTER TABLE "public"."sessions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."tipo_apoderados" (
    "id" bigint NOT NULL,
    "nombre" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "parroquia_id" bigint NOT NULL
);

ALTER TABLE ONLY "public"."tipo_apoderados" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."tipo_apoderados" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."tipo_apoderados_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."tipo_apoderados_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."tipo_apoderados_id_seq" OWNED BY "public"."tipo_apoderados"."id";



CREATE TABLE IF NOT EXISTS "public"."users" (
    "id" bigint NOT NULL,
    "dni" character varying(20),
    "grupo_id" bigint,
    "name" character varying(255) NOT NULL,
    "celular" character(255),
    "email" character varying(255) NOT NULL,
    "fecha_nacimiento" "date",
    "email_verified_at" timestamp(0) without time zone,
    "password" character varying(255) NOT NULL,
    "remember_token" character varying(100),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "parroquia_id" bigint NOT NULL,
    "activo" boolean DEFAULT true NOT NULL
);

ALTER TABLE ONLY "public"."users" FORCE ROW LEVEL SECURITY;


ALTER TABLE "public"."users" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."users_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."users_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."users_id_seq" OWNED BY "public"."users"."id";



ALTER TABLE ONLY "public"."apoderados" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."apoderados_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."asistencia" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."asistencia_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."catequista_grupo" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."catequista_grupo_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."confirmando_apoderado" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."confirmando_apoderado_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."confirmando_requisito" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."confirmando_requisito_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."confirmando_sacramento" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."confirmando_sacramento_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."confirmandos" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."confirmandos_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."failed_jobs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."failed_jobs_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."frontend_error_logs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."frontend_error_logs_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."grupos" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."grupos_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."jobs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."jobs_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."justificaciones" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."justificaciones_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."migrations" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."migrations_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."parroquia_configuraciones" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."parroquia_configuraciones_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."parroquias" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."parroquias_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."permissions" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."permissions_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."requisitos" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."requisitos_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."reunion_user" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."reunion_user_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."reunions" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."reunions_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."roles" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."roles_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."sacramento_requisito" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."sacramento_requisito_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."sacramentos" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."sacramentos_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."tipo_apoderados" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."tipo_apoderados_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."users" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."users_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."apoderados"
    ADD CONSTRAINT "apoderados_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."asistencia"
    ADD CONSTRAINT "asistencia_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."cache_locks"
    ADD CONSTRAINT "cache_locks_pkey" PRIMARY KEY ("key");



ALTER TABLE ONLY "public"."cache"
    ADD CONSTRAINT "cache_pkey" PRIMARY KEY ("key");



ALTER TABLE ONLY "public"."catequista_grupo"
    ADD CONSTRAINT "catequista_grupo_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."confirmando_apoderado"
    ADD CONSTRAINT "confirmando_apoderado_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."confirmando_apoderado"
    ADD CONSTRAINT "confirmando_apoderado_tipo_unique" UNIQUE ("confirmando_id", "apoderado_id", "tipo_apoderado_id");



ALTER TABLE ONLY "public"."confirmando_requisito"
    ADD CONSTRAINT "confirmando_requisito_confirmando_id_requisito_id_unique" UNIQUE ("confirmando_id", "requisito_id");



ALTER TABLE ONLY "public"."confirmando_requisito"
    ADD CONSTRAINT "confirmando_requisito_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."confirmando_sacramento"
    ADD CONSTRAINT "confirmando_sacramento_confirmando_id_sacramento_id_unique" UNIQUE ("confirmando_id", "sacramento_id");



ALTER TABLE ONLY "public"."confirmando_sacramento"
    ADD CONSTRAINT "confirmando_sacramento_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."confirmandos"
    ADD CONSTRAINT "confirmandos_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_uuid_unique" UNIQUE ("uuid");



ALTER TABLE ONLY "public"."frontend_error_logs"
    ADD CONSTRAINT "frontend_error_logs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."grupos"
    ADD CONSTRAINT "grupos_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."job_batches"
    ADD CONSTRAINT "job_batches_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."jobs"
    ADD CONSTRAINT "jobs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."justificaciones"
    ADD CONSTRAINT "justificaciones_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."migrations"
    ADD CONSTRAINT "migrations_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."model_has_permissions"
    ADD CONSTRAINT "model_has_permissions_pkey" PRIMARY KEY ("permission_id", "model_id", "model_type");



ALTER TABLE ONLY "public"."model_has_roles"
    ADD CONSTRAINT "model_has_roles_pkey" PRIMARY KEY ("role_id", "model_id", "model_type");



ALTER TABLE ONLY "public"."oauth_access_tokens"
    ADD CONSTRAINT "oauth_access_tokens_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."oauth_auth_codes"
    ADD CONSTRAINT "oauth_auth_codes_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."oauth_clients"
    ADD CONSTRAINT "oauth_clients_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."oauth_device_codes"
    ADD CONSTRAINT "oauth_device_codes_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."oauth_device_codes"
    ADD CONSTRAINT "oauth_device_codes_user_code_unique" UNIQUE ("user_code");



ALTER TABLE ONLY "public"."oauth_refresh_tokens"
    ADD CONSTRAINT "oauth_refresh_tokens_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."parroquia_configuraciones"
    ADD CONSTRAINT "parroquia_configuraciones_parroquia_id_unique" UNIQUE ("parroquia_id");



ALTER TABLE ONLY "public"."parroquia_configuraciones"
    ADD CONSTRAINT "parroquia_configuraciones_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."parroquias"
    ADD CONSTRAINT "parroquias_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."parroquias"
    ADD CONSTRAINT "parroquias_slug_unique" UNIQUE ("slug");



ALTER TABLE ONLY "public"."password_reset_tokens"
    ADD CONSTRAINT "password_reset_tokens_pkey" PRIMARY KEY ("email");



ALTER TABLE ONLY "public"."permissions"
    ADD CONSTRAINT "permissions_name_guard_name_unique" UNIQUE ("name", "guard_name");



ALTER TABLE ONLY "public"."permissions"
    ADD CONSTRAINT "permissions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."requisitos"
    ADD CONSTRAINT "requisitos_parroquia_id_nombre_unique" UNIQUE ("parroquia_id", "nombre");



ALTER TABLE ONLY "public"."requisitos"
    ADD CONSTRAINT "requisitos_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."reunion_user"
    ADD CONSTRAINT "reunion_user_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."reunion_user"
    ADD CONSTRAINT "reunion_user_reunion_id_user_id_unique" UNIQUE ("reunion_id", "user_id");



ALTER TABLE ONLY "public"."reunions"
    ADD CONSTRAINT "reunions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."role_has_permissions"
    ADD CONSTRAINT "role_has_permissions_pkey" PRIMARY KEY ("permission_id", "role_id");



ALTER TABLE ONLY "public"."roles"
    ADD CONSTRAINT "roles_name_guard_name_unique" UNIQUE ("name", "guard_name");



ALTER TABLE ONLY "public"."roles"
    ADD CONSTRAINT "roles_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."sacramento_requisito"
    ADD CONSTRAINT "sacramento_requisito_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."sacramento_requisito"
    ADD CONSTRAINT "sacramento_requisito_sacramento_id_requisito_id_unique" UNIQUE ("sacramento_id", "requisito_id");



ALTER TABLE ONLY "public"."sacramentos"
    ADD CONSTRAINT "sacramentos_parroquia_id_nombre_unique" UNIQUE ("parroquia_id", "nombre");



ALTER TABLE ONLY "public"."sacramentos"
    ADD CONSTRAINT "sacramentos_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."sessions"
    ADD CONSTRAINT "sessions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."tipo_apoderados"
    ADD CONSTRAINT "tipo_apoderados_parroquia_id_nombre_unique" UNIQUE ("parroquia_id", "nombre");



ALTER TABLE ONLY "public"."tipo_apoderados"
    ADD CONSTRAINT "tipo_apoderados_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."users"
    ADD CONSTRAINT "users_dni_unique" UNIQUE ("dni");



ALTER TABLE ONLY "public"."users"
    ADD CONSTRAINT "users_email_unique" UNIQUE ("email");



ALTER TABLE ONLY "public"."users"
    ADD CONSTRAINT "users_pkey" PRIMARY KEY ("id");



CREATE INDEX "apoderados_parroquia_id_index" ON "public"."apoderados" USING "btree" ("parroquia_id");



CREATE INDEX "asistencia_asistente_type_asistente_id_estado_index" ON "public"."asistencia" USING "btree" ("asistente_type", "asistente_id", "estado");



CREATE INDEX "asistencia_asistente_type_asistente_id_index" ON "public"."asistencia" USING "btree" ("asistente_type", "asistente_id");



CREATE INDEX "asistencia_estado_index" ON "public"."asistencia" USING "btree" ("estado");



CREATE INDEX "asistencia_reunion_id_estado_index" ON "public"."asistencia" USING "btree" ("reunion_id", "estado");



CREATE INDEX "asistencia_reunion_id_index" ON "public"."asistencia" USING "btree" ("reunion_id");



CREATE INDEX "confirmandos_estado_index" ON "public"."confirmandos" USING "btree" ("estado");



CREATE INDEX "confirmandos_grupo_id_estado_index" ON "public"."confirmandos" USING "btree" ("grupo_id", "estado");



CREATE INDEX "confirmandos_grupo_id_index" ON "public"."confirmandos" USING "btree" ("grupo_id");



CREATE INDEX "confirmandos_parroquia_id_index" ON "public"."confirmandos" USING "btree" ("parroquia_id");



CREATE INDEX "frontend_error_logs_parroquia_id_index" ON "public"."frontend_error_logs" USING "btree" ("parroquia_id");



CREATE INDEX "grupos_parroquia_id_index" ON "public"."grupos" USING "btree" ("parroquia_id");



CREATE INDEX "jobs_queue_index" ON "public"."jobs" USING "btree" ("queue");



CREATE INDEX "justificaciones_asistencia_id_estado_index" ON "public"."justificaciones" USING "btree" ("asistencia_id", "estado");



CREATE INDEX "justificaciones_asistencia_id_index" ON "public"."justificaciones" USING "btree" ("asistencia_id");



CREATE INDEX "justificaciones_estado_index" ON "public"."justificaciones" USING "btree" ("estado");



CREATE INDEX "model_has_permissions_model_id_model_type_index" ON "public"."model_has_permissions" USING "btree" ("model_id", "model_type");



CREATE INDEX "model_has_roles_model_id_model_type_index" ON "public"."model_has_roles" USING "btree" ("model_id", "model_type");



CREATE INDEX "oauth_access_tokens_user_id_index" ON "public"."oauth_access_tokens" USING "btree" ("user_id");



CREATE INDEX "oauth_auth_codes_user_id_index" ON "public"."oauth_auth_codes" USING "btree" ("user_id");



CREATE INDEX "oauth_clients_owner_type_owner_id_index" ON "public"."oauth_clients" USING "btree" ("owner_type", "owner_id");



CREATE INDEX "oauth_device_codes_client_id_index" ON "public"."oauth_device_codes" USING "btree" ("client_id");



CREATE INDEX "oauth_device_codes_user_id_index" ON "public"."oauth_device_codes" USING "btree" ("user_id");



CREATE INDEX "oauth_refresh_tokens_access_token_id_index" ON "public"."oauth_refresh_tokens" USING "btree" ("access_token_id");



CREATE INDEX "requisitos_parroquia_id_index" ON "public"."requisitos" USING "btree" ("parroquia_id");



CREATE INDEX "reunions_fecha_index" ON "public"."reunions" USING "btree" ("fecha");



CREATE INDEX "reunions_parroquia_id_index" ON "public"."reunions" USING "btree" ("parroquia_id");



CREATE INDEX "reunions_tipo_fecha_index" ON "public"."reunions" USING "btree" ("tipo", "fecha");



CREATE INDEX "sacramentos_clave_index" ON "public"."sacramentos" USING "btree" ("clave");



CREATE INDEX "sacramentos_parroquia_id_index" ON "public"."sacramentos" USING "btree" ("parroquia_id");



CREATE INDEX "sessions_last_activity_index" ON "public"."sessions" USING "btree" ("last_activity");



CREATE INDEX "sessions_user_id_index" ON "public"."sessions" USING "btree" ("user_id");



CREATE INDEX "tipo_apoderados_parroquia_id_index" ON "public"."tipo_apoderados" USING "btree" ("parroquia_id");



CREATE INDEX "users_parroquia_id_index" ON "public"."users" USING "btree" ("parroquia_id");



ALTER TABLE ONLY "public"."apoderados"
    ADD CONSTRAINT "apoderados_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."asistencia"
    ADD CONSTRAINT "asistencia_reunion_id_foreign" FOREIGN KEY ("reunion_id") REFERENCES "public"."reunions"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."catequista_grupo"
    ADD CONSTRAINT "catequista_grupo_grupo_id_foreign" FOREIGN KEY ("grupo_id") REFERENCES "public"."grupos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."catequista_grupo"
    ADD CONSTRAINT "catequista_grupo_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "public"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmando_apoderado"
    ADD CONSTRAINT "confirmando_apoderado_apoderado_id_foreign" FOREIGN KEY ("apoderado_id") REFERENCES "public"."apoderados"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmando_apoderado"
    ADD CONSTRAINT "confirmando_apoderado_confirmando_id_foreign" FOREIGN KEY ("confirmando_id") REFERENCES "public"."confirmandos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmando_apoderado"
    ADD CONSTRAINT "confirmando_apoderado_tipo_apoderado_id_foreign" FOREIGN KEY ("tipo_apoderado_id") REFERENCES "public"."tipo_apoderados"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmando_requisito"
    ADD CONSTRAINT "confirmando_requisito_confirmando_id_foreign" FOREIGN KEY ("confirmando_id") REFERENCES "public"."confirmandos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmando_requisito"
    ADD CONSTRAINT "confirmando_requisito_requisito_id_foreign" FOREIGN KEY ("requisito_id") REFERENCES "public"."requisitos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmando_sacramento"
    ADD CONSTRAINT "confirmando_sacramento_confirmando_id_foreign" FOREIGN KEY ("confirmando_id") REFERENCES "public"."confirmandos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmando_sacramento"
    ADD CONSTRAINT "confirmando_sacramento_sacramento_id_foreign" FOREIGN KEY ("sacramento_id") REFERENCES "public"."sacramentos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."confirmandos"
    ADD CONSTRAINT "confirmandos_grupo_id_foreign" FOREIGN KEY ("grupo_id") REFERENCES "public"."grupos"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."confirmandos"
    ADD CONSTRAINT "confirmandos_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."frontend_error_logs"
    ADD CONSTRAINT "frontend_error_logs_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."frontend_error_logs"
    ADD CONSTRAINT "frontend_error_logs_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "public"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."grupos"
    ADD CONSTRAINT "grupos_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."justificaciones"
    ADD CONSTRAINT "justificaciones_asistencia_id_foreign" FOREIGN KEY ("asistencia_id") REFERENCES "public"."asistencia"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."model_has_permissions"
    ADD CONSTRAINT "model_has_permissions_permission_id_foreign" FOREIGN KEY ("permission_id") REFERENCES "public"."permissions"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."model_has_roles"
    ADD CONSTRAINT "model_has_roles_role_id_foreign" FOREIGN KEY ("role_id") REFERENCES "public"."roles"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."parroquia_configuraciones"
    ADD CONSTRAINT "parroquia_configuraciones_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."requisitos"
    ADD CONSTRAINT "requisitos_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."reunion_user"
    ADD CONSTRAINT "reunion_user_reunion_id_foreign" FOREIGN KEY ("reunion_id") REFERENCES "public"."reunions"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."reunion_user"
    ADD CONSTRAINT "reunion_user_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "public"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."reunions"
    ADD CONSTRAINT "reunions_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."role_has_permissions"
    ADD CONSTRAINT "role_has_permissions_permission_id_foreign" FOREIGN KEY ("permission_id") REFERENCES "public"."permissions"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."role_has_permissions"
    ADD CONSTRAINT "role_has_permissions_role_id_foreign" FOREIGN KEY ("role_id") REFERENCES "public"."roles"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."sacramento_requisito"
    ADD CONSTRAINT "sacramento_requisito_requisito_id_foreign" FOREIGN KEY ("requisito_id") REFERENCES "public"."requisitos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."sacramento_requisito"
    ADD CONSTRAINT "sacramento_requisito_sacramento_id_foreign" FOREIGN KEY ("sacramento_id") REFERENCES "public"."sacramentos"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."sacramentos"
    ADD CONSTRAINT "sacramentos_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."tipo_apoderados"
    ADD CONSTRAINT "tipo_apoderados_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "public"."users"
    ADD CONSTRAINT "users_grupo_id_foreign" FOREIGN KEY ("grupo_id") REFERENCES "public"."grupos"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."users"
    ADD CONSTRAINT "users_parroquia_id_foreign" FOREIGN KEY ("parroquia_id") REFERENCES "public"."parroquias"("id") ON DELETE RESTRICT;



ALTER TABLE "public"."apoderados" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "apoderados_delete" ON "public"."apoderados" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "apoderados_insert" ON "public"."apoderados" FOR INSERT WITH CHECK ("public"."app_is_privileged"());



CREATE POLICY "apoderados_parroquia" ON "public"."apoderados" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



CREATE POLICY "apoderados_select" ON "public"."apoderados" FOR SELECT USING (("public"."app_is_privileged"() OR ("id" IN ( SELECT "ca"."apoderado_id"
   FROM ("public"."confirmando_apoderado" "ca"
     JOIN "public"."confirmandos" "c" ON (("c"."id" = "ca"."confirmando_id")))
  WHERE ("c"."grupo_id" IN ( SELECT "public"."app_user_grupo_ids"() AS "app_user_grupo_ids"))))));



CREATE POLICY "apoderados_update" ON "public"."apoderados" FOR UPDATE USING ("public"."app_is_privileged"()) WITH CHECK ("public"."app_is_privileged"());



ALTER TABLE "public"."asistencia" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "asistencia_delete" ON "public"."asistencia" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "asistencia_insert" ON "public"."asistencia" FOR INSERT WITH CHECK (("public"."app_is_privileged"() OR ((("asistente_type")::"text" = ANY ((ARRAY['App\Models\Confirmando'::character varying, 'App\Models\Apoderado'::character varying])::"text"[])) AND "public"."app_can_access_asistente"(("asistente_type")::"text", "asistente_id"))));



CREATE POLICY "asistencia_select" ON "public"."asistencia" FOR SELECT USING (("public"."app_is_privileged"() OR "public"."app_can_access_asistente"(("asistente_type")::"text", "asistente_id")));



CREATE POLICY "asistencia_update" ON "public"."asistencia" FOR UPDATE USING (("public"."app_is_privileged"() OR ((("asistente_type")::"text" = ANY ((ARRAY['App\Models\Confirmando'::character varying, 'App\Models\Apoderado'::character varying])::"text"[])) AND "public"."app_can_access_asistente"(("asistente_type")::"text", "asistente_id")))) WITH CHECK (("public"."app_is_privileged"() OR ((("asistente_type")::"text" = ANY ((ARRAY['App\Models\Confirmando'::character varying, 'App\Models\Apoderado'::character varying])::"text"[])) AND "public"."app_can_access_asistente"(("asistente_type")::"text", "asistente_id"))));



ALTER TABLE "public"."catequista_grupo" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "catequista_grupo_delete" ON "public"."catequista_grupo" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "catequista_grupo_insert" ON "public"."catequista_grupo" FOR INSERT WITH CHECK ("public"."app_is_privileged"());



CREATE POLICY "catequista_grupo_select" ON "public"."catequista_grupo" FOR SELECT USING (("public"."app_is_privileged"() OR ("user_id" = "public"."app_current_user_id"())));



CREATE POLICY "catequista_grupo_update" ON "public"."catequista_grupo" FOR UPDATE USING ("public"."app_is_privileged"()) WITH CHECK ("public"."app_is_privileged"());



ALTER TABLE "public"."confirmando_apoderado" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "confirmando_apoderado_delete" ON "public"."confirmando_apoderado" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "confirmando_apoderado_insert" ON "public"."confirmando_apoderado" FOR INSERT WITH CHECK ("public"."app_is_privileged"());



CREATE POLICY "confirmando_apoderado_select" ON "public"."confirmando_apoderado" FOR SELECT USING (("public"."app_is_privileged"() OR ("confirmando_id" IN ( SELECT "confirmandos"."id"
   FROM "public"."confirmandos"
  WHERE ("confirmandos"."grupo_id" IN ( SELECT "public"."app_user_grupo_ids"() AS "app_user_grupo_ids"))))));



CREATE POLICY "confirmando_apoderado_update" ON "public"."confirmando_apoderado" FOR UPDATE USING ("public"."app_is_privileged"()) WITH CHECK ("public"."app_is_privileged"());



ALTER TABLE "public"."confirmandos" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "confirmandos_delete" ON "public"."confirmandos" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "confirmandos_insert" ON "public"."confirmandos" FOR INSERT WITH CHECK ("public"."app_is_privileged"());



CREATE POLICY "confirmandos_parroquia" ON "public"."confirmandos" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



CREATE POLICY "confirmandos_select" ON "public"."confirmandos" FOR SELECT USING (("public"."app_is_privileged"() OR ("grupo_id" IN ( SELECT "public"."app_user_grupo_ids"() AS "app_user_grupo_ids"))));



CREATE POLICY "confirmandos_update" ON "public"."confirmandos" FOR UPDATE USING ("public"."app_is_privileged"()) WITH CHECK ("public"."app_is_privileged"());



ALTER TABLE "public"."frontend_error_logs" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "frontend_error_logs_all" ON "public"."frontend_error_logs" USING (true) WITH CHECK (true);



CREATE POLICY "frontend_error_logs_delete" ON "public"."frontend_error_logs" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "frontend_error_logs_insert" ON "public"."frontend_error_logs" FOR INSERT WITH CHECK (("public"."app_is_privileged"() OR ("user_id" = "public"."app_current_user_id"())));



CREATE POLICY "frontend_error_logs_parroquia" ON "public"."frontend_error_logs" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



CREATE POLICY "frontend_error_logs_select" ON "public"."frontend_error_logs" FOR SELECT USING ("public"."app_is_privileged"());



ALTER TABLE "public"."grupos" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "grupos_delete" ON "public"."grupos" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "grupos_insert" ON "public"."grupos" FOR INSERT WITH CHECK ("public"."app_is_privileged"());



CREATE POLICY "grupos_parroquia" ON "public"."grupos" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



CREATE POLICY "grupos_select" ON "public"."grupos" FOR SELECT USING (("public"."app_is_privileged"() OR ("id" IN ( SELECT "public"."app_user_grupo_ids"() AS "app_user_grupo_ids"))));



CREATE POLICY "grupos_update" ON "public"."grupos" FOR UPDATE USING ("public"."app_is_privileged"()) WITH CHECK ("public"."app_is_privileged"());



ALTER TABLE "public"."justificaciones" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "justificaciones_delete" ON "public"."justificaciones" FOR DELETE USING ("public"."app_is_privileged"());



CREATE POLICY "justificaciones_insert" ON "public"."justificaciones" FOR INSERT WITH CHECK (("public"."app_is_privileged"() OR (EXISTS ( SELECT 1
   FROM "public"."asistencia" "a"
  WHERE (("a"."id" = "justificaciones"."asistencia_id") AND "public"."app_can_access_asistente"(("a"."asistente_type")::"text", "a"."asistente_id"))))));



CREATE POLICY "justificaciones_select" ON "public"."justificaciones" FOR SELECT USING (("public"."app_is_privileged"() OR (EXISTS ( SELECT 1
   FROM "public"."asistencia" "a"
  WHERE (("a"."id" = "justificaciones"."asistencia_id") AND "public"."app_can_access_asistente"(("a"."asistente_type")::"text", "a"."asistente_id"))))));



CREATE POLICY "justificaciones_update" ON "public"."justificaciones" FOR UPDATE USING (("public"."app_is_privileged"() OR (EXISTS ( SELECT 1
   FROM "public"."asistencia" "a"
  WHERE (("a"."id" = "justificaciones"."asistencia_id") AND "public"."app_can_access_asistente"(("a"."asistente_type")::"text", "a"."asistente_id")))))) WITH CHECK (("public"."app_is_privileged"() OR (EXISTS ( SELECT 1
   FROM "public"."asistencia" "a"
  WHERE (("a"."id" = "justificaciones"."asistencia_id") AND "public"."app_can_access_asistente"(("a"."asistente_type")::"text", "a"."asistente_id"))))));



ALTER TABLE "public"."parroquia_configuraciones" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "parroquia_configuraciones_all" ON "public"."parroquia_configuraciones" USING (true) WITH CHECK (true);



CREATE POLICY "parroquia_configuraciones_parroquia" ON "public"."parroquia_configuraciones" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



ALTER TABLE "public"."requisitos" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "requisitos_all" ON "public"."requisitos" USING (true) WITH CHECK (true);



CREATE POLICY "requisitos_parroquia" ON "public"."requisitos" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



ALTER TABLE "public"."reunions" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "reunions_all" ON "public"."reunions" USING (true) WITH CHECK (true);



CREATE POLICY "reunions_parroquia" ON "public"."reunions" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



ALTER TABLE "public"."sacramentos" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "sacramentos_all" ON "public"."sacramentos" USING (true) WITH CHECK (true);



CREATE POLICY "sacramentos_parroquia" ON "public"."sacramentos" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



ALTER TABLE "public"."tipo_apoderados" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "tipo_apoderados_all" ON "public"."tipo_apoderados" USING (true) WITH CHECK (true);



CREATE POLICY "tipo_apoderados_parroquia" ON "public"."tipo_apoderados" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));



ALTER TABLE "public"."users" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "users_all" ON "public"."users" USING (true) WITH CHECK (true);



CREATE POLICY "users_parroquia" ON "public"."users" AS RESTRICTIVE USING ("public"."app_parroquia_ok"("parroquia_id")) WITH CHECK ("public"."app_parroquia_ok"("parroquia_id"));





ALTER PUBLICATION "supabase_realtime" OWNER TO "postgres";


GRANT USAGE ON SCHEMA "public" TO "postgres";
GRANT USAGE ON SCHEMA "public" TO "anon";
GRANT USAGE ON SCHEMA "public" TO "authenticated";
GRANT USAGE ON SCHEMA "public" TO "service_role";






















































































































































GRANT ALL ON FUNCTION "public"."app_can_access_asistente"("p_type" "text", "p_id" bigint) TO "anon";
GRANT ALL ON FUNCTION "public"."app_can_access_asistente"("p_type" "text", "p_id" bigint) TO "authenticated";
GRANT ALL ON FUNCTION "public"."app_can_access_asistente"("p_type" "text", "p_id" bigint) TO "service_role";



GRANT ALL ON FUNCTION "public"."app_current_parroquia_id"() TO "anon";
GRANT ALL ON FUNCTION "public"."app_current_parroquia_id"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."app_current_parroquia_id"() TO "service_role";



GRANT ALL ON FUNCTION "public"."app_current_user_id"() TO "anon";
GRANT ALL ON FUNCTION "public"."app_current_user_id"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."app_current_user_id"() TO "service_role";



GRANT ALL ON FUNCTION "public"."app_is_privileged"() TO "anon";
GRANT ALL ON FUNCTION "public"."app_is_privileged"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."app_is_privileged"() TO "service_role";



GRANT ALL ON FUNCTION "public"."app_parroquia_ok"("p_parroquia_id" bigint) TO "anon";
GRANT ALL ON FUNCTION "public"."app_parroquia_ok"("p_parroquia_id" bigint) TO "authenticated";
GRANT ALL ON FUNCTION "public"."app_parroquia_ok"("p_parroquia_id" bigint) TO "service_role";



GRANT ALL ON FUNCTION "public"."app_user_grupo_ids"() TO "anon";
GRANT ALL ON FUNCTION "public"."app_user_grupo_ids"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."app_user_grupo_ids"() TO "service_role";


















GRANT ALL ON TABLE "public"."apoderados" TO "anon";
GRANT ALL ON TABLE "public"."apoderados" TO "authenticated";
GRANT ALL ON TABLE "public"."apoderados" TO "service_role";



GRANT ALL ON SEQUENCE "public"."apoderados_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."apoderados_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."apoderados_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."asistencia" TO "anon";
GRANT ALL ON TABLE "public"."asistencia" TO "authenticated";
GRANT ALL ON TABLE "public"."asistencia" TO "service_role";



GRANT ALL ON SEQUENCE "public"."asistencia_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."asistencia_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."asistencia_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."cache" TO "anon";
GRANT ALL ON TABLE "public"."cache" TO "authenticated";
GRANT ALL ON TABLE "public"."cache" TO "service_role";



GRANT ALL ON TABLE "public"."cache_locks" TO "anon";
GRANT ALL ON TABLE "public"."cache_locks" TO "authenticated";
GRANT ALL ON TABLE "public"."cache_locks" TO "service_role";



GRANT ALL ON TABLE "public"."catequista_grupo" TO "anon";
GRANT ALL ON TABLE "public"."catequista_grupo" TO "authenticated";
GRANT ALL ON TABLE "public"."catequista_grupo" TO "service_role";



GRANT ALL ON SEQUENCE "public"."catequista_grupo_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."catequista_grupo_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."catequista_grupo_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."confirmando_apoderado" TO "anon";
GRANT ALL ON TABLE "public"."confirmando_apoderado" TO "authenticated";
GRANT ALL ON TABLE "public"."confirmando_apoderado" TO "service_role";



GRANT ALL ON SEQUENCE "public"."confirmando_apoderado_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."confirmando_apoderado_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."confirmando_apoderado_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."confirmando_requisito" TO "anon";
GRANT ALL ON TABLE "public"."confirmando_requisito" TO "authenticated";
GRANT ALL ON TABLE "public"."confirmando_requisito" TO "service_role";



GRANT ALL ON SEQUENCE "public"."confirmando_requisito_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."confirmando_requisito_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."confirmando_requisito_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."confirmando_sacramento" TO "anon";
GRANT ALL ON TABLE "public"."confirmando_sacramento" TO "authenticated";
GRANT ALL ON TABLE "public"."confirmando_sacramento" TO "service_role";



GRANT ALL ON SEQUENCE "public"."confirmando_sacramento_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."confirmando_sacramento_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."confirmando_sacramento_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."confirmandos" TO "anon";
GRANT ALL ON TABLE "public"."confirmandos" TO "authenticated";
GRANT ALL ON TABLE "public"."confirmandos" TO "service_role";



GRANT ALL ON SEQUENCE "public"."confirmandos_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."confirmandos_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."confirmandos_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."failed_jobs" TO "anon";
GRANT ALL ON TABLE "public"."failed_jobs" TO "authenticated";
GRANT ALL ON TABLE "public"."failed_jobs" TO "service_role";



GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."frontend_error_logs" TO "anon";
GRANT ALL ON TABLE "public"."frontend_error_logs" TO "authenticated";
GRANT ALL ON TABLE "public"."frontend_error_logs" TO "service_role";



GRANT ALL ON SEQUENCE "public"."frontend_error_logs_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."frontend_error_logs_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."frontend_error_logs_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."grupos" TO "anon";
GRANT ALL ON TABLE "public"."grupos" TO "authenticated";
GRANT ALL ON TABLE "public"."grupos" TO "service_role";



GRANT ALL ON SEQUENCE "public"."grupos_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."grupos_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."grupos_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."job_batches" TO "anon";
GRANT ALL ON TABLE "public"."job_batches" TO "authenticated";
GRANT ALL ON TABLE "public"."job_batches" TO "service_role";



GRANT ALL ON TABLE "public"."jobs" TO "anon";
GRANT ALL ON TABLE "public"."jobs" TO "authenticated";
GRANT ALL ON TABLE "public"."jobs" TO "service_role";



GRANT ALL ON SEQUENCE "public"."jobs_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."jobs_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."jobs_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."justificaciones" TO "anon";
GRANT ALL ON TABLE "public"."justificaciones" TO "authenticated";
GRANT ALL ON TABLE "public"."justificaciones" TO "service_role";



GRANT ALL ON SEQUENCE "public"."justificaciones_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."justificaciones_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."justificaciones_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."migrations" TO "anon";
GRANT ALL ON TABLE "public"."migrations" TO "authenticated";
GRANT ALL ON TABLE "public"."migrations" TO "service_role";



GRANT ALL ON SEQUENCE "public"."migrations_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."migrations_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."migrations_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."model_has_permissions" TO "anon";
GRANT ALL ON TABLE "public"."model_has_permissions" TO "authenticated";
GRANT ALL ON TABLE "public"."model_has_permissions" TO "service_role";



GRANT ALL ON TABLE "public"."model_has_roles" TO "anon";
GRANT ALL ON TABLE "public"."model_has_roles" TO "authenticated";
GRANT ALL ON TABLE "public"."model_has_roles" TO "service_role";



GRANT ALL ON TABLE "public"."oauth_access_tokens" TO "anon";
GRANT ALL ON TABLE "public"."oauth_access_tokens" TO "authenticated";
GRANT ALL ON TABLE "public"."oauth_access_tokens" TO "service_role";



GRANT ALL ON TABLE "public"."oauth_auth_codes" TO "anon";
GRANT ALL ON TABLE "public"."oauth_auth_codes" TO "authenticated";
GRANT ALL ON TABLE "public"."oauth_auth_codes" TO "service_role";



GRANT ALL ON TABLE "public"."oauth_clients" TO "anon";
GRANT ALL ON TABLE "public"."oauth_clients" TO "authenticated";
GRANT ALL ON TABLE "public"."oauth_clients" TO "service_role";



GRANT ALL ON TABLE "public"."oauth_device_codes" TO "anon";
GRANT ALL ON TABLE "public"."oauth_device_codes" TO "authenticated";
GRANT ALL ON TABLE "public"."oauth_device_codes" TO "service_role";



GRANT ALL ON TABLE "public"."oauth_refresh_tokens" TO "anon";
GRANT ALL ON TABLE "public"."oauth_refresh_tokens" TO "authenticated";
GRANT ALL ON TABLE "public"."oauth_refresh_tokens" TO "service_role";



GRANT ALL ON TABLE "public"."parroquia_configuraciones" TO "anon";
GRANT ALL ON TABLE "public"."parroquia_configuraciones" TO "authenticated";
GRANT ALL ON TABLE "public"."parroquia_configuraciones" TO "service_role";



GRANT ALL ON SEQUENCE "public"."parroquia_configuraciones_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."parroquia_configuraciones_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."parroquia_configuraciones_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."parroquias" TO "anon";
GRANT ALL ON TABLE "public"."parroquias" TO "authenticated";
GRANT ALL ON TABLE "public"."parroquias" TO "service_role";



GRANT ALL ON SEQUENCE "public"."parroquias_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."parroquias_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."parroquias_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."password_reset_tokens" TO "anon";
GRANT ALL ON TABLE "public"."password_reset_tokens" TO "authenticated";
GRANT ALL ON TABLE "public"."password_reset_tokens" TO "service_role";



GRANT ALL ON TABLE "public"."permissions" TO "anon";
GRANT ALL ON TABLE "public"."permissions" TO "authenticated";
GRANT ALL ON TABLE "public"."permissions" TO "service_role";



GRANT ALL ON SEQUENCE "public"."permissions_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."permissions_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."permissions_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."requisitos" TO "anon";
GRANT ALL ON TABLE "public"."requisitos" TO "authenticated";
GRANT ALL ON TABLE "public"."requisitos" TO "service_role";



GRANT ALL ON SEQUENCE "public"."requisitos_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."requisitos_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."requisitos_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."reunion_user" TO "anon";
GRANT ALL ON TABLE "public"."reunion_user" TO "authenticated";
GRANT ALL ON TABLE "public"."reunion_user" TO "service_role";



GRANT ALL ON SEQUENCE "public"."reunion_user_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."reunion_user_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."reunion_user_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."reunions" TO "anon";
GRANT ALL ON TABLE "public"."reunions" TO "authenticated";
GRANT ALL ON TABLE "public"."reunions" TO "service_role";



GRANT ALL ON SEQUENCE "public"."reunions_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."reunions_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."reunions_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."role_has_permissions" TO "anon";
GRANT ALL ON TABLE "public"."role_has_permissions" TO "authenticated";
GRANT ALL ON TABLE "public"."role_has_permissions" TO "service_role";



GRANT ALL ON TABLE "public"."roles" TO "anon";
GRANT ALL ON TABLE "public"."roles" TO "authenticated";
GRANT ALL ON TABLE "public"."roles" TO "service_role";



GRANT ALL ON SEQUENCE "public"."roles_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."roles_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."roles_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."sacramento_requisito" TO "anon";
GRANT ALL ON TABLE "public"."sacramento_requisito" TO "authenticated";
GRANT ALL ON TABLE "public"."sacramento_requisito" TO "service_role";



GRANT ALL ON SEQUENCE "public"."sacramento_requisito_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."sacramento_requisito_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."sacramento_requisito_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."sacramentos" TO "anon";
GRANT ALL ON TABLE "public"."sacramentos" TO "authenticated";
GRANT ALL ON TABLE "public"."sacramentos" TO "service_role";



GRANT ALL ON SEQUENCE "public"."sacramentos_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."sacramentos_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."sacramentos_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."sessions" TO "anon";
GRANT ALL ON TABLE "public"."sessions" TO "authenticated";
GRANT ALL ON TABLE "public"."sessions" TO "service_role";



GRANT ALL ON TABLE "public"."tipo_apoderados" TO "anon";
GRANT ALL ON TABLE "public"."tipo_apoderados" TO "authenticated";
GRANT ALL ON TABLE "public"."tipo_apoderados" TO "service_role";



GRANT ALL ON SEQUENCE "public"."tipo_apoderados_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."tipo_apoderados_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."tipo_apoderados_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."users" TO "anon";
GRANT ALL ON TABLE "public"."users" TO "authenticated";
GRANT ALL ON TABLE "public"."users" TO "service_role";



GRANT ALL ON SEQUENCE "public"."users_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."users_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."users_id_seq" TO "service_role";









ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "service_role";































