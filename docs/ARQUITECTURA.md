# Arquitectura del Sistema de Confirmación

> Estado a 2026-08-27. Documento vivo: actualizar cuando cambie algo estructural.

## 1. Visión general

Sistema de gestión del programa de Confirmación de una parroquia: catequistas, grupos
de catequesis, confirmandos, apoderados, cronograma de reuniones, asistencia,
justificación de faltas y ruta sacramental (Bautismo → Primera Comunión → Confirmación).

Dos repositorios independientes:

| Capa | Repo | Stack | Deploy |
|------|------|-------|--------|
| Backend (API REST) | `sistemaConfirmacionApi` | Laravel 12, PHP 8.2, Passport (OAuth2), Spatie Permission | Docker en Render |
| Frontend (SPA) | `sistemaConfirmacion` | Vue 3 + Vite, Pinia, vue-router, Bootstrap 5 + Tailwind 4 | Vercel |
| Base de datos | — | PostgreSQL con Row Level Security | Supabase (pooler) |

El frontend en producción llama directo a la API de Render. En desarrollo usa el proxy
de Vite (`/api` → Render). CORS se controla por `CORS_ALLOWED_ORIGINS` (ver `.env.example`).

## 2. Autenticación y autorización

- **Login**: `POST /api/login` con `{ login, password }`. `login` acepta **correo o DNI**
  (se detecta con `filter_var(FILTER_VALIDATE_EMAIL)`). Rate limit `throttle:5,1`.
- **Tokens**: Passport personal access tokens (Bearer). El frontend guarda el token en
  `localStorage` y valida su expiración decodificando el `exp` del JWT.
- **Recuperación de contraseña**: `POST /api/forgot-password` + `POST /api/reset-password`
  (flujo estándar de Laravel, con `throttle`). El link del correo apunta a
  `APP_FRONTEND_URL/reset-password/{token}`.
- **Roles** (Spatie, guard `api`): `super-admin`, `coordinador`, `catequista`.
  Los permisos están en `RolePermissionUserSeeder`.
- **Rutas protegidas**: `auth:api` + middleware `permission:<nombre>` por endpoint
  (`routes/api.php`).

### Alcance de datos (multi-nivel)

0. **Parroquia (tenant)** — desde la Fase A del plan multi-parroquia. Cada tabla raíz
   lleva `parroquia_id` (FK NOT NULL). `App\Tenancy\TenantContext` (singleton) guarda
   la parroquia del request; el middleware `ResolveTenant` la fija desde el usuario
   autenticado. El trait `App\Tenancy\Concerns\BelongsToParroquia` añade un Global
   Scope de Eloquent que filtra por esa parroquia y setea `parroquia_id` al crear
   (desde el contexto, nunca del request). `User` no lleva el Global Scope (rompería
   la resolución de login); se filtra explícito con `->parroquiaActual()`.
   En CLI el contexto se marca privilegiado (sin filtro); los seeders acotan con
   `Tenant::set()` / `Tenant::runFor()`.
1. **Filtro en PHP por grupo**: cada controlador que devuelve listas restringe por
   `user->grupos` cuando el usuario no es coordinador/super-admin.
2. **Row Level Security (Postgres)**: última línea de defensa. El middleware
   `SetPostgresRlsContext` fija por request `app.current_user_id` y
   `app.current_user_privileged`; las políticas de `grupos`, `catequista_grupo`,
   `confirmandos`, `apoderados`, `confirmando_apoderado` y `asistencia` filtran por
   los grupos del catequista. Coordinador/super-admin = privilegiado (ve todo).
   Ver migración `2026_08_11_120000_enable_row_level_security`.
   - CLI (artisan/queue/tinker) se marca privilegiada en `AppServiceProvider::boot()`.
   - Solo aplica sobre `pgsql`; los tests corren en sqlite.

## 3. Modelo de datos

```
User ──< catequista_grupo >── Grupo ──< Confirmando
 │                              │           │
 │ reunion_user                 │           ├──< confirmando_apoderado >── Apoderado ──> TipoApoderado (pivote)
 ▼                              │           ├──< confirmando_sacramento >── Sacramento ──< sacramento_requisito >── Requisito
Reunion ──< Asistencia          │           └──< confirmando_requisito >── Requisito
           (polimórfica:        │
            Confirmando |       │
            Apoderado |         │
            User)               │
             │                  │
             └──> Justificacion (1:1 con la asistencia corregida)
```

Enums / estados clave:

- `Confirmando.estado`: `en_preparacion` · `retirado` · `confirmado`
- `Reunion.tipo`: `Catequistas` · `Confirmandos` · `Apoderados`
- `Asistencia.estado`: `asistio` · `tardanza` · `falta justificada` · `falta injustificada`
- `Justificacion.estado`: `injustificado` → `pendiente` → `justificado`; o `no_cumplido`
- `Grupo.procedencia`: `sede` · `caserio`

## 4. Flujos de negocio

### 4.1 Ruta sacramental
Al crear/editar un confirmando se indica el "sacramento faltante" y
`ConfirmandoController::asignarRutaSacramental` sincroniza en cascada los sacramentos
pendientes y sus requisitos (manteniendo el avance ya registrado). El catálogo de
sacramentos está cacheado 1 día (`SacramentoController::CACHE_KEY`).

### 4.2 Asistencia
`POST /api/reuniones/{id}/asistencias` recibe un array y hace `updateOrCreate` por
`(reunion_id, asistente_id, asistente_type)`. `GET /api/asistencias/matriz?tipo=...`
arma la matriz persona × reunión.

### 4.3 Justificación de faltas
1. Falta `injustificada`.
2. El apoderado se acerca; se registra un **acuerdo** (`motivo`, `descripcion`,
   `fecha_acuerdo`) → estado `pendiente` (`POST /justificaciones/acuerdo`).
3. Cuando el joven cumple la acción pactada → `justificado` y la asistencia pasa a
   `falta justificada` (`POST /justificaciones/completar`).
4. Si no cumple → `no_cumplido`, la asistencia vuelve a `falta injustificada` y se
   estampa nota de auditoría (`PUT /justificaciones/{id}/rechazar`).

Alcance: el **catequista** gestiona las justificaciones de los confirmandos de sus
grupos; **coordinador/super-admin** ven todas. El filtro está en
`JustificacionController` (`esPrivilegiado` / `autorizarAsistencia`) y reforzado por RLS.

Regla temporal: en el listado, una falta injustificada "pura" solo aparece si la
reunión fue en los últimos **21 días** (valor fijado por el equipo, sin norma formal —
candidato a configuración por parroquia). Las que ya tienen trámite aparecen siempre.

### 4.4 Dashboard de alertas de riesgo
`GET /api/dashboard/metricas` devuelve métricas + un array de alertas por confirmando.
El cálculo (rachas de faltas, umbrales) vive **solo en el backend**
(`DashboardController::metricasYAlertas`) — es la única fuente de verdad; el frontend
únicamente filtra por grupo. Umbrales actuales (fijados por el equipo, sin norma):

| Nivel | Condición |
|-------|-----------|
| ALTO | ≥4 faltas injustificadas acumuladas, o racha activa ≥2, o ≥3 seguidas históricas |
| MEDIO | ≥4 faltas justificadas |
| BAJO | tardanza en las 2 últimas reuniones |

### 4.5 Distribución equitativa de grupos
`POST /api/grupos/generar-equitativo`: reparte confirmandos sin grupo (14–17 años,
`en_preparacion`) en round-robin separando por género.

### 4.6 Import/Export Excel
`maatwebsite/excel`. Importa confirmandos desde un Excel (parsea nombre completo,
valida celular de 9 dígitos, sanitiza con `strip_tags`). Exporta agrupado por grupo.

## 5. Frontend

- `views/` → `stores/` (Pinia) → `services/` (funciones que llaman a `lib/api.js`).
- `lib/api.js`: axios con interceptores. 401 → limpia sesión y redirige a login;
  red/5xx → toast genérico + reporte a `POST /api/logs/frontend-error`.
- Guard de rutas por `meta.permission` (`router/index.js`).
- `main.js`: `errorHandler` global de Vue + `unhandledrejection` → reporte al backend.
- Migración de UI Bootstrap → Tailwind en curso ("Fase 8").

## 6. Deuda técnica conocida

Corregido (2026-08-27):

- Perfil de confirmando leía atributos inexistentes (`falta_bautizo`).
- CORS `*` + credenciales → allowlist por env.
- `ReunionController::update` bloqueaba editar reuniones pasadas; `tipo` sin validar.
- Enumeración de usuarios en `forgot-password`.
- Login solo por DNI (Perú) → correo o DNI; `dni` ahora opcional y más ancho.
- Sin rate limit en `forgot/reset password`.
- `AsistenciaController::store` reventaba con 500 si la reunión no existía.
- Esquema de `justificaciones` desincronizado del código (`fecha_acuerdo`, `no_cumplido`).
- Frontend recalculaba alertas con umbrales distintos al backend.
- `.rnd` versionado.

Corregido (2026-08-28, Parte 0 del plan multi-parroquia):

- `justificaciones` ahora bajo RLS (hereda el alcance de su `asistencia`).
- `sacramentos.clave` (`bautismo`/`comunion`/`confirmacion`): `asignarRutaSacramental`
  resuelve por clave estable, no por nombre → una parroquia puede renombrarlos.
- Tests de aislamiento por grupo: `confirmandos`, `grupos`, `asistencias/matriz`,
  `justificaciones` (`ScopePorGrupoTest`, `JustificacionScopeTest`).

Hecho (2026-08-29, Parte 1 — tenant base):

- Tabla `parroquias` + `parroquia_id` (FK NOT NULL) en users, grupos, confirmandos,
  apoderados, reunions, sacramentos, requisitos, tipo_apoderados, frontend_error_logs.
  Backfill a la parroquia inicial (Parroquia Sagrado Corazón de Jesús).
- `App\Tenancy\*` (TenantContext, Facade Tenant, ParroquiaScope, BelongsToParroquia,
  middleware ResolveTenant). Login y `/get-user` devuelven `parroquia`.
- Bug al paso: `ConfirmandoController::store` accedía a `sacramento_faltante_id` sin
  `?? null` → warning/500 al crear un confirmando sin ese campo.
- `TenantIsolationTest` (7 casos de aislamiento entre parroquias).

Hecho (2026-08-30, Parte 2 — RLS por parroquia):

- La app usa el **session pooler** de Supabase (`pooler.supabase.com:5432`), donde
  `set_config(..., false)` (ámbito de sesión) es correcto. **No usar el transaction
  pooler (:6543)** — perdería el contexto RLS.
- `SetPostgresRlsContext` fija también `app.current_parroquia_id`.
- Migración `add_parroquia_to_rls`: políticas **RESTRICTIVE** de parroquia (se
  combinan con AND sobre las permisivas de alcance-por-grupo existentes, sin tocarlas)
  en las tablas con `parroquia_id` directo: grupos, confirmandos, apoderados (ya con
  RLS) + reunions, sacramentos, requisitos, tipo_apoderados, users, frontend_error_logs
  (RLS nueva). Cada política lee la columna de su fila, sin subconsultas → sin recursión.
  Las pivote y asistencia/justificaciones NO llevan política de parroquia: la app
  siempre las alcanza vía su modelo padre, ya acotado. El filtro de parroquia NO lo
  salta el "privilegiado" (un super-admin de A no ve B); el rol `proveedor` llega en
  la Fase E.
- `TenantRlsPgsqlTest` (se salta salvo que la suite apunte a pgsql).

Pendiente:

- Sin paginación real en `users`, `grupos`, `reuniones` (volumen bajo por ahora).
- Verificar en Render: `APP_DEBUG=false`, `APP_ENV=production`.
- Configurar un mailer real (hoy `MAIL_MAILER=log`). Mientras no lo esté,
  `POST /forgot-password` responde `503` con un aviso explícito de que el envío de
  correos no está configurado (no simula que mandó el enlace).
- `procedencia` (`sede`/`caserio`) y los tipos de reunión están hardcodeados
  (se resuelven en la Fase C del plan multi-parroquia).
- Multi-parroquia: ver `PLAN-MULTITENANT.md`.
