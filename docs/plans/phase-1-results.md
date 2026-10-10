# Fase 1 — Resultados de aceptación

Fecha: 2026-10-10. E2E-01 verifica las operaciones de borrador/publicación compartidas por panel,
REST y MCP sobre MySQL real, con la base y almacenamiento desechables del harness de fase 0.

## Recorrido reproducible

`tests/E2E/phase-one-http.py` crea un borrador desde el panel con sesión y CSRF reales. Un cliente
independiente, sin procesador de cookies, lo lee y modifica por JSON-RPC con su clave Bearer. Reabre el
editor y analiza sus inputs/textarea para comparar exactamente título, slug, metadatos SEO, heading y
cuerpo guardados; no considera suficiente que una cadena aparezca en cualquier parte del HTML.

La actualización parcial conserva las claves hermanas. Una clave de solo lectura no puede escribir;
un editor no puede publicar. Las relecturas completas del registro deben permanecer idénticas y la URL
pública continúa devolviendo 404. La publicación RPC autorizada devuelve fecha/estado y el sitio público,
sin sesión ni Bearer, muestra contenido y SEO esperados.

Otro borrador, con ID y slug distintos, repite creación, parche, rechazos y publicación mediante REST.
Se comparan los efectos persistidos de ambas entradas en título, idioma, plantilla, estado y content_json.
El registro independiente tampoco cambia después de los rechazos y renderiza el mismo contenido público.

Reproducción: [tests/E2E/README.md](../../tests/E2E/README.md). La fixture exige crear una base nueva,
rechaza nombres no generados y nunca reutiliza los datos del sitio local. Las credenciales efímeras quedan
en manifest.json con permisos 0600; no se incluyen en la evidencia ni en el repositorio.

## Ejecución

**Aprobado sobre la implementación de fase 1**, con una base MySQL recién creada:

- E2E-00: nueve comprobaciones HTTP aprobadas, incluyendo los 22 esquemas, RPC, permisos,
  paginación, parciales, idiomas, contenido público, panel, SEO, imagen y formulario/bandeja.
- Worker: cola MySQL real y notificación entregada al log de correo local.
- E2E-01: cinco comprobaciones aprobadas, cubriendo los cinco pasos del plan y la equivalencia REST.
  Los valores del editor se compararon en el formulario principal para excluir inputs de modales.
- Runtime de aceptación: PHP 8.5.4, Python 3.9, MySQL local; servidor de pruebas en 127.0.0.1:8018.
  Esta ejecución no certifica por sí sola el runtime mínimo PHP 8.3.

Evidencia no sensible de la ejecución aprobada:
`/var/folders/88/zkqjgk4x2911mq910dsjlzg40000gn/T/ruweb-phase1-final-r4m8ktq0/http-results.json` y
`/var/folders/88/zkqjgk4x2911mq910dsjlzg40000gn/T/ruweb-phase1-final-r4m8ktq0/phase-one-results.json`.
Solo se simula la verificación externa de Google; persistencia, autorización y middleware son reales.
No se envió correo a terceros ni se alteró la base del sitio local.

La primera ejecución de E2E-01 detectó un defecto del parser de pruebas: recogía también un input
`title` de un modal ajeno al formulario del editor. Se acotó el parser a `editor-form` y se repitieron
E2E-00, worker y E2E-01 completos sobre una base nueva. El defecto no exigió cambios de aplicación.

Servidor detenido, claves efímeras revocadas y base desechable eliminada con el cleanup del harness.
Se conservan únicamente logs y resultados locales no sensibles para revisión.

## Gate sobre el candidato final

Se repitieron E2E-00, worker y E2E-01 completos después de la corrección de coalescing del ledger
ante rollback/reintento de transacción y de conservar null en los parches MCP de campos normalizados
por el navegador. El candidato final volvió a aprobar nueve checks fase 0, entrega de cola y cinco
checks fase 1 en una nueva base MySQL; los paths de evidencia arriba corresponden a esta repetición.

Las negativas RPC ahora exigen el contrato de autorización exacto: scope readonly devuelve HTTP 403,
código JSON-RPC -32603 y el mensaje de scope_denied; el editor devuelve HTTP 200 con error JSON-RPC
-32603 y el mensaje publish_forbidden. REST exige HTTP 403. Un error interno genérico ya no satisface
la comprobación de rechazo. Tras cada intento se relee el registro completo y se comprueba identidad
de los datos y ausencia de publicación. Se conserva la API del adaptador MCP.

## Núcleo y regresiones

`PageOperations` recibe un actor explícito y comparte reglas de `PageInput` con los adaptadores del panel.
Guardado/publicación bloquean la fila y agrupan contenido, medios, historial y ledger en la transacción.
Se conserva el merge de formulario, la aprobación de traducciones y las propuestas sobre publicado del
editor; el parche remoto mantiene omisiones, listas y eliminación por null. Los eventos del modelo y el
registro editorial respetan el actor de la operación aunque exista otra sesión autenticada.

La revisión independiente detectó y se corrigieron dos casos: el slug de un parche se valida siempre contra
el idioma persistido, y el ledger restaura su estado de coalescing antes de reintentar una transacción.
Una regresión provoca un deadlock después del evento `saved`, verifica el segundo intento y el registro
confirmado con su autor. Otras regresiones cubren identidad explícita/sesión distinta, validación sin
escrituras, rechazo de estado publicado, null de campos normalizados del browser y fingerprint obsoleto.

- Suite final: **394 tests aprobados, 2394 assertions**, 22.89 s.
- `cms:check-conventions`: aprobado; revisión documental sin violaciones pendientes.
- Versionado de cierre: **0.47.2**, extracción y correcciones compatibles, sin nuevas herramientas ni rutas.

Límites para las fases siguientes: la extracción cubre páginas; posts y otros dominios siguen en sus fases.
Los scopes Bearer se verifican en el middleware de transporte y el núcleo comprueba los roles del actor.
Fuera de operaciones explícitas se mantiene el fallback HTTP para eventos legacy. La herramienta
`pages_update_draft` sigue rechazando publicados; ampliar MCP con propuestas/revisiones corresponde a
fase 5. No hay migraciones ni cambios de formato persistido. Reversión: revertir esta extracción conservando
los datos y contratos existentes; volver a ejecutar ambos E2E antes de habilitar escrituras.

Compatibilidad de errores preservada: el rechazo de scope de solo lectura usa HTTP 403; una
`AuthorizationException` del controlador RPC conserva HTTP 200 con error JSON-RPC `-32603` y el mensaje
específico. Las negativas E2E verifican cada contrato, evitando aceptar errores internos arbitrarios.
La normalización futura de errores corresponde a los contratos MCP de fases 3/9.
