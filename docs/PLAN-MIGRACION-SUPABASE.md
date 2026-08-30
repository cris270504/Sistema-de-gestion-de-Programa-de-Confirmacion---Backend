# Plan de migración: Laravel/Render → solo Supabase

> Estado: PROPUESTA (2026-08-30). Requiere aprobar las decisiones abiertas de la §6
> antes de empezar. Mientras dure esta migración se **pausa** el plan multi-parroquia
> (`PLAN-MULTITENANT.md`) — las Partes 0–6 ya hechas se conservan y se portan.

## 1. Objetivo y motivación

- **Eliminar Render.** Motivo declarado: cold starts del plan free (cuelga 30–60 s y
  responde 200 → el retry no ayuda) y no querer depender de otro proveedor.
- **Destino:** el frontend Vue (Vercel) habla **directo con Supabase**. No hay servidor
  de aplicación propio que hospedar ni mantener.

## 2. A dónde va cada pieza

| Hoy (Laravel en Render) | Mañana (Supabase) |
|---|---|
| Passport (access tokens OAuth) | **Supabase Auth (GoTrue)** — sesión JWT, refresh automático |
| `PassportAuthController::login` (correo **o** DNI) | `signInWithPassword` + email sintético para quienes solo tienen DNI (§6.2) |
| Forgot/reset password (mailer Laravel) | Auth nativo de Supabase (**ganancia**: ya no hace falta mailer propio) |
| Spatie: `permission:<x>` por ruta | **Custom Access Token Hook** mete `parroquia_id` + `roles` + `permissions` en el JWT; RLS y funciones los leen. Las tablas `roles/permissions/model_has_*` se conservan |
| `ResolveTenant` + `ParroquiaScope` (Eloquent) + `SetPostgresRlsContext` (`set_config`) | **Se eliminan los 3.** La RLS lee `auth.jwt()`. Desaparece la atadura al session pooler |
| GET de listas y detalle (confirmandos, grupos, reuniones, catálogos, matriz, dashboard) | **PostgREST** sobre tablas + **vistas** SQL. Frontend: `supabase.from(...).select()` |
| Escrituras transaccionales (asistencia masiva, cascada sacramental, workflow justificaciones, reparto equitativo, onboarding) | **Funciones Postgres (RPC)** `supabase.rpc(...)` |
| Import/Export Excel (`maatwebsite/excel`) | **Edge Function** (Deno) con librería `xlsx` |
| Alta/edición/baja de usuarios (crea filas en `users`) | **Edge Function** con Auth Admin API (crea `auth.users`) |
| `throttle:` por ruta | Rate limits nativos de GoTrue en auth + Cloudflare/limitador manual en Edge Functions (**pérdida parcial**, §7) |
| 47 tests Pest | **pgTAP** (RLS + funciones) + **Deno test** (Edge Functions) |
| Migraciones `php artisan migrate` desde local | **Supabase CLI** (`supabase migration new` / `db push`), stack local con `supabase start` |
| Caché de alertas (5 min, store `array`) | Recalcular on-read (≤300 confirmandos es barato) o vista materializada con refresh por trigger |
| `X-Frame-Options`, `nosniff`, etc. (middleware `SecurityHeaders`) | Cabeceras en Edge Functions + config del proyecto; el resto lo sirve Vercel |

## 3. Arquitectura destino

```
Vue (Vercel) ── @supabase/supabase-js ──┬─ auth            → GoTrue
                                        ├─ from().select() → PostgREST + RLS  (lecturas / CRUD simple)
                                        ├─ rpc()           → funciones plpgsql (escrituras transaccionales)
                                        └─ functions.invoke→ Edge Functions   (Excel, admin usuarios, onboarding)
                                                                   │
                                        Postgres (Supabase) ◄──────┘
                                          - RLS en todas las tablas, lee auth.jwt()
                                          - Custom Access Token Hook (claims: parroquia_id, roles, permissions)
                                          - tablas Spatie conservadas
```

### 3.1 Identidad de usuario (decisión clave)

`auth.users.id` es UUID; hoy todo apunta a `users.id` (bigint): `catequista_grupo`,
`reunion_user`, `asistencia` polimórfica (`asistente_type = App\Models\User`), Spatie
`model_has_roles.model_id`, FKs varias.

**Recomendado (menos disruptivo):** conservar `public.users` con su PK bigint y
añadir `auth_id uuid UNIQUE REFERENCES auth.users(id)`. El hook y la RLS resuelven la
fila de `public.users` desde `auth.uid()`:

```sql
create function app.current_user_id() returns bigint language sql stable as $$
  select id from public.users where auth_id = auth.uid()
$$;
```

Backfill: por cada fila de `public.users`, crear su `auth.users` (Admin API) y enlazar
`auth_id`. Ninguna FK existente cambia.

### 3.2 Claims en el JWT (Custom Access Token Hook)

Función `public.custom_access_token_hook(event jsonb)` que en cada emisión/refresh
añade a `app_metadata`:

- `parroquia_id` (de `public.users`)
- `es_proveedor` (bool, rol global)
- `roles` (array de nombres, de Spatie)
- `permisos` (array de nombres, de Spatie — el frontend ya consume esta lista)

La RLS y las funciones leen p. ej.
`(auth.jwt() -> 'app_metadata' ->> 'parroquia_id')::bigint`.

### 3.3 RLS: de `set_config` a claims

Se reescriben los helpers de las 2 migraciones RLS actuales:

| Hoy | Mañana |
|---|---|
| `app_current_user_id()` ← `current_setting('app.current_user_id')` | ← `public.users.auth_id = auth.uid()` |
| `app_is_privileged()` ← `current_setting(...)` | ← `'coordinador'`/`'super-admin'`/`'proveedor'` en el claim `roles` |
| `app_current_parroquia_id()` ← `current_setting(...)` | ← claim `parroquia_id` |
| `app_user_grupo_ids()` (subconsulta a `catequista_grupo`) | igual, pero parte de `app_current_user_id()` nuevo |

Las políticas permisivas (alcance por grupo) y restrictivas (`*_parroquia`) se mantienen
en forma; solo cambia de dónde sale el contexto. El `proveedor` (sin filtro de
parroquia) → `app_parroquia_ok()` devuelve `true` si el claim `es_proveedor`.

**Ventaja:** el contexto viaja firmado en el token, no en una variable de sesión →
sirve con cualquier pooler y con conexiones efímeras de PostgREST/Edge Functions.

## 4. Inventario de lógica a portar

### 4.1 Vistas (lectura) — PostgREST
- `v_confirmando_perfil` ← `ConfirmandoController::obtenerPerfilCompleto` (deriva sacramentos pendientes del pivot).
- `v_asistencia_matriz` ← `AsistenciaController::matrix` (persona × reunión).
- `v_dashboard_metricas` ← conteos por estado (`CASE`, 1 query).
- `v_dashboard_alertas` ← `DashboardController` cálculo de rachas/umbrales (window functions; umbrales de `parroquia_configuraciones`).

### 4.2 Funciones RPC (escritura transaccional) — plpgsql `SECURITY INVOKER`
- `fn_guardar_asistencias(reunion_id bigint, filas jsonb)` — SELECT + INSERT masivo + UPDATE (hoy sin índice único en `(reunion_id,asistente_id,asistente_type)` — añadirlo y usar `on conflict`).
- `fn_asignar_ruta_sacramental(confirmando_id bigint, sacramento_faltante_id bigint)` — cascada, conserva avance.
- `fn_justificacion_acuerdo(...)`, `fn_justificacion_completar(...)`, `fn_justificacion_rechazar(...)` — tocan `asistencia` + `justificaciones` juntas.
- `fn_generar_grupos_equitativo(periodo, ...)` — round-robin por género (viable en plpgsql; si se complica, Edge Function).
- CRUD simple (grupos, reuniones, catálogos, un confirmando suelto) → PostgREST directo con RLS + `CHECK`/triggers de validación.

### 4.3 Edge Functions (Deno) — necesitan runtime / libs / Admin API
- `importar-confirmandos` — parseo Excel, split de nombre completo, validación celular 9 díg, `strip_tags` → INSERT masivo (service role o RPC).
- `exportar-confirmandos` — genera `.xlsx` agrupado por grupo.
- `admin-usuarios` — alta/edición/baja: crea/actualiza `auth.users` + `public.users` + roles.
- `onboarding-parroquia` — crea parroquia + `parroquia_configuraciones` + primer admin + catálogo sacramental (`SembrarCatalogoSacramental` portado a SQL).
- `resolver-login` — recibe el identificador tecleado (correo **o** DNI) y devuelve el
  correo canónico de `auth.users`. El frontend luego llama `signInWithPassword` normal
  (la contraseña nunca pasa por aquí). Para inputs desconocidos devuelve un correo
  sintético inexistente → el login falla igual, sin revelar si el identificador existe.
  Rate limit propio. Resuelve la decisión §6.2: **todos** pueden entrar con DNI o correo.
- Todas: cabeceras CORS + de seguridad, verificación del JWT del llamador y de sus permisos.

### 4.4 Validación / sanitización (exige CLAUDE.md)
PostgREST no valida reglas de negocio. Por cada tabla con escritura directa: `CHECK`
constraints, tipos/`DOMAIN`, y triggers `BEFORE INSERT/UPDATE` para lo no expresable en
`CHECK`. Lo que no quepa → esa escritura pasa por RPC.

## 5. Fases (ejecutar y verificar una a una; commit + checkpoint entre cada una)

- **Fase 0 — Cimientos y prueba de concepto** ✅ HECHA (2026-08-30)
  `supabase init`; stack local (Docker); baseline del esquema en
  `supabase/migrations/00000000000000_baseline_laravel.sql` (46 migraciones Laravel
  corridas contra Postgres 17 local). **Spike del Custom Access Token Hook + RLS por
  claims** en `supabase/spike/` — VERDE: `parroquia_id`/roles/permisos viajan en el
  JWT, la RLS de `confirmandos` aísla por parroquia y por grupo del catequista leyendo
  `auth.jwt()`, sin `set_config`. Ver `supabase/spike/README.md`.
  Aprendizajes → Fase 2: (a) `anon`/`authenticated` tienen grants sobre TODO `public`
  por defecto → RLS obligatoria en todas las tablas; (b) toda tabla consultada dentro
  de una política debe migrarse a claims a la vez; (c) `parroquia_configuraciones`
  tiene NOT NULL sin default; (d) `DatabaseSeeder` con `WithoutModelEvents` rompe el
  hook de `BelongsToParroquia`.

- **Fase 1 — Auth** ✅ HECHA en local (2026-08-30). Ramas `feat/migracion-supabase-fase-1`
  en ambos repos. **Falta el cutover de auth en producción** (ver §8).
  - Backfill `supabase/migrations/20260830200000_fase1_backfill_auth.sql`: `auth.users`
    + `auth.identities` por cada `public.users`, reusando el hash bcrypt de Laravel
    (`$2y$`→`$2a$`). Idempotente. Nota: las columnas de token de `auth.users` deben
    quedar en `''` (no NULL) o GoTrue tira "Database error querying schema".
  - Edge Function `resolver-login`: identificador tecleado (correo O DNI) → correo
    canónico; anti-enumeración con correo sintético inerte.
  - **Supabase firma los JWT con ES256 (claves asimétricas) + JWKS**, no HS256. El
    guard de Laravel valida contra `{SUPABASE_URL}/auth/v1/.well-known/jwks.json`.
  - Guard Laravel `supabase` (`App\Auth\SupabaseTokenGuard`, vía `Auth::viaRequest`).
    `routes/api.php` → `auth:supabase,api` (Passport queda de 2º guard: rollback + tests).
  - Migración Laravel `add_auth_id_to_users_table` (para tests y entornos Laravel).
  - Frontend: `@supabase/supabase-js`, `src/lib/supabase.js`, `stores/auth.js`
    (resolver-login → signInWithPassword → hidrata de `/get-user`), `lib/api.js`
    (token de la sesión de Supabase), `App.vue` (getSession + onAuthStateChange).
  - Verificado end-to-end en navegador: login por DNI → dashboard con datos, sesión
    persistida tras reload, aislamiento por parroquia/grupo intacto. Backend 68 verde,
    frontend 52 verde.

- **Fase 2 — RLS como único guardián de tenant**
  Reescribir helpers y verificar TODAS las políticas contra claims. pgTAP portando
  `TenantIsolationTest`, `ScopePorGrupoTest`, `JustificacionScopeTest`,
  `RolesDosNivelesTest`.

- **Fase 3 — Lecturas → PostgREST + vistas**
  Crear vistas §4.1. Capa `services/` del frontend: cada GET → `from()`/`rpc()`.

- **Fase 4 — Escrituras → RPC + constraints**
  Funciones §4.2 + validación §4.4. Migrar CRUD simple a PostgREST directo.

- **Fase 5 — Edge Functions**
  §4.3. CORS, cabeceras de seguridad, limitador.

- **Fase 6 — Cutover y baja de Laravel**
  Ventana de mantenimiento (≤300 confirmandos, 1 parroquia → big-bang viable):
  sync final de datos → cambiar env del frontend a solo-Supabase → apagar Render →
  archivar repo Laravel (referencia) → actualizar `ARQUITECTURA.md`. Todos los usuarios
  re-inician sesión una vez (ya hay sesión única por usuario, impacto nulo).

## 6. Decisiones (cerradas 2026-08-30)

1. **Identidad:** ✅ conservar `public.users` (PK bigint) + `auth_id uuid`. No toca ninguna FK.
2. **Login por DNI o correo:** ✅ se conserva el comportamiento actual (un campo, cualquiera
   de los dos identificadores). Implementación: Edge Function `resolver-login` (§4.3)
   traduce el identificador tecleado → correo de `auth.users`; luego `signInWithPassword`.
3. **Cutover:** ✅ **big-bang** con ventana de mantenimiento anunciada (p. ej. domingo de
   noche). Se congela la app ~1 h, se copia el estado final de datos, se apunta el
   frontend a solo-Supabase, se prueba, se apaga Render. Rollback = re-apuntar el
   frontend a Render. NO se hace doble escritura (complejidad injustificada para 1
   parroquia / ≤300 confirmandos).
4. **Rate limiting:** ✅ límites nativos de GoTrue para auth (configurables en el panel);
   `resolver-login` y las Edge Functions sensibles llevan un contador simple en Postgres;
   Cloudflare delante de Vercel queda como opción futura. No se replican todos los
   `throttle:` actuales uno a uno.

## 7bis. Cutover de auth (poner la Fase 1 en producción)

La Fase 1 ya funciona en local; para activarla en producción hace falta un cambio
en el proyecto Supabase real + un mini-cutover (todos re-inician sesión una vez):

1. **Proyecto Supabase de producción** (idealmente primero un proyecto *staging*):
   - Habilitar Auth. Aplicar migraciones `20260830190000` (hook) y — si se hace
     RLS por claims ya — `20260830190100`.
   - Desplegar la Edge Function `resolver-login` (`supabase functions deploy`).
   - Registrar el hook: panel → Authentication → Hooks → Custom Access Token →
     `custom_access_token_hook` (o `config.toml` + `supabase db push` si el proyecto
     se gestiona por CLI).
   - Correr el backfill: `select public.fase1_backfill_auth_users();` **tras un backup**.
     Verificar con un par de logins de prueba.
2. **Backend (Render, mientras siga vivo)**: setear `SUPABASE_URL` y — solo si se usa
   el fallback HS256 — `SUPABASE_JWT_SECRET`. Desplegar la rama de Fase 1. El guard
   `auth:supabase,api` acepta ambos tokens, así que no hay ventana de corte dura.
3. **Frontend (Vercel)**: setear `VITE_SUPABASE_URL` + `VITE_SUPABASE_ANON_KEY`,
   desplegar la rama de Fase 1.
4. Los usuarios con token Passport viejo en `localStorage` re-inician sesión una vez.
5. **Rollback**: revertir el deploy del frontend (vuelve a `/api/login` de Passport).

Recomendado: hacer esto contra un proyecto Supabase **staging** primero, y recién
después contra el de producción.

## 7. Qué se pierde / riesgos

- **Rate limiting granular** (`throttle:5,1` en login, `20,1` en logs, etc.): GoTrue trae
  límites en `/token` y `/recover`; para Edge Functions hay que construirlo.
- **Red de tests:** los 47 Pest dejan de aplicar hasta reconstruir pgTAP + Deno test.
- **DX:** se va Eloquent, Debugbar, Pint, factories. Entra SQL + Deno/TS.
- **Excel:** lógica reescrita con librerías distintas (Deno `xlsx`), revalidar casos borde.
- **ETag/304 manual** de `GET /confirmandos`: PostgREST tiene su propio modelo de caché;
  hay que medir si hace falta algo extra.
- **Cold start de Edge Functions:** existe (~cientos de ms), muy inferior al de Render,
  pero no es cero. Las lecturas por PostgREST no lo tienen.
- **Curva de aprendizaje** del equipo (hoy PHP/Laravel).

## 8. Esfuerzo estimado

Varias semanas de trabajo enfocado. Fase 0–2 son el grueso del riesgo (auth + RLS por
claims); 3–5 son volumen mecánico; 6 es una tarde con ventana de mantenimiento.
