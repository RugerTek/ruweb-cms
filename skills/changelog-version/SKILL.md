# Skill: Versionar commits en `CHANGELOG.md`

Objetivo: mantener un changelog **por commit** usando **SemVer pre-1.0** (`0.MINOR.PATCH`) hasta llegar a `1.0.0`.

## Reglas de bump (por commit)

- **MINOR** (`0.(x+1).0`) si el commit agrega o cambia funcionalidades del producto (UI/UX, módulos, endpoints, features).
- **PATCH** (`0.x.(y+1)`) si el commit es fix, refactor sin impacto funcional, docs, chores, CI/deploy, estilos.
- Si hay duda, preferir **PATCH**.

## Archivo `VERSION`

- La versión también vive en el archivo `VERSION` de la raíz del repo, una sola línea con el semver, sin `v`.
- `config('cms.version')` lo lee en runtime y los snapshots `.appbackup` lo guardan en su manifest para validar compatibilidad al restaurar.
- Cada vez que se cree una sección versionada nueva en el changelog, `VERSION` debe quedar con **esa misma** versión en el **mismo commit**.
- El test `VersionFileTest` falla si `VERSION` no coincide con la última entrada versionada del changelog.

## Cómo actualizar `CHANGELOG.md`

- Mantener `## Unreleased` arriba.
- `## Unreleased` es **solo** para trabajo todavía no committeado.
- Cuando el trabajo se va a commitear, **no debe quedar en `Unreleased`**: hay que mover esos bullets a una versión nueva en el mismo flujo del commit.
- Agregar una entrada nueva arriba del todo (debajo de `Unreleased`):
  - `## <version> - <YYYY-MM-DD>`
  - 2–6 bullets claros, orientados a impacto (qué se habilita o qué se arregla).
- Categorías opcionales dentro de la versión (si aporta claridad): `Added`, `Changed`, `Fixed`, `Docs`.
- No incluir trabajo futuro ni “v1”; sólo lo que ya está en `main`.

## Flujo correcto para evitar desfase

### Si todavía no se va a crear commit

- Dejar los bullets en `## Unreleased`.
- No crear una sección versionada todavía.

### Si el trabajo se va a commitear ahora

- Calcular el próximo bump tomando como base la **última versión ya cerrada** del changelog, no el contenido de `Unreleased`.
- Si la entrada versionada inmediatamente anterior todavía no tiene SHA, completar su encabezado usando el SHA real del commit anterior.
- Mover los bullets actuales de `Unreleased` a una nueva sección versionada del commit que se está preparando.
- La nueva entrada del commit actual debe quedar **sin SHA**:
  - `## <version> - <YYYY-MM-DD>`
- Crear el commit incluyendo ese cambio del changelog.

## Regla operativa

- Nunca dejar en `Unreleased` el trabajo que acaba de entrar en el commit recién creado.
- Nunca dejar features ya committeadas dentro de `Unreleased`.
- El SHA de una entrada se completa en la siguiente actualización del changelog, no en el mismo commit.

## Convención de encabezados

- Formato preferido para entradas ya resueltas:
  - `## <version> - <YYYY-MM-DD> (<short-sha>)`
- Formato permitido solo para la entrada más reciente todavía no resuelta:
  - `## <version> - <YYYY-MM-DD>`
- Debe existir como máximo **una** entrada sin SHA, y debe ser la más reciente.

## Checklist rápido

- ¿Esto cambia lo que un usuario puede hacer? => MINOR
- ¿Sólo corrige/mejora sin nuevas capacidades? => PATCH
- ¿Docs/deploy/CI? => PATCH
- ¿Actualicé `VERSION` con la misma versión que la sección nueva? => obligatorio
