# Spike Fase 0 — claims en el JWT + RLS

Prueba de concepto de la decisión central del plan (`docs/PLAN-MIGRACION-SUPABASE.md`):
**`parroquia_id` + roles + permisos viajan en el JWT de Supabase Auth y la RLS los
lee con `auth.jwt()`**, reemplazando `ResolveTenant` + `SetPostgresRlsContext` +
`ParroquiaScope` de Laravel.

## Qué monta

- `../migrations/20260830190000_auth_bridge_y_access_token_hook.sql`
  - `public.users.auth_id uuid` → `auth.users(id)` (decisión §6.1)
  - `public.custom_access_token_hook(event jsonb)` — inyecta `app_user_id`,
    `parroquia_id`, `es_proveedor`, `roles`, `permisos` (guard `api` de Spatie)
  - registrado en `../config.toml` → `[auth.hook.custom_access_token]`
- `../migrations/20260830190100_rls_por_claims_spike_confirmandos.sql`
  - esquema `app` con helpers `jwt_*()` que leen `auth.jwt()`
  - políticas nuevas de `confirmandos` y `catequista_grupo` basadas en claims
    (alcance del spike; la reescritura completa es la Fase 2)

## Cómo correrlo

```bash
supabase start                 # stack local (Docker)
# aplicar las 2 migraciones (o supabase db reset una vez exista el seed de Spatie)
psql ... < ../migrations/20260830190000_*.sql
psql ... < ../migrations/20260830190100_*.sql
supabase stop && supabase start   # para que Auth cargue el hook de config.toml

bash 01_seed.sh        # parroquia 2, grupos, confirmandos, 3 usuarios (auth+public)
bash 02_verify.sh      # login de cada uno, decodifica el JWT, GET /rest/v1/confirmandos
bash 03_negatives.sh   # intentos de escritura/lectura cruzada (deben fallar)
```

## Resultado (2026-08-30) — ✅ TODO VERDE

| Actor | Ve en `/rest/v1/confirmandos` | Correcto |
|---|---|---|
| `admin1` super-admin P1 | Ana, Beto, Caro (toda P1) | ✅ |
| `cat1` catequista P1 (grupo G1A) | solo Ana | ✅ aislamiento por grupo |
| `admin2` super-admin P2 | solo Dani | ✅ aislamiento por parroquia |
| anón (sin login) | nada | ✅ |

Negativos: `cat1` no puede crear confirmandos (403 RLS); `admin1` no ve ni puede
crear filas de la parroquia 3 (403 `confirmandos_parroquia` WITH CHECK); `admin1`
sí crea en su parroquia (201).

## Aprendizajes que van a la Fase 2

- **Grants por defecto de Supabase**: `anon` y `authenticated` tienen `SELECT/INSERT/
  UPDATE/DELETE` sobre TODAS las tablas de `public`. → La RLS es obligatoria en
  **todas** las tablas; las que hoy no tienen RLS (la mayoría) quedan abiertas vía
  PostgREST. La Fase 2 debe habilitar RLS + políticas por claims en todo `public`,
  o revocar grants donde no aplique.
- Las políticas de `catequista_grupo` (y toda tabla consultada dentro de otra
  política) también deben migrarse a claims, o las subconsultas devuelven vacío.
- `parroquia_configuraciones` tiene columnas NOT NULL sin default (las llena el
  modelo con `TenantConfig::DEFAULTS`) → el onboarding en Supabase debe fijarlas.
- El `DatabaseSeeder` usa `WithoutModelEvents`, que anula el hook `creating` de
  `BelongsToParroquia` → al sembrar contra Postgres real falla por `users.parroquia_id`
  NOT NULL. (No bloquea la migración; a tener en cuenta al portar seeds.)
