# Fase 10 — Actualización, snapshots, transiciones y piloto

Estado: fase 10 aceptada por el coordinador tras E2E-10 y revisión independiente; cierre local 0.54.2.
No push ni despliegue real. Candidato probado: base `14b2a7871d1b40b0938089fdfea6d61452d7b415`,
`VERSION` 0.54.1, más los cambios de fase 10 identificados en
[los fingerprints](phase-10-evidence/candidate-fingerprints.json). El cierre 0.54.2 aplica únicamente metadata de versión/changelog y estado del plan después del gate, sin cambiar comportamiento; los fingerprints conservan el candidato funcional probado en 0.54.1.

## Resultado y alcance

Se extrajo el artefacto real 0.47.0 de `f337e7d7b2eba14c9e6bcb2b1cb79a1978bb79a5`, se instaló en
MySQL desechable, se guardaron snapshot nativo, dump SQL de esquema/datos y copia de archivos públicos,
y se actualizó esa misma base con el candidato. Otro esquema ausente se instaló desde cero mediante
`cms:install`. No se inició ni modificó la instalación de `localhost:8000`.

Los manifiestos de contenido, URLs por idioma, IDs, SEO, estados/fechas de publicación, roles, relaciones
y SHA-256 de todos los archivos públicos coinciden antes y después de la actualización, la segunda
migración, las transiciones cacheadas y el rollback. La copia actual restaurada en otro destino conserva
además los hashes y conteos exactos de versiones editoriales, propuestas, revisiones, ledger y versiones
de código. Las propuestas persistentes viajan; los borradores de recuperación y previews locales se
invalidan por contrato.

| Recorrido | Evidencia real y resultado |
|---|---|
| Base e instalación limpia | Dos ejecuciones reales de `cms:install`, con guard que rechaza esquemas existentes; PHP 8.5.4, Laravel 13.3.0, MySQL. [Artefactos](phase-10-evidence/initial-artifacts.json). |
| Actualización 0.47.0 | Cinco migraciones aditivas; segunda ejecución informa `Nothing to migrate`. Manifiesto [inicial](phase-10-evidence/baseline-manifest.json) idéntico al [actualizado](phase-10-evidence/upgrade-manifest.json). |
| Full → essential → full | Configuración y rutas cacheadas, vistas limpiadas, señal de reinicio y trabajador real, servidor reiniciado en cada transición; discovery HTTP/MCP y datos preservados. [Informe](phase-10-evidence/profile-transitions.json). |
| Piloto essential | MCP sin cookies crea HTML, lienzo `empty`, bloques `composed`, traducción inglesa, propuesta, preview y publicación explícita; activa una versión local Custom Code con ámbito específico. Navegador verifica medios cargados, HTML saneado y una ejecución del snippet. [HTTP](phase-10-evidence/http-pilot-essential.json), [navegador](phase-10-evidence/browser-essential-existing.json). |
| Repetición full | Mismo MCP realiza propuesta/revisión/aprobación, restauración histórica a draft y republicación; desactiva código conservando historial. Login y listas operativas humanas accesibles en ambos perfiles. [HTTP](phase-10-evidence/http-pilot-full.json), [navegador](phase-10-evidence/browser-full-existing.json). |
| Snapshot actual entre instalaciones | Contenido, medios, IDs, SEO, idiomas y hashes de historial exactos; destino esencial preservado; credentials/receipts/previews cero; código inactivo/sin puntero; cola del destino vacía; pendientes failed sin claim/token, enviados sent. Se reactivó explícitamente el snippet de origen antes del export para demostrar que importarlo lo apaga. [Informe](phase-10-evidence/current-import.json), [navegador](phase-10-evidence/browser-import-fresh.json). |
| Snapshot 0.47.0 e incompatible | Se aceptaron por clave `older_snapshot` y los demás warnings realmente informados; baseline restaurado idéntico. Formato 999, re-checksummed, rechazado con `Unsupported snapshot format version.` sin mutación. [Informe](phase-10-evidence/old-import-and-rejection.json). |
| Rollback coordinado | Código exacto 0.47.0 + dump nativo correspondiente + public disk correspondiente; reconstrucción de assets y navegador bilingüe/publicación. Ninguna migración inversa. [Informe](phase-10-evidence/rollback.json), [navegador](phase-10-evidence/browser-rollback-existing.json). |
| Limpieza | Ambos esquemas eliminados con guard, procesos detenidos, claves/configuración/logs privados/snapshots/dump/copies destruidos; hash original de `.env` sin cambios. [Registro](phase-10-evidence/cleanup.json). |

Las capturas son de Chromium real a 1440 y 390 píxeles, sin tráfico externo. El límite Google de
reCAPTCHA del router se simula; este gate no recertifica la conexión externa de clientes, el login Claude,
ni una entrega a proveedor de correo. Codex con clave real en ambos perfiles mantiene la evidencia de
fase 9; Claude conserva el aplazamiento explícito del propietario. PHP mínimo 8.3 y los E2E acumulados
del candidato final corresponden a fase 11.

## Correcciones del producto

- Receipts MCP/editorial/submissions dejan de exportarse y no se importan aunque un archivo manipulado
  los añada con manifest y checksums válidos. Se limpian recibos locales; las exclusiones de tokens,
  sesiones, OAuth y previews se mantienen al importar.
- Restaurar borra claims y generaciones de notificaciones. Historial de submission events se conserva
  con actores válidos o null; un archivo anterior limpia eventos locales asociados a IDs reutilizados.
- El destino repite sanitización de Page/Post, valida referencias/rutas de medios y uploads según su
  política de MIME/extensión/tamaño/SVG. Un checksum no autentica un archivo controlado por su operador.
  Permisos Custom Code se fuerzan a false incluso con archivos parciales; snippets permanecen inactivos.
- Medios/metadatos importan antes del contenido, según prioridad del destino incluso con manifest antiguo
  o alterado. Esto corrigió el fallo real de restauración del bloque compuesto observado en el ensayo.
- Las pestañas de Developer tools hacen wrap en móvil. La captura final
  [390 px](phase-10-evidence/full-operations-existing-operations-390.png) muestra Monitor 404 en una
  segunda línea, sin overflow ni sidebar/overlay sobre el contenido.

## Validación determinista y juicio independiente

`composer test`: **542 pruebas, 4178 aserciones, todas pasan**, 26.93 segundos. Incluye:

- `SnapshotPortabilitySecurityTest`: archivos con checksum válido y tablas de receipts/tokens inyectadas;
  permiso falsificado, HTML hostil, SVG ejecutable, medios ausentes/rutas escapadas y rollback transaccional.
  El caso de media ID 420 en origen y 900 en destino es determinista; el ensayo MySQL usó los placeholders
  instalados y comprobó sus bytes. No se atribuye ese caso concreto al navegador.
- `SnapshotNotificationQueueTest`: cola **database** y trabajador reales en SQLite, sin la transacción
  exterior de RefreshDatabase; dos trabajos locales antiguos sobre IDs reutilizados no envían ni fallan
  entregas restauradas. Mail es el límite simulado. Un retry explícito de pending crea otra generación y
  envía una vez; retry de sent se rechaza. El MySQL E2E verifica cola destino vacía y estado restaurado.
- `PhaseFiveEditorialTest::test_review_request_rejects_stale_proposal_base_from_agent_panel_and_restore_and_unknown_legacy_base` comprueba una propuesta legacy con `base_fingerprint = NULL`: revisión rechazada con 409 sin cambiar live. El caso `test_draft_scope_can_restore_as_proposal_preserving_publication_until_assigned_review` comprueba por separado la recuperación histórica como propuesta nueva sin cambiar la publicación antes de revisión. La [guía](../operations/update-recovery.md)
  describe el procedimiento. No se afirma haber creado esa propuesta particular desde el UI 0.47.0.

`cms:check-conventions`: OK; `git diff --check`: OK. `npm run build`: pasa; 116 módulos, 489 ms en el
build final. Permanece el aviso existente del chunk TinyMCE de full; no se cambia el runtime JavaScript.
Sintaxis del harness Python/Node y PHP válida. La revisión independiente Sol ejecutó además 74 pruebas /
738 aserciones en copia aislada, sin editar el candidato, y revisó la captura móvil corregida.

Los assets servidos por el candidato aislado son byte por byte iguales a los del build final. El manifest
Vite difiere sólo en tres claves/src de imágenes Leaflet debido al symlink de `node_modules` de la copia;
las entradas de recursos y todos los archivos emitidos coinciden. Los fingerprints distinguen estas
rutas de entorno de los bytes de los assets, sin fingir un manifest raw idéntico.

## Incidencias y recuperación ensayada

1. Tras copiar source con sus timestamps, quedaron vistas compiladas 0.47.0: faltaban los nuevos hooks
   Custom Code. La receta limpia explícitamente vistas antes de caches/build/reinicio; se repitió el
   navegador y se comprobó una ejecución en las tres variantes.
2. El primer import validó composition antes de importar sus medios: falló con `image_id` inválido y
   la transacción no modificó la base. Se corrigió el orden y se repitió import, hashes y navegador.
3. La primera restauración SQL intentó re-añadir `GTID_PURGED` en el mismo servidor. Se conservó el dump
   privado, se retiró esa metadata redundante y se restauró el mismo esquema/datos; la receta exporta
   ahora con `--set-gtid-purged=OFF` para este clone aislado. El SHA final identifica el dump usado.
4. Las primeras capturas móviles atrapaban la transición del sidebar. El harness ahora espera su cierre
   completo también al cambiar viewport y usa el backdrop real; la nueva captura fue inspeccionada.

No queda incidencia bloqueante del piloto. Se documenta el límite existente: DB commit precede el
reemplazo de public disk; un fallo posterior de filesystem exige recuperar con backup previo e inspeccionar
archivos. No se garantiza atomicidad conjunta de base y filesystem. Se observó un `down()` SQLite del
workflow que deja un índice al quitar una columna; no se usa ni se recomienda como rollback de producción.
La recuperación ensayada restaura artefactos y datos completos.

## Reproducción y retención

La [guía de actualización y recovery](../operations/update-recovery.md) da el orden de despliegue, caches,
workers, inspección por riesgo, revisión de propuestas legacy y rollback conjunto. El harness durable
`tests/E2E/phase-ten-run.py` exige copia temporal y nombres `ruweb_phase0_<12 hex>`; lee sólo las cuatro
credenciales MySQL locales para crear schemas ausentes, nunca las imprime. El bootstrap rechaza boot fuera
de la copia y cualquier otro puerto. Los servidores usan 8018/8019, storage independiente y mail log.

Secuencia: `init`, navegador baseline/fresh, `upgrade`, `transitions`, `pilot-essential`, HTTP y browser
essential, `pilot-full`, HTTP y browser full, reactivación explícita en essential, `import-current`,
navegador import, `restore-old`, navegador old-import, `rollback`, navegador rollback y `cleanup`.
`refresh` sirve sólo para repetir los casos afectados cuando el ensayo encuentra una corrección del
producto. El harness conserva state privado tras cada arranque/parada para permitir cleanup seguro tras
una interrupción. No arranca ni ejecuta operaciones en el source original.

Los dumps, archives con hashes de contraseñas, conexiones y logs sólo existieron en la carpeta temporal
privada (0700, secretos 0600), y fueron destruidos al terminar. El repo conserva ref de código antiguo,
receta reproducible, manifiestos de contenido/medios sin credenciales y hashes del artefacto de recovery;
no contiene una base, dump, `.env`, clave, URL preview privada ni password de una cuenta real.
Antes de un despliegue real deben conservarse copias nativas/configuración bajo la política segura del
host y probar su restore, como exige la guía; este gate no sustituyó ni retuvo backups de producción.
