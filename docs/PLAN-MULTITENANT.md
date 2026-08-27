# Plan: convertir el sistema en SaaS multi-parroquia

> Borrador para revisión. No implementado. Estimaciones en "sesiones de trabajo",
> no en días calendario.

## 1. Objetivo

Vender el sistema a varias parroquias. Cada parroquia opera aislada, con su propia
gente, sus datos y su configuración. Requisitos declarados por el dueño del producto:

- La **duración del programa** varía por parroquia (la de referencia: mayo–noviembre;
  otras 1 o 2 años).
- Configurable por parroquia: **tipos de reunión** activos (algunas no registran
  apoderados), **umbrales de alerta**, **ventana de días para justificar**, **ruta
  sacramental y requisitos**, **nombres de roles**, **branding** (nombre, logo, colores).
- Dos niveles de administración:
  - **Super-admin global** (el proveedor): da de alta parroquias, soporte.
  - **Admin de parroquia** (párroco/coordinador): autogestiona su parroquia (usuarios,
    configuración).
- Login: correo o DNI (ya implementado).
- Apoderados y confirmandos **no** tienen login (no cambia).

## 2. Decisión de arquitectura: **una sola base de datos con `parroquia_id` + RLS**

### Recomendación

Tenant discriminator: columna `parroquia_id` en las tablas raíz, aislamiento reforzado
por **Row Level Security de Postgres** (ya se usa RLS para el alcance por grupos; se
extiende el mismo mecanismo a la parroquia).

### Por qué, y no "una BD por parroquia"

| Criterio | BD compartida + RLS (elegido) | BD por parroquia |
|----------|------------------------------|------------------|
| Operación | Una migración, un deploy, un backup | N migraciones/backups; orquestación |
| Costo | Un proyecto Supabase | Coste por instancia; caro con pocas parroquias |
| Onboarding | Insertar una fila `parroquias` | Provisionar BD + migrar + configurar |
| Consultas cross-tenant (métricas del proveedor) | Triviales | Agregación N bases |
| Aislamiento | Fuerte si RLS está bien (defensa en profundidad) | Máximo (físico) |
| Riesgo principal | Un bug de RLS filtra datos entre parroquias | Complejidad operativa |

Con el volumen esperado (decenas de parroquias, cientos de confirmandos c/u) la BD
compartida es la opción sensata. El riesgo de RLS se mitiga con: políticas `DENY ALL`
por defecto, tests de aislamiento obligatorios, y `FORCE ROW LEVEL SECURITY` (ya en uso).

### Nota sobre Supabase + pooler

El middleware `SetPostgresRlsContext` usa `set_config(..., false)` (scope de sesión).
Con el **transaction pooler** de Supabase eso no es seguro (la conexión se reasigna
entre transacciones). Hay que:

- O bien pasar a `set_config(..., true)` (scope de transacción) y envolver cada request
  en una transacción, **o**
- Usar el **session pooler** / conexión directa para la app web.

Decidir esto **antes** de la Fase B. Es el punto más delicado de todo el plan.

## 3. Fases

### Fase A — Tenant base (sin RLS todavía)  · ~2 sesiones

1. Migración `parroquias`: `id`, `nombre`, `slug` (único), `activa` (bool),
   `creada_en`. Opcional: `plan`, `zona_horaria`, `contacto_email`.
2. `parroquia_id` (FK, **NOT NULL**) en las tablas raíz:
   `users`, `grupos`, `confirmandos`, `apoderados`, `reunions`, `sacramentos`,
   `requisitos`, `tipo_apoderados`, `frontend_error_logs`.
   Las tablas pivote y `asistencia`/`justificaciones` heredan la parroquia por su
   relación (no llevan columna propia; o la llevan denormalizada si conviene a RLS).
3. **Backfill**: crear la parroquia actual (`id = 1`) y asignar todo lo existente.
   Migración de datos productivos → probar primero en una copia.
4. `BelongsToParroquia` trait + **Global Scope** de Eloquent: toda query filtra por
   `parroquia()->id` salvo contexto privilegiado. Resolver la parroquia actual desde
   `auth()->user()->parroquia_id` (web) o desde un contexto explícito (CLI/jobs).
5. `parroquia_id` se asigna solo al crear (observer/scope), nunca desde el request.
6. Tests de aislamiento: usuario de parroquia A no ve nada de parroquia B en cada
   endpoint de listado.

### Fase B — RLS por parroquia  · ~2 sesiones

1. Resolver el tema del pooler (sección 2).
2. Middleware fija también `app.current_parroquia_id`.
3. Nueva migración RLS: en cada tabla raíz, añadir a la política existente
   `AND parroquia_id = app_current_parroquia_id()`. Las tablas ya con RLS (grupos,
   confirmandos, apoderados, asistencia…) suman la condición de parroquia a la de grupo.
4. `AppServiceProvider` (CLI) fija la parroquia cuando el job/comando la conoce; si no,
   modo privilegiado explícito.
5. Ampliar tests: intento de acceso cross-parroquia a nivel SQL crudo debe devolver 0
   filas aunque el Global Scope de Eloquent se salte.

### Fase C — Configuración por parroquia  · ~2-3 sesiones

Tabla `parroquia_configuraciones` (1:1 con `parroquias`), columnas tipadas + un `jsonb`
para lo extensible:

| Config | Forma | Uso |
|--------|-------|-----|
| Duración del programa | `programa_inicio` / `programa_fin` (date) o `duracion_meses` | Cálculos de cronograma, cierre de periodo |
| Tipos de reunión activos | `jsonb` (p.ej. `["Confirmandos","Catequistas"]`) | `ReunionController` valida contra esta lista; el frontend muestra solo esos |
| Umbrales de alerta | `jsonb` (`alto_injustificadas`, `alto_racha`, `medio_justificadas`, …) | `DashboardController` los lee en vez de constantes |
| Ventana de justificación | `dias_ventana_justificacion` (int, default 21) | `JustificacionController::index` |
| Branding | `nombre_publico`, `logo_url`, `color_primario` | Login, sidebar, correos, `<title>` |

- Endpoint `GET/PUT /api/parroquia/configuracion` (solo admin de parroquia).
- Cache de la config por parroquia (invalidar al guardar).
- El login puede devolver la config (o `GET /api/parroquia/configuracion` público por
  `slug` para pintar el branding antes de autenticar).
- Reemplazar las constantes actuales: umbrales en `DashboardController`, `21` en
  `JustificacionController`, `in:Catequistas,Confirmandos,Apoderados` en
  `ReunionController`, `sede/caserio` en `GrupoController`.

### Fase D — Ruta sacramental / requisitos por parroquia  · ~1-2 sesiones

`sacramentos` y `requisitos` ya son tablas → con `parroquia_id` (Fase A) cada parroquia
tiene las suyas. Falta:

1. Seeder que, al crear una parroquia, clone el catálogo estándar (Bautismo, Primera
   Comunión, Confirmación + requisitos típicos).
2. UI de administración de sacramentos/requisitos ya existe; verificar que respeta el
   scope. `asignarRutaSacramental` busca por nombre (`'Bautismo'`, …) — cambiar a un
   campo estable (`tipo` / `orden`) para no romperse si una parroquia los renombra.

### Fase E — Roles de dos niveles  · ~2 sesiones

1. Rol global nuevo: `proveedor` (super-admin del SaaS). El `super-admin` actual pasa a
   ser **admin de parroquia** (scoped).
2. Spatie con `team_id` = `parroquia_id` (Spatie soporta "teams"): los roles
   `coordinador`/`catequista` se asignan por parroquia. `proveedor` es global.
3. Nombres de roles configurables: tabla `parroquia_roles` que mapea el rol interno
   (`coordinador`) a una etiqueta visible (`"Coordinador de Catequesis"`). El frontend
   muestra la etiqueta; el backend sigue chequeando el permiso interno.
4. Panel de proveedor: alta/baja de parroquias, ver estado, impersonar para soporte.

### Fase F — Onboarding de parroquia nueva  · ~2 sesiones

1. `POST /api/proveedor/parroquias` (solo `proveedor`): crea `parroquia` + config por
   defecto + catálogo sacramental + primer usuario admin (con contraseña temporal).
2. Correo de bienvenida al admin con enlace para fijar su contraseña.
3. (Opcional) auto-registro con verificación manual del proveedor.

### Fase G — Suscripción / billing  · fuera de alcance inicial

Decidir aparte. Mínimo viable: campo `parroquias.activa` + `plan` + fecha de
vencimiento; middleware que bloquea el acceso (salvo pantalla de "suscripción vencida")
si `activa = false`. Integración con pasarela (Stripe/Culqi/Mercado Pago) después.

## 4. Impacto en el frontend

- `constants/api.js` y branding dejan de ser fijos: el branding se resuelve por
  `slug` de parroquia (subdominio `parroquia.app.com` o `?p=slug`, o tras login).
- Store `parroquia` (Pinia): config + branding, cargado al iniciar sesión.
- Aplicar `color_primario` como variable CSS global; `logo_url` en sidebar/login.
- Menús y validaciones que hoy asumen los 3 tipos de reunión leen la config.
- Panel de proveedor: vistas nuevas bajo permiso `proveedor`.

## 5. Riesgos

| Riesgo | Mitigación |
|--------|-----------|
| Bug de RLS filtra datos entre parroquias | `DENY ALL` por defecto, `FORCE RLS`, tests de aislamiento a nivel SQL, revisión de cada política |
| Pooler de Supabase invalida el `set_config` de sesión | Resolver en Fase B antes de tocar RLS (transacción por request o session pooler) |
| Backfill de datos productivos | Ensayar en copia; migración reversible; ventana de mantenimiento |
| `asignarRutaSacramental` busca sacramentos por nombre | Migrar a campo estable en Fase D |
| Spatie teams cambia el cacheo de permisos | Revisar `PermissionRegistrar` y limpiar cache por parroquia |
| Doble fuente de scope (Global Scope Eloquent + RLS) puede confundir | Documentar: Eloquent = DX, RLS = seguridad real |

## 6. Orden sugerido

`A → B` (aislamiento sólido primero) → `C` (desbloquea la venta: cada parroquia se
siente "suya") → `E` (roles) → `F` (onboarding) → `D` (sacramentos) → `G` (billing).

Antes de arrancar la Fase A: cerrar la deuda técnica pendiente de `ARQUITECTURA.md §6`
y subir cobertura de tests de los flujos con scope (asistencia, justificaciones, grupos).
