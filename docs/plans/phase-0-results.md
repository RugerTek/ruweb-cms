# Fase 0 — Resultados e inventario

Fecha: 2026-10-10. Base: 0.47.0. Entrega de correcciones: 0.47.1.
Estado: fase 0 aceptada; correcciones, regresiones, HTTP/MySQL y descubrimiento de las 22 herramientas
en una sesión nueva de Codex aprobados. La autorización global del propietario habilita fase 1 tras este
cierre y exige aprobar el E2E de cada fase antes de avanzar, con programación y pruebas por Sol 6.1.

## Cambios de contrato

| Antes | Ahora | Compatibilidad / reversión |
|---|---|---|
| Listados sin parámetros anunciaban `properties: []`. | Esquema objeto `{}` para los 22 tools. | Reconectar clientes; sin migración. |
| Una actualización parcial reemplazaba todo `content_json`. | Mapas se combinan, listas se reemplazan, null elimina una clave; raíz vacía no hace cambios. | Documentado en MCP. Los clientes deben enviar solo claves cambiadas; revertir puede reintroducir pérdida de contenido. |
| Un administrador podía despublicar al editar por MCP. | Solo se aceptan borradores; rechazo explícito y sin cambios para publicados. | Restricción intencional. La fase 5 añade versiones pendientes; el panel conserva su flujo actual. |
| La API omitía idioma/grupo y validaba slugs globalmente. | Devuelve locale/grupo; creación acepta idioma instalado y unicidad por idioma. | Usa columnas e índices existentes; ninguna migración. No permite vincular traducciones todavía. |
| El agente no podía recorrer las páginas de resultados. | `page` explícito en RPC y REST, orden por fecha e ID. | Paginación por offset para inventarios estables; no promete snapshot concurrente. |
| Los ajustes tipo contraseña podían devolver secretos descifrados. | `value: null`, `redacted: true` en list/get. | Se conserva el valor almacenado; no se cambian permisos ni credenciales. |

Las escrituras de borrador recargan el registro bajo bloqueo dentro de una transacción. Esto evita combinar
sobre una lectura obsoleta y hace atómico el chequeo de estado con la escritura; no sustituye el control
optimista de conflictos entre cambios a la misma clave previsto en fases posteriores.

## Inventario de superficies y permisos

Inventario de rutas del repositorio: 159 entradas, 114 bajo admin, 24 bajo MCP y 21 restantes
(públicas, identidad/OAuth y framework). Un total no implica paridad funcional.

| Dominio | Panel actual | MCP actual | CLI / vía pendiente |
|---|---|---|---|
| Páginas/posts | CRUD y Live Editor, contratos editoriales | 5 tools por entidad: listar, leer, crear borrador, editar borrador, publicar | Crear plantillas/colecciones sigue siendo código. Ampliación en fase 3. |
| Medios | Carga, edición, traducciones, salud/usos | 3 tools: listar, detalle, metadatos | `cms:placeholders`, `cms:media:audit-orphans`; carga/usos/borrado MCP en fase 3. |
| Menús | CRUD y árbol | 6 tools: listar, detalle, crear, actualizar, sincronizar, borrar | El listado existe; reparar descubrimiento, no duplicarlo. |
| Ajustes/layout | General, header/footer, contacto, analytics, email | 3 tools de ajustes; escritura por allowlist | `config/cms.php` para opciones de despliegue. No hay gestor Custom Code. |
| Idiomas | Instalación y traducción por entidad | Identidad explícita y creación por locale; no workflow de traducción | Fase 3: descubrimiento de esquemas, vinculación y traducciones. |
| Editorial | Versiones, revisiones, recuperación y previews | Publicación básica; sin tools de revisión/preview/restauración | `cms:expire-content`; flujo completo en fase 5. |
| Formularios | Bandeja, asignación, seguimiento y reenvío | Sin tools específicos | Worker y scheduler operativos; tools en fase 6. |
| Identidad | Login, usuarios, claves, TOTP y conexiones | Autenticación Bearer/OAuth y scopes; sin tools de gestión de usuarios | `cms:install`, `cms:user:reset-password`; paridad de clientes en fase 9. |
| Portabilidad | Snapshots y herramientas de desarrollo | Sin tools de snapshots | `cms:rewrite-url`; migración/piloto en fase 10. |
| Contratos | AGENTS, skills, convenciones | Herramientas con esquemas; aún sin descubrimiento general de capacidades | `cms:check-conventions`; adaptación por perfil en fase 2. |

Roles: páginas/medios/lecturas de ajustes requieren editor o administrador; posts y menús usan sus políticas;
publicación, escritura de ajustes y administración de menús requieren administrador donde lo establece la
política. Los scopes se intersectan con el rol: solo lectura no escribe; solo borradores no publica ni modifica
medios, menús o ajustes. No es autorización para operaciones nuevas.

Se conserva el alcance acordado: full y essential con el mismo MCP; essential mantiene identidad, ajustes,
medios, listados/preview y bandeja, omitiendo Live Editor. HTML editorial saneado y Custom Code autorizado
siguen separados. Codex local es el primer cliente de aceptación; Claude/OAuth y compatibilidad ampliada se
validan en fase 9. La configuración de perfiles todavía no está implementada.

## Evidencia de aceptación

- Suite PHP: **386 tests, 2391 assertions**, todos aprobados.
- JavaScript: **15 tests**, todos aprobados.
- Build Vite: aprobado; advertencia por el bundle grande del editor enriquecido existente.
- Convenciones: aprobado. Traducciones ES/EN conservan paridad.
- `McpPhaseZeroTest`: siete escenarios para esquemas/listados, errores de herramientas, secretos, parches,
  protección de publicados, idiomas/slugs y paginación por ambos transportes. Los tests previos de scopes
  permanecen aprobados.
- HTTP real/MySQL: fixture exclusivo, 105 registros sintéticos por dominio paginado y placeholders reales;
  22 esquemas válidos y listados ejecutados; parches/relectura; idiomas/grupos; validación negativa; protección
  de publicados como admin/editor/draft-only; solo lectura e identidad inválida; sesión admin, creación de
  publicación, lectura pública sin sesión, SEO e imagen descargada; escritura MCP visible en el editor.
- Formulario real: CSRF, token firmado, espera mínima, honeypot y throttle activos. Solo se simula la
  respuesta externa de Google. Bandeja y cola MySQL reales; correo entregado a log local, nunca a terceros.
- Navegador: portada de prueba visible con imagen y contenido; login con cuenta ficticia y bandeja con el
  envío «Phase Zero Contact» y notificación «Enviada».
- Reproducción y preparación: [tests/E2E/README.md](../../tests/E2E/README.md).
- **Codex real, sesión nueva Sol 6.1:** inventario de herramientas del cliente con exactamente 22
  herramientas `mcp__ruweb__`; incluye `menus_list` y `settings_list`. Ambos listados se ejecutaron con
  `isError: false`. Se recibieron seis menús y ocho grupos de ajustes; dos ajustes tipo contraseña
  devolvieron `value: null` y `redacted: true`. `menus_get` leyó el menú 5 usando el ID recibido del listado
  (sin aportar un ID externo) y respondió correctamente. Solo se hicieron lecturas en el sitio local;
  no se alteró configuración personal ni se emitieron credenciales. El descubrimiento anterior de 20
  herramientas queda como evidencia del defecto reparado, no como limitación vigente.

No se ejecutó un envío a proveedores reales ni se probó Claude/OAuth. No se escribió en las páginas del
sitio local ni se eliminó su borrador de prueba 15. Las bases desechables no son una migración del sitio.
Los resultados automatizados no sustituyen la revisión de producto de las fases futuras.

## Línea base de frontend

Build de esta revisión (sin cambios en templates o JavaScript): CSS público 101.18 kB / gzip 17.83 kB;
JS público 36.50 kB / gzip 14.47 kB; CSS admin 88.09 kB / gzip 16.11 kB; JS admin 46.04 kB / gzip 13.86 kB.
Editor enriquecido: 1586.01 kB / gzip 493.01 kB. Son tamaños de artefactos, no una medición de Core Web Vitals
ni prueba de qué chunks descarga cada ruta. Servirán para comparar la entrega essential más adelante.

## Revisión y reversión

Revisión de contratos: contenido editable permanece en datos, medios se mantienen por ID, URL se resuelve
al renderizar, traducciones se identifican por grupo y locale, formularios y anti-spam no cambian, y secretos
no se exponen. No hay cambios de esquema ni migraciones que revertir. Al revertir código se debe bloquear
la escritura MCP si reaparecen reemplazos destructivos o despublicaciones. Los tests se repiten con un
fixture nuevo; las credenciales efímeras y bases generadas se limpian con el procedimiento documentado.

El cierre se prepara como commit local 0.47.1, con CHANGELOG y VERSION en la misma entrega; sin push
ni publicación remota. No se implementó ninguna fase posterior.

## Corrección tras la verificación adicional

El rechazo de un ajuste fuera de la allowlist conservaba el valor pero aparecía como éxito en MCP: el
adaptador perdía el estado HTTP 422. Se reprodujo primero con un test fallido y se corrigió el adaptador
para devolver `result.isError: true` cuando el controlador responde con estado >= 400, preservando su
mensaje. Las respuestas exitosas incluyen `isError: false`; REST mantiene sus códigos originales.

Regresión verificada: ajuste no permitido sin mutación (422), lectura de ajuste inexistente (404),
escritura de clave permitida pero inexistente (404) y escritura válida exitosa. El helper de pruebas
ya no considera suficiente HTTP 200: comprueba tanto `error` como `isError`. El E2E HTTP/MySQL repitió
los rechazos 422/404 y el recorrido completo de panel, publicación y formulario/cola con éxito.
Suite final: 386 pruebas PHP y 2391 assertions; convenciones y diff sin errores. No se repitieron build
ni JavaScript porque esta corrección solo cambia la envoltura PHP del protocolo y sus pruebas.
El redescubrimiento real en una sesión nueva de Codex quedó aprobado durante el cierre, como se detalla
en la evidencia de aceptación. No se implementó fase 1 ni se realizó push o publicación remota.

## Cierre de aceptación

La revisión independiente no encontró bloqueos de producto. Señaló que faltaba comprobar el contenido
público tras los rechazos, además del HTTP 200: el fixture de página ahora contiene heading y cuerpo
renderizables por su template. El E2E comprueba página (contenido, título SEO y meta description) y post
(título y cuerpo) sin sesión antes y después de los intentos de edición; los valores se conservan.

Se repitió el E2E HTTP/MySQL completo sobre un nuevo fixture: nueve comprobaciones HTTP aprobadas y
cola real con entrega de la notificación al log local aprobada. La base y las credenciales se eliminaron
al finalizar. Evidencia no sensible de esta repetición:
`/tmp/ruweb-phase0-close-qgg76w_2/http-results.json`.
La nueva ejecución de `composer test` aprobó 386 tests y 2381 assertions; el conteo previo 2391 se conserva arriba como resultado registrado de aquella ejecución,
y esta repetición registra su conteo exacto sin inferir equivalencia de conteos. Convenciones, formato PHP y diff aprobados. El descubrimiento del cliente Codex real aprobó
el último gate de la fase 0. La autorización global no elimina los gates E2E de las fases posteriores.
