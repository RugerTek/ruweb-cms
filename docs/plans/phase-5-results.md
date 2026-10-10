# Fase 5 — Revisión, versiones y recuperación por MCP

Fecha: 2026-10-10. Fase aceptada tras revisión independiente y gates finales con base original de propuesta.
Commit local 0.51.0; no se inició fase 6 ni se ejecutó push.

## Entrega

`EditorialOperations` comparte propuestas, solicitudes/decisiones de revisión, comentarios, versiones/diff,
restauración y previews entre el panel y MCP. Recibe el usuario explícitamente; usa los validadores de páginas
/posts, transacciones y el ledger existente. El catálogo añade diez herramientas (46 en total), idénticas en
full/essential. Historial/diff nunca escriben; los payloads grandes tienen paginación y recuperación por campo,
con revisiones/fingerprints completos y rangos Unicode sin saltos.

Draft-only puede preparar propuestas sobre publicado, pedir revisión, comentar, restaurar como propuesta y
compartir/revocar su preview. No publica ni decide; sólo el administrador asignado aprueba la versión exacta.
Las fechas de publicación/programación/retiro vigentes sobreviven a la aprobación. `content_publication` añade
`expire` para fijar una retirada futura o quitarla explícitamente con `expires_at: null`.

Las propuestas conservan la base original: si otro agente o humano la cambia antes de solicitar revisión o
antes de aprobarla, la operación falla sin mutar contenido, reviews ni recibos. Los leases humanos también
bloquean la aplicación por MCP. Cada escritura editorial nueva exige revisión y operation_key; los recibos
persistidos recuperan el mismo resultado ante reintentos, incluso si las claves JSON cambian de orden.

Restore `proposal` conserva el público y permite revisar la recuperación. Restore `draft` mantiene la semántica
anterior del panel: retira explícitamente una publicación, valida la copia histórica y conserva asociaciones
que todavía existen. Republicar requiere la acción administrativa explícita. Locale, grupo y autor original
no se cambian por recuperar una versión; la versión y comentario conservan la identidad del actor.

Las previews nuevas se fijan a una versión inmutable, incluidas sus asociaciones. Cada lectura anónima verifica
expiry/revocación; respuesta private/no-store/noindex, sin formularios, analytics ni barra administrativa.
Los tokens se guardan sólo como hash; las URLs privadas no forman parte de los recibos ni evidencia.

## Migración y compatibilidad

La migración incorpora `editorial_versions.base_fingerprint` nullable y `editorial_operation_receipts` con
identidad de actor/contenido/operation_key y resultado JSON. Versiones existentes siguen consultables y
restaurables. Una propuesta histórica sin base conocida se rechaza conservadoramente para review: leer el
contenido actual, crear una nueva propuesta y solicitar una nueva revisión. No se deduce su base de la actual.
Las herramientas legacy de editar drafts continúan rechazando publicados. Preview conserva `url`, `expires_at`
y horas/revisión opcionales; añade link/versión exactos y revocación. Los scopes y roles no dependen del perfil.

La inclusión/exclusión de recibos de operación en snapshots se decide explícitamente en fase 10. No son contenido
editorial portable; las preview capabilities continúan excluidas de snapshots.

## Verificación y evidencia

La suite final pasó **455 tests / 3439 assertions** en 25,12 s, con SQLite y rutas/config/services/packages
caches aislados; `cms:check-conventions` y Pint pasaron. Los 16 tests nuevos cubren ambos perfiles y entidades,
base original por MCP/panel/restore-proposal, legacy desconocida, reintentos con mapas reordenados, autoría,
leases, campos inválidos, diferencias y recuperación de Unicode/comentarios grandes. Posts comprueban
reemplazo de categorías, preservación de otros tipos, idioma correcto y medios actuales tras restauración.

El gate final E2E-05 creó **dos schemas MySQL nuevos**, uno por perfil, y ejecutó bearer HTTP sin cookies:
publicar, proponer, solicitar/comentar, aprobar exacto, rechazar revisión obsoleta y rechazar propuesta obsoleta
incluso si se solicita review con la revisión actual. Restauró un histórico a draft (404 público), lo revisó,
republicó y leyó los pivots actuales de medios, el HTML público y bytes reales de la imagen. Chromium anónimo
verificó propuesta/público y recuperación/revocación a 1440/390 px, decodificó imágenes responsivas y comprobó
canonical, headers seguros y ausencia de errores/desbordamiento. Revocación/expiry devolvieron 404; el reloj
controlado retiró publicación y sitemap en el límite correcto.

Regresiones acumuladas: E2E-00/01 en full; E2E-02 y transiciones, E2E-03 en ambos perfiles; E2E-04 con instalación
real, creación humana de usuario/credencial, cliente real Codex `gpt-6.1-sol`, esenciales/barra y regreso a Full
con guardado/reapertura visual. Se usó el mismo build de producción; esta fase no cambia JS/UI. El cliente hija
usó el aislamiento/configuración efímera del harness de fase 4; no se cambió la configuración global ni se
repite aquí interoperabilidad OAuth/otros clientes de fase 9.

La evidencia saneada está en [phase-5-evidence](phase-5-evidence/context.json), con resultados por perfil y
capturas de fase 5 y resultados del cliente/regresión de fase 4. El contexto distingue el candidato final
HTTP del endurecimiento final de entradas inválidas, que no cambia flujos válidos y está cubierto por la suite
final. Las fixtures de cada ejecución se limpiaron; loopback 8018 quedó libre.

La revisión independiente encontró que una propuesta de base A podía solicitar revisión después de avanzar
el live a B. Se agregó base_fingerprint al crearla y comprobaciones al solicitar/aprobar; el gate final repite
este caso en HTTP y ambos perfiles. También se reforzó la restauración leyendo asociaciones persistidas
actuales y el HTML público, en lugar de verificar solamente la copia histórica.

Las fixtures son copias descartables con vendor/storage/caches/MySQL independientes, sin `.env` compartido,
public/hot o estado productivo. Los únicos stubs externos son reCAPTCHA/mail log del harness acumulado. El
reloj existe sólo en el router de pruebas. La evidencia exportada contiene resultados y capturas sin bearer,
cookies, tokens, URLs privadas o traces crudas. El cleanup guardado revoca credenciales y elimina sólo los
esquemas generados por cada fixture; elimina manifest, homes/auth del CLI y logs privados.

## Reversión

Detener agentes y workers antes de revertir código/esquema; volver al commit previo y revertir la migración
elimina recibos y el control de base de propuestas, no páginas/posts/versiones históricas. No se elimina contenido
por cambiar de perfil. Una instalación que creó propuestas con este contrato debe revisarlas antes de revertir;
los clientes deberán volver al catálogo legacy. No se ejecutó push ni despliegue remoto.
