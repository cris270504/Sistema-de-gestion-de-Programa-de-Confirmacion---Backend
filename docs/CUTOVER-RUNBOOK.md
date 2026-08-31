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
        `SUPABASE_DB_URL` (URI del **Session pooler**). Correrlo a mano y verificar
        que sube el artifact `.dump`.  ✅ HECHO 2026-08-31 (verde).
  - [ ] Ídem `supabase-keepalive.yml` (secrets `SUPABASE_URL` + `SUPABASE_ANON_KEY`).
        ✅ HECHO 2026-08-31 (verde).
- [ ] **Edge Functions**: `supabase functions list` → deben estar las 5
      (`resolver-login`, `admin-usuarios`, `onboarding-parroquia`,
      `importar-confirmandos`, `exportar-confirmandos`).
- [ ] **Migraciones**: `php artisan migrate:status --env=staging` → 0 pendientes.

## Esquema origen ↔ destino — YA RECONCILIADO (2026-08-31)

Diagnóstico corrido contra el origen `hqvdkeijdgiejiekilhx`:

| | |
|---|---|
| Migraciones | 46 (hasta `2026_09_05_090000_add_activo_to_users_table`) |
| Multi-parroquia (`parroquias`, `parroquia_id`, `sacramentos.clave`, `grupos.periodo`) | ✅ presente |
| `users.auth_id` | ❌ no existe → lo crea `fase1_backfill_auth_users()` |
| RLS en tablas de dominio | sí (modelo viejo `set_config`) — irrelevante: se carga como `postgres` (BYPASSRLS) + `--disable-triggers` |
| Duplicados que romperían UNIQUE del destino (justificaciones/grupos/catequista_grupo) | 0 ✅ |
| Colores de grupo inválidos | 0 ✅ |
| **Celulares de confirmando que romperían el CHECK** (`^[0-9]{9}$`) | **3 filas** (ids 96, 110, 133 — basura tipo `"900 1"`) → NULL en la ventana |
| Data real | 18 users · 1 parroquia · 8 grupos · 125 confirmandos · 94 apoderados · 28 reunions · 1675 asistencias · 57 justificaciones · pivotes · 18 `model_has_roles` |

**No hace falta nivelar migraciones en el origen.** El esquema base es compatible;
la brecha son solo objetos que el destino agrega de más (funciones, vistas, RLS,
triggers, unique indexes) y no rechazan la data — salvo los 3 celulares.

## T-1 día — Ensayo (recomendado)

- [ ] Hacer el paso de datos completo contra el destino **con la data de prueba
      todavía dentro** para medir tiempos y cazar errores de FK/constraint.
      Después re-preparar el destino en la ventana. La data es chica: el
      `pg_dump` pesa pocos cientos de KB, todo el corte < 15 min.

---

## VENTANA DE MANTENIMIENTO (~1 h)

Entorno: cliente **PostgreSQL 17**; `ORIGEN` y `DESTINO` = URIs del **Session pooler**
de cada proyecto (`postgres.<ref>:<pass>@aws-0-<region>.pooler.supabase.com:5432/postgres`).

### 1. Congelar (T-0)

- [ ] Avisar a los usuarios (ya avisado con anticipación).
- [ ] Poner Laravel en mantenimiento: en Render, parar el servicio (o `php artisan down`).
      Desde acá nadie escribe en el origen.
- [ ] Backup completo fresco del **origen**:
  ```bash
  pg_dump "$ORIGEN" --no-owner --no-privileges -Fc \
    -f "origen-final-$(date -u +%Y%m%dT%H%M%SZ).dump"
  ```
- [ ] **Limpiar los 3 celulares basura** en el origen (antes del dump):
  ```sql
  UPDATE public.confirmandos SET celular = NULL WHERE id IN (96, 110, 133);
  -- verificar: 0 filas
  SELECT count(*) FROM public.confirmandos WHERE celular IS NOT NULL AND celular !~ '^[0-9]{9}$';
  ```

### 2. Limpiar el destino

- [ ] Correr `supabase-backup.yml` a mano (respaldo del destino con su data de prueba).
- [ ] Borrar la data de prueba del destino. Como `postgres` (BYPASSRLS):
  ```sql
  BEGIN;
  TRUNCATE
    public.asistencia, public.justificaciones,
    public.confirmando_apoderado, public.confirmando_sacramento,
    public.confirmando_requisito, public.sacramento_requisito,
    public.catequista_grupo, public.reunion_user,
    public.confirmandos, public.apoderados, public.reunions,
    public.grupos, public.sacramentos, public.requisitos, public.tipo_apoderados,
    public.frontend_error_logs,
    public.model_has_roles, public.model_has_permissions,
    public.role_has_permissions, public.permissions, public.roles,
    public.parroquia_configuraciones, public.users, public.parroquias
    RESTART IDENTITY CASCADE;
  COMMIT;
  ```
  (Se recargan roles/permissions/pivotes desde el origen para que quede
  todo autoconsistente — el código del destino chequea por NOMBRE, no por id.)
- [ ] Borrar los `auth.users` de prueba (los recrea el backfill):
  ```sql
  DELETE FROM auth.users;
  ```

### 3. Cargar la data del origen

- [ ] `pg_dump --data-only` del `public` del origen (sin infra ni `migrations`;
      `auth` del origen NO se toca — el destino usa Supabase Auth):
  ```bash
  pg_dump "$ORIGEN" --data-only --no-owner --no-privileges --schema=public \
    --exclude-table-data='public.migrations' \
    --exclude-table-data='public.cache' --exclude-table-data='public.cache_locks' \
    --exclude-table-data='public.jobs' --exclude-table-data='public.job_batches' \
    --exclude-table-data='public.failed_jobs' --exclude-table-data='public.sessions' \
    --exclude-table-data='public.password_reset_tokens' \
    --exclude-table-data='public.frontend_error_logs' \
    --exclude-table-data='public.oauth_access_tokens' \
    --exclude-table-data='public.oauth_auth_codes' \
    --exclude-table-data='public.oauth_refresh_tokens' \
    --exclude-table-data='public.oauth_clients' \
    --exclude-table-data='public.oauth_device_codes' \
    --exclude-table-data='public.oauth_personal_access_clients' \
    --exclude-table-data='public.personal_access_tokens' \
    -Fc -f data-origen.dump
  ```
- [ ] Restaurar en el destino como `postgres` (BYPASSRLS); `--disable-triggers`
      apaga las FKs y los triggers de `parroquia_id`/`updated_at` durante la carga:
  ```bash
  pg_restore --data-only --no-owner --no-privileges --disable-triggers \
    --exit-on-error -d "$DESTINO" data-origen.dump
  ```
  - Si algún `--exclude-table-data` apunta a una tabla que no existe en el
    origen, `pg_dump` lo ignora (sin `--strict-names`). OK.
- [ ] Resincronizar TODAS las secuencias (el `--data-only` no lo hace) — en el destino:
  ```sql
  DO $$
  DECLARE r record;
  BEGIN
    FOR r IN
      SELECT s.relname AS seq, t.relname AS tbl, a.attname AS col
      FROM pg_class s
      JOIN pg_depend d  ON d.objid = s.oid AND d.deptype = 'a' AND d.classid = 'pg_class'::regclass
      JOIN pg_class t   ON t.oid = d.refobjid
      JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
      WHERE s.relkind = 'S' AND t.relnamespace = 'public'::regnamespace
    LOOP
      EXECUTE format(
        'SELECT setval(%L, GREATEST(COALESCE((SELECT max(%I) FROM public.%I), 0), 1), (SELECT count(*) > 0 FROM public.%I))',
        r.seq, r.col, r.tbl, r.tbl);
    END LOOP;
  END $$;
  ```

### 4. Crear los auth.users reales

- [ ] En el destino:
  ```sql
  SELECT public.fase1_backfill_auth_users();   -- crea auth.users + identities reusando el hash bcrypt

  UPDATE auth.users SET
    confirmation_token = coalesce(confirmation_token, ''),
    recovery_token = coalesce(recovery_token, ''),
    email_change_token_new = coalesce(email_change_token_new, ''),
    email_change = coalesce(email_change, ''),
    email_change_token_current = coalesce(email_change_token_current, ''),
    phone_change = coalesce(phone_change, ''),
    phone_change_token = coalesce(phone_change_token, ''),
    reauthentication_token = coalesce(reauthentication_token, '');
  ```
- [ ] Verificar: `SELECT count(*) FROM auth.users;` == `SELECT count(*) FROM public.users;` (= 18)
- [ ] Verificar que existen los permisos que usa el código nuevo:
  ```sql
  SELECT unnest(ARRAY['ver roles','crear roles','editar roles','eliminar roles',
                      'administrar plataforma','crear confirmandos','ver confirmandos',
                      'ver todos los confirmandos','asignar catequista','asignar confirmandos'])
         EXCEPT SELECT name FROM public.permissions WHERE guard_name = 'api';
  ```
  Si devuelve filas, faltan permisos → `INSERT INTO public.permissions (name, guard_name, ...)`
  y asignarlos al rol que corresponda, o re-correr solo la parte de permisos del
  `RolePermissionUserSeeder`.

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
