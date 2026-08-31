# Runbook de cutover — Laravel/Render → solo Supabase

> Ejecutar de arriba hacia abajo. Los pasos **T-...** son de preparación (días
> antes); la **ventana** es el corte real (~1 h, big-bang). Ver contexto en
> `PLAN-MIGRACION-SUPABASE.md`.

**Origen (prod hoy):** proyecto Supabase que usa Laravel en Render.
El `.env` local apunta a `hqvdkeijdgiejiekilhx` (us-west-2) — **confirmar en la
config de Render que ese es realmente el de producción** antes de nada.

**Destino (prod nueva):** `srdccebxlslgomvxrfnu` (sa-east-1). Ya tiene todo el
esquema (Fases 0–5) + datos de prueba.

**Frontend:** `https://confirmacionscj.vercel.app` (Vercel).

> **`pg_dump` / `pg_restore`**: usar cliente **PostgreSQL 17** (el server es 17.x;
> un pg_dump 16 falla con "server version mismatch"). Y para conectarse desde un
> entorno **IPv4-only** (CI, algunos ISP) usar el **Session pooler** de Supabase
> (`aws-0-<region>.pooler.supabase.com:5432`, user `postgres.<ref>`), NO la
> conexión directa `db.<ref>.supabase.co` (IPv6-only). El pooler de transacción
> (`:6543`) no sirve para `pg_dump`.

---

## T-7 días — Preparar el destino

- [ ] **Renombrar** el proyecto `srdccebxlslgomvxrfnu` en el dashboard (quitar
      "staging"). No cambia el ref ni las URLs.
- [ ] **Rotar credenciales** que se compartieron en texto plano:
  - Access token `sbp_...`: Account → Access Tokens → revocar y crear otro.
  - DB password: Settings → Database → Reset database password. Actualizar
    `.env.staging` y el secret `SUPABASE_DB_URL` del backup (abajo).
- [ ] **CORS**: ya endurecido (`_shared/cors.ts` + secrets `ALLOWED_ORIGINS` /
      `ALLOWED_ORIGIN_PATTERNS`). Si el dominio del frontend será otro, actualizar
      el secret y **redeploy de las 5 funciones**.
- [ ] **Plan**: se queda en Free (decisión). Confirmar que las mitigaciones
      están activas:
  - [ ] Workflow `supabase-keepalive.yml` en el repo del frontend, con secrets
        `SUPABASE_URL` + `SUPABASE_ANON_KEY`. Correrlo a mano una vez
        (`workflow_dispatch`) y verificar verde.
  - [ ] Workflow `supabase-backup.yml` en el repo del frontend, con secret
        `SUPABASE_DB_URL` (URI de conexión directa). Correrlo a mano y verificar
        que sube el artifact `.dump`.
- [ ] **Edge Functions**: `supabase functions list` → deben estar las 5
      (`resolver-login`, `admin-usuarios`, `onboarding-parroquia`,
      `importar-confirmandos`, `exportar-confirmandos`).
- [ ] **Migraciones**: `php artisan migrate:status --env=staging` → 0 pendientes.

## T-3 días — Reconciliar esquema origen ↔ destino

El paso de datos es `pg_dump --data-only` (public + auth) del origen → cargar en
el destino. Para que las filas encajen, **el origen tiene que tener corridas
TODAS las migraciones Laravel** que agregan columnas NOT NULL en el destino, en
particular las multi-parroquia (`parroquia_id` en confirmandos/grupos/apoderados/
reunions, tablas `parroquias` / `parroquia_configuraciones`) y `users.auth_id`
(esta última puede faltar: la llena el backfill).

- [ ] Conectarse al **origen** y comparar:
  ```bash
  # cuántas migraciones corrió el origen
  psql "$ORIGEN_DB_URL" -c "select count(*) from migrations;"
  # vs las del repo
  ls database/migrations | wc -l   # 66
  psql "$ORIGEN_DB_URL" -c "\d public.confirmandos" | grep parroquia_id
  psql "$ORIGEN_DB_URL" -c "\d public.users" | grep auth_id
  ```
- [ ] Si el origen está atrasado: `php artisan migrate` **contra el origen**
      (con un `.env` apuntando ahí) para nivelarlo — SIN las migraciones
      `2026_09_*` de Supabase (esas son solo para el destino). Alternativa:
      correr solo las de esquema faltantes a mano.
- [ ] Anotar el conteo de filas de las tablas clave del origen para verificar
      después:
  ```bash
  for t in users parroquias grupos confirmandos apoderados reunions asistencia \
           justificaciones confirmando_sacramento confirmando_requisito \
           confirmando_apoderado catequista_grupo sacramentos requisitos \
           tipo_apoderados; do
    printf "%-26s " "$t"; psql "$ORIGEN_DB_URL" -tAc "select count(*) from $t";
  done
  ```

## T-1 día — Ensayo (opcional pero recomendado)

- [ ] Hacer el paso de datos completo contra el destino **con la data de prueba
      todavía dentro** para medir tiempos y detectar errores de FK/constraint.
      Después restaurar el destino a como estaba (o simplemente re-preparar en la
      ventana). Si el ensayo sale limpio, la ventana es trivial.

---

## VENTANA DE MANTENIMIENTO (~1 h)

### 1. Congelar (T-0)

- [ ] Avisar a los usuarios (ya avisado con anticipación).
- [ ] Poner Laravel en mantenimiento: en Render, `php artisan down` o parar el
      servicio. Desde acá nadie escribe en el origen.
- [ ] Backup fresco del **origen**:
  ```bash
  pg_dump "$ORIGEN_DB_URL" --no-owner --no-privileges -Fc \
    -f "origen-final-$(date -u +%Y%m%dT%H%M%SZ).dump"
  ```

### 2. Limpiar el destino

- [ ] Backup del destino por las dudas (o correr `supabase-backup.yml` a mano).
- [ ] Borrar **toda la data de prueba/spike** del destino. Las tablas de dominio
      tienen FKs; borrar en orden hijo→padre. Como `postgres` (bypass RLS):
  ```sql
  BEGIN;
  TRUNCATE
    public.asistencia, public.justificaciones,
    public.confirmando_apoderado, public.confirmando_sacramento,
    public.confirmando_requisito, public.sacramento_requisito,
    public.catequista_grupo, public.reunion_user,
    public.confirmandos, public.apoderados, public.reunions,
    public.grupos, public.sacramentos, public.requisitos,
    public.tipo_apoderados, public.frontend_error_logs
    RESTART IDENTITY CASCADE;
  DELETE FROM public.model_has_roles WHERE model_type = 'App\Models\User';
  DELETE FROM public.model_has_permissions WHERE model_type = 'App\Models\User';
  -- users de prueba (dejar solo los que se van a re-crear del origen)
  DELETE FROM public.users;
  DELETE FROM public.parroquia_configuraciones;
  DELETE FROM public.parroquias;
  COMMIT;
  ```
  - [ ] Borrar los `auth.users` de prueba (los recrea el backfill):
    ```sql
    DELETE FROM auth.users;   -- en el destino, antes de cargar
    ```
  - ⚠️ NO tocar `public.roles` / `public.permissions` / `public.role_has_permissions`
    (catálogo del sistema, ya sembrado y correcto).

### 3. Cargar la data del origen

- [ ] `pg_dump` data-only del origen (solo `public`; `auth` del origen NO sirve —
      el destino usa Supabase Auth):
  ```bash
  pg_dump "$ORIGEN_DB_URL" --data-only --no-owner --no-privileges \
    --schema=public \
    --exclude-table='public.migrations' \
    --exclude-table='public.roles' --exclude-table='public.permissions' \
    --exclude-table='public.role_has_permissions' \
    --exclude-table-data='public.cache' --exclude-table-data='public.cache_locks' \
    --exclude-table-data='public.jobs' --exclude-table-data='public.job_batches' \
    --exclude-table-data='public.failed_jobs' --exclude-table-data='public.sessions' \
    --exclude-table-data='public.password_reset_tokens' \
    --exclude-table-data='public.oauth_access_tokens' \
    --exclude-table-data='public.oauth_auth_codes' \
    --exclude-table-data='public.oauth_refresh_tokens' \
    -Fc -f data-origen.dump
  ```
- [ ] Restaurar en el destino (como `postgres`, que hace bypass RLS). `--disable-triggers`
      evita que se dispare la RLS y los triggers de `parroquia_id` durante la carga:
  ```bash
  pg_restore --data-only --no-owner --no-privileges --disable-triggers \
    -d "$DESTINO_DB_URL" data-origen.dump
  ```
  - Si el orden de tablas da errores de FK, agregar `--single-transaction` +
    revisar; o restaurar tabla por tabla en orden padre→hijo.
- [ ] Resincronizar las secuencias (el data-only no lo hace):
  ```sql
  SELECT setval(pg_get_serial_sequence(quote_ident(t), 'id'),
                COALESCE((SELECT max(id) FROM ONLY public.%I), 1))
  -- más simple: por cada tabla con id serial:
  --   SELECT setval('public.confirmandos_id_seq', (SELECT max(id) FROM public.confirmandos));
  ```
  (o correr el bloque `resync_secuencias` que ya existe en las migraciones).

### 4. Crear los auth.users reales

- [ ] En el destino:
  ```sql
  SELECT public.fase1_backfill_auth_users();   -- crea auth.users + identities reusando el hash bcrypt
  UPDATE auth.users SET
    confirmation_token = '', recovery_token = '', email_change_token_new = '',
    email_change = '', email_change_token_current = '', phone_change = '',
    phone_change_token = '', reauthentication_token = ''
  WHERE confirmation_token IS NULL OR recovery_token IS NULL;   -- GoTrue: deben ser '' no NULL
  ```
- [ ] Verificar: `SELECT count(*) FROM auth.users;` == `SELECT count(*) FROM public.users;`

### 5. Verificar

- [ ] Conteos de filas destino == los anotados en T-3 (menos las tablas excluidas).
- [ ] Con `curl` / navegador contra el destino:
  - [ ] `resolver-login` con un DNI real → devuelve el correo canónico.
  - [ ] Login real (DNI y correo) → token con claims (`parroquia_id`, `roles`, `permisos`).
  - [ ] `fn_get_user` → perfil completo.
  - [ ] Dashboard: `v_dashboard_*` con números.
  - [ ] Aislamiento: un catequista solo ve su grupo; un coordinador toda la parroquia.
  - [ ] Una escritura (guardar asistencia de una reunión) vía RPC.
  - [ ] `exportar-confirmandos` → xlsx válido; `importar-confirmandos` con un CSV chico.

### 6. Switch del frontend

- [ ] En Vercel (proyecto `confirmacionscj`), setear las env vars de producción:
  ```
  VITE_SUPABASE_URL=https://srdccebxlslgomvxrfnu.supabase.co
  VITE_SUPABASE_ANON_KEY=<anon key del destino>
  ```
  (o commitear los valores en `.env.production` y push — Vercel redeploya).
- [ ] Redeploy del frontend. Verificar en `https://confirmacionscj.vercel.app`:
  login real → dashboard → una acción de escritura.
- [ ] Los usuarios con token viejo re-inician sesión una vez (impacto nulo).

### 7. Apagar Laravel

- [ ] Confirmado que el frontend anda 100% contra Supabase → **parar el servicio
      de Render** (o borrarlo).
- [ ] Borrar/parar el pinger viejo (`.github/workflows/keep-alive.yml` del repo
      backend) — al archivar el repo deja de correr igual.
- [ ] Archivar el repo `Sistema-de-gestion-...-Backend` en GitHub (Settings →
      Archive). Copiar antes este runbook y `PLAN-MIGRACION-SUPABASE.md` a donde
      queden accesibles.
- [ ] Actualizar `docs/ARQUITECTURA.md` (nueva arquitectura sin backend propio).
- [ ] Dar de baja el proyecto Supabase **origen** (`hqvdkeijdgiejiekilhx`) una
      vez que el destino lleve unos días estable — no antes.

---

## Rollback

Mientras Render siga vivo y el origen intacto:

- [ ] En Vercel, borrar `VITE_SUPABASE_URL` / `VITE_SUPABASE_ANON_KEY` (o volver a
      `VITE_API_URL` de Render) y redeploy → el frontend vuelve a Laravel.
- [ ] `php artisan up` en Render.

Punto de no retorno: cuando se apaga Render **y** se le empezó a escribir data
nueva al destino. A partir de ahí, rollback = restaurar el `origen-final-*.dump`
y perder lo que se haya cargado después.
