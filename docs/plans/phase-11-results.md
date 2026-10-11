# Fase 11 — Candidato reproducible y entrega local

Estado: gate funcional aceptado por revisión independiente y coordinador; cierre local VERSION/changelog
1.0.0 autorizado. Base cerrada `2b65a272f25470d89b0edbd70f7e6b0a8820d41e` (0.54.2), con corrección de protocolo MCP
identificada por los fingerprints de esta fase. No push ni despliegue real. La excepción del propietario
para Claude Code permanece explícita: cliente aplazado, sin login ni certificación.

## Resultado funcional del recorrido nuevo

Dos esquemas MySQL antes inexistentes se instalaron realmente con `cms:install`; cada perfil usa su copia,
storage, vistas, caches, puerto y cuentas desechables. Un navegador entra como primer administrador y emite
conexiones **full** y **custom-code** por el panel; la autorización de ejecutar código es un permiso separado
concedido expresamente a esa cuenta fixture. Codex CLI real, autenticado mediante la sesión nativa existente
en CODEX_HOME efímero, ejecuta las herramientas autorizadas con Sol 6.1 y bearer emitido por el panel.

No basta la última frase del agente: el harness exige herramientas completadas, sin errores MCP ni errores
del contenido CMS, y valores esperados. Los navegadores y consultas reales comprueban efectos persistidos.
El agente crea landing HTML ES, traducción EN, medios por ID, lienzo `empty`, formulario `contact` registrado,
previews y publicaciones explícitas. El formulario es el ejemplo code-owned existente; se editan textos,
idioma y notificación, sin trasladar campos/validación/destino a contenido editable.

| Paso E2E-11 | Full | Essential | Evidencia |
|---|---|---|---|
| Instalación limpia, login y claves del panel | Pasa | Pasa | `full/panel-connection.json`, `essential/panel-connection.json` |
| Codex crea/traduce/preview/publica HTML, lienzo y contact | Pasa | Pasa | `create-codex.json`, `contact-codex.json` por perfil |
| Snippet inocuo, destino ES exacto y versión activada | Pasa | Pasa | `snippet-codex.json`, `browser-initial.json` |
| Sitio anónimo, 1440/390, medios, SEO, idiomas, sin overflow | Pasa | Pasa | `browser-initial.json`, `browser-final.json`, capturas |
| Formulario completo, Google simulado, persistencia y cola real | Pasa | Pasa | `browser-initial.json`, `mail-queue.json` |
| Codex lee envío sent, asigna, cambia workflow y deja nota | Pasa | Pasa | `submissions-codex.json` |
| Propuesta/revisión aprobada/restauración draft/republicación | Pasa | Pasa | `workflow-codex.json` |
| Scope editorial deniega snippet; permiso propio separado | Pasa | Pasa | `protocol-permissions.json` |
| Codex desactiva; EN/lienzo/preview excluidos, ejecución cero | Pasa | Pasa | `deactivate-codex.json`, browsers |
| Recuperar acceso, revocar conexiones y password antiguo | Pasa | Pasa | `access-recovery.log`, `browser-recovered.json` |
| Snapshot restaura contenido mutado, elimina autoridad y código | Pasa | Pasa | `snapshot-recovery.json`, `browser-recovered.json` |

Todos esos archivos están bajo [phase-11-evidence](phase-11-evidence). `full/connect-form-codex.json` y
`essential/verify-linked-codex.json` verifican que las landing enlazan los contactos del piloto en su idioma.

## Corrección encontrada por revisión independiente

Los getters con selectores omitidos podían leer una clave inexistente y devolver HTTP 500. Se valida ahora
el contrato top-level anunciado en ToolRegistry antes de despachar: campos requeridos, tipos JSON sin
coerción, enums, mínimos/máximos y límites de longitud/items. Las reglas editoriales y permisos siguen en
las operaciones existentes. Un error retorna JSON-RPC `-32602` y diagnóstico por campo; una herramienta
inexistente conserva `-32601`. No se presenta esto como un motor JSON Schema recursivo.

La prueba de integración recorre los selectores legacy y nuevos con argumentos ausentes, array, boolean,
null y string numérico; ambas configuraciones de perfil. Otra prueba recorre cada argumento required del
registro y conserva diagnósticos de enum/bounds. El HTTP MySQL sobre las dos instalaciones finales reproduce
getters malformados y la separación scope/permiso. La suite completa PHP 8.3 y los recorridos afectados se
repiten después de la corrección; el cambio no altera el frontend público ni sus assets.

## Validación acumulada y cobertura de la matriz

Las recetas acumuladas se ejecutan sobre el candidato funcional final. El resumen de última ejecución
aceptada y los fingerprints de cada copia están en
[runtime-accumulated](phase-11-evidence/runtime-accumulated/README.md). Los incidentes resueltos se explican
abajo; una ejecución histórica fallida no cuenta como aceptación.

| Fase | Full | Essential | Alcance y prueba acumulada |
|---|---|---|---|
| 00/01 | Pasa | Pasa | Discovery, permisos, siblings, publicación, REST/MCP, formulario y worker. Editor visual original sólo Full; Essential verifica panel operativo/preview y exclusión de editor. `phase-0-*/http-results.json`, `phase-one-results.json`. |
| 02 | Pasa | Pasa | Sección/colección de fixture reproducible, datos/editor Full, operación Essential y tres transiciones cacheadas. `phase-2-*/phase-two-{results,browser-results,transition-results}.json`. |
| 03 | Pasa | Pasa | Medios binarios/variantes/localización, traducción, SEO/sitemap, previews y publicación programada; navegadores draft/public. `phase-3-*/phase-three-results.json` y browser JSON. |
| 04 | Pasa tras transición | Pasa | Instalación Essential real y panel humano, credencial y Codex CLI real; luego transición Full y editor visual. `phase-4-essential/phase-four-*-results.json`. |
| 05 | Pasa | Pasa | Propuesta, revisión, conflictos, restore, revocación/expiración y retiro programado; browsers proposal/restored. `phase-5-*/phase-five-results.json` y browser JSON. |
| 06 | Pasa | Pasa | Formulario browser, ocho solicitudes concurrentes, fallo/reintento del worker y seguimiento MCP. `phase-6-*/phase-six-results.json` y cuatro browser JSON. |
| 07 | Pasa | Pasa | HTML, empty, composed, medios/traducción/saneamiento, accordion y restore; Essential→Full y compatibilidad visual. `phase-7-*/phase-seven-results.json` y browser JSON. |
| 08 | Pasa | Pasa | Snippets por panel/MCP, concurrencia, reglas, versiones, seis documentos, iframe, kill/recovery y exclusiones. `phase-8-*/phase-eight-*-results.json`. |
| 09 | Pasa | Pasa | Identidad, roles, 2FA, sesiones, OAuth/revocación, recuperación y trabajador idempotente. Chromium identity/oauth-final/recover ambos; Codex real adicional Full. `phase-9-*/phase-nine-*-results.json`. |
| 10 | Pasa | Pasa | Upgrade real 0.47, instalación limpia, transiciones, snapshot actual/antiguo/incompatible y rollback SQL/files/código. `accumulated10/*json` y capturas. |
| 11 | Pasa | Pasa | Piloto nuevo detallado arriba; Codex real ambos, browser anónimo, formulario/cola, permisos y recovery. `full/`, `essential/`. |

Las fases 02 y 04 añaden únicamente sus capacidades code-owned de prueba documentadas en
`tests/Fixtures/admin-profiles` o en su bootstrap: son extensiones de aceptación y no cambios del starter.
Full y Essential usan el mismo producto salvo `config/cms.php` seleccionando el perfil en la copia; las
transiciones y diferencias adicionales de fixture quedan registradas. El replay 10 conserva el código real
0.47 para baseline/rollback y copia el candidato final para upgrade. No se reutiliza una prueba antigua para
suplantar un recorrido pendiente. Cada build emitido se compara por bytes; la diferencia de rutas Leaflet
por symlink en el manifest de Vite se declara, no se trata como diferencia de asset.

La matriz del apartado 11 del plan tiene esta prueba concreta por requisito. “Pasa” cubre el alcance
implementado y ambos perfiles apropiados; las exclusiones explícitas son editor visual Full y Claude aplazado.

| Requisito del plan | Resultado | Prueba y límites concretos |
|---|---|---|
| Actualización existente | Pasa | Replay10: baseline/upgrade/rollback real 0.47; browsers ES/EN, URLs/medios/contenido, roles/permisos y Full. |
| Nueva sección | Pasa | E2E02 fixture de capacidad + E2E07 composed; editor Full y operación Essential sin crear editor. No se promete autoría de código arbitrario por MCP. |
| Editorial MCP | Pasa | E2E03/05 y Codex11: crear/traducir/proponer/revisar/publicar/retirar/restaurar con bearer sin cookie administrativa; conflictos y previews fijados. |
| Medios | Pasa | E2E03: upload binario/variantes/metadatos idioma y borrado en uso; suite `MediaSafetyTest`, `MediaOperationsTest` verifica rechazo inseguro y validación. |
| Formulario | Pasa | E2E06 ambos: antispam completo, persistencia, concurrencia, fallo/retry/tracking; piloto11 envío browser, worker/mail log y Codex gestión. Google y transporte externo son fronteras simuladas. |
| HTML flexible | Pasa | E2E07 ambos: DOM saneado, responsive, vocabulario precompilado, IDs, traducción y restore; HTML editorial no ejecuta servidor/código arbitrario. |
| HTML/lienzo | Pasa | E2E07 y11: cuerpo HTML, empty sin layout, SEO/preview/publicación y edición MCP; 1440/390. |
| Custom Code | Pasa | E2E08: reglas global/exactas/idioma/posición/orden, versión activa y ejecución única, admin/previews/iframe. Protocolo11 confirma scope y permiso de cuenta separados. |
| Recovery código | Pasa | E2E08 disruption/kill y CLI con MySQL inaccesible; `offline-code.json` ambos. Snapshot10/11 restaura código inactivo y permiso false. |
| Permisos/concurrencia | Pasa | E2E00/03/05/06/08/09: scopes/roles, no escritura directa sobre publicado, conflictos, reintentos idempotentes. Suite `AdminSettingsTest`, `McpLayoutOperationsTest`, `McpClientCompatibilityTest`, `McpSubmissionsTest` y las pruebas identidad de E2E09 cubren settings, secretos, roles y auditoría; HTTP11: diagnóstico -32602 sin eludir403. |
| Inicio humano/agente | Pasa | E2E04 install/login/usuarios/clave desde panel; E2E09 ambos roles, sesiones,2FA,OAuth, revocación/reset. Piloto11 crea conexión real de Codex desde panel. |
| Cambio perfil | Pasa | E2E02 caches y tres transiciones; E2E04 Essential→Full; E2E07 aviso de bloque y E2E10 trabajadores/caches/datos. |
| Snapshot | Pasa | E2E10 actual/antiguo/incompatible/rollback; E2E11 mutation/restore y autoridad removida. No atomicidad conjunta DB/filesystem; receta explícita de recovery. |
| SEO/rendimiento | Pasa local | E2E03 publicación/canonical/hreflang/sitemap/draft/future/expired; E2E07/11 semántica/medios/empty; baseline comparativo SEO84 navegaciones, sin editor público. No CWV producción. |
| Cliente MCP | Pasa con excepción autorizada | Codex CLI real E2E04/09/11; permisos/errores/protocolo. Claude Code aplazado expresamente por propietario, no certificado. |

PHP 8.3.35 real se extrajo en un runtime privado sin cambiar el PHP global. `composer install` instala las
109 dependencias del lock y `check-platform-reqs` pasa. La suite final posterior a la corrección pasa
**545 tests / 6290 assertions**. JavaScript: **17/17**; build y convenciones pasan. Son los 545 tests completos, sin omitidos; el conteo de assertions observado de la última ejecución se conserva, sin atribuir una causa no demostrada a su diferencia respecto de una lectura anterior. El chunk TinyMCE de full
conserva el aviso conocido; Essential no carga sus assets públicos/editoriales excluidos.

El replay E2E-10 recorre otra vez el artefacto real 0.47.0, instalación limpia, doble migrate, cambios de
perfil cacheados con restart de worker, piloto de ambos perfiles, import actual y antiguo, rechazo de
formato incompatible antes de mutar, y rollback de código+dump SQL+public disk; Chromium 1440/390 verifica
cada etapa. Evidencia independiente en `accumulated10/`; la histórica de fase 10 no se sobrescribe.

[SEO y rendimiento](phase-11-seo-performance.md): baseline 0.47.0 comparable, 84 navegaciones reales,
cuatro requests públicos, incremento explicado de 2.193 bytes (1,53%), CLS 0 y ocho comparaciones pasan.
Los criterios fueron adoptados para esta validación bajo la autonomía autorizada; no se denominan acuerdos
previos ni Core Web Vitals de producción. La corrección posterior afecta sólo el despacho MCP y se valida
por separado; no cambia esos recursos o rutas públicas medidas.

## Incidencias del harness y límites

- La receta E2E00/01 histórica asumía rutas de editor Full. Se conserva ese bloque para Full y se adapta Essential a su panel operativo/preview, manteniendo todas las operaciones y assertions compartidas.
- OAuth final necesitaba un sexto POST de autorización dentro del minuto tras los desafíos de segundo factor. Se esperó la ventana real de 60 segundos y se repitió ambos perfiles, sin relajar ni limpiar el limitador.
- Una sincronización privada de docs SEO omitió su harness enlazado y falló DocsReferences; se sincronizó el archivo existente del source y se repitió la suite aislada.
- La evidencia de agentes contenía URLs de preview en JSON embebido con slash escaping. Se corrigió el sanitizador, se añadieron pruebas de regresión y se reescribió evidencia sin autoridad. Los SHA de producto/assets se conservaron y los manifests finales se regeneraron.
- Un runtime 8.3 extraído con ini vacío activaba short_open_tag y rompía el XML del sitemap. Se configuró Off,
  igual que el runtime habitual, y se repitieron SEO/sitemap del E2E-03. Una copia inicial excluía también
  `.env.example`; se corrigió la receta antes de la suite final.
- E2E-07 esperaba 52 tools de su fase histórica; el registro vigente tiene 59. Se actualizó la expectativa
  y se repitió el recorrido, sin cambiar producto para satisfacerla.
- La instalación limpia desactiva correo y no trae claves reCAPTCHA. La fixture habilita mail log y claves
  Google de prueba; se descartaron los envíos `skipped` del primer intento y se repitió el navegador completo.
- Codex intentó un `changes` serializado como string y recibió el rechazo correcto. La indicación se hizo
  explícita como objeto; la conexión de formularios se comprobó con herramientas sin errores. Un intento
  de aprobar la traducción tras cambiar su fuente fue rechazado por revisión de campos; no se debilitó el
  contrato. El enlace publicado correcto se verificó mediante lectura y navegador.
- Una ejecución de composer test comenzó brevemente con directorio original por error de invocación;
  se detuvo. Su `config:clear` alcanzó el source; no tocó datos reales (testing SQLite) ni `.env`. Por ello
  no se afirma que el cache del source permaneció intacto. Todas las ejecuciones aceptadas usan copias.

El único límite simulado en el envío es Google reCAPTCHA; mail log registra el correo local y no certifica
entrega a proveedor. OAuth real, segundo factor, grants y recuperación se comprueban en acumulados; bearer
nativo de Codex no certifica OAuth propio del cliente. DB y filesystem no son atómicos juntos: snapshot
puede comprometer DB antes de reemplazar public disk; se conserva la receta de recovery coordinado.
Las propuestas legacy con fingerprint desconocido no se aprueban ciegamente; se restauran como propuesta
nueva y se revisan, según [update-recovery](../operations/update-recovery.md).

## Reproducir y entregar

`tests/E2E/phase-eleven-run.py init` prepara full/essential en 8022/8023 y schemas generados con guard
`ruweb_phase0_<12 hex>`. El bootstrap de fase 10 sólo admite esos puertos en la copia privada y rechaza
cualquier raíz original. Para cada perfil: panel, clients create/contact/snippet/workflow, browser initial,
control mail, clients submissions/deactivate, browser final, protocolo/permission, recovery y snapshot,
browser recovered. `cleanup` destruye schemas, procesos, claves, dumps, snapshots y logs privados.

`phase-eleven-replay-ten.py` adapta las recetas durables de fase 10 a estado propio, 8034/8035 y evidencia
propia; aplica sólo en bootstrap privado los puertos guardados. `phase-eleven-accumulated.py` conserva las
recetas por fase/perfil y escribe fingerprints y registros sanitizados. Los scripts SEO conservan muestras,
comparación y contexto, con limpieza propia.

[Guía final de verificación](../operations/release-verification.md),
[actualización/rollback](../operations/update-recovery.md), [perfiles](../concepts/admin-profiles.md) y
[conexión/recovery de agente](../operations/agent-access.md) son el handoff operativo. VERSION/changelog
se cierran tras el gate aceptado del coordinador; un cambio posterior de comportamiento exige repetir afectados.

Los manifests finales [candidate-fingerprints](phase-11-evidence/candidate-fingerprints.json) y
[evidence-digests](phase-11-evidence/evidence-digests.json) fijan fuentes, harness, assets y evidencia saneada.
`phase-eleven-final-check.py` verificó el candidato antes del cierre de VERSION: drift, cobertura acumulada, runtime y JSON embebido sin bearer/preview
privado. El tar de `local-artifact.json` es un borrador local identificado como 0.54.2; el artefacto definitivo
1.0.0 se genera desde el commit local final tras la aceptación independiente. La prueba de release con
SHA del commit y del paquete se guarda fuera del commit en `/Users/alemanydev/Developer/ruweb-cms-releases/`,
para evitar un checksum circular; el stamp post-commit del changelog no se incorpora al paquete.

La procedencia de las primeras copias se conserva en `phase-eleven-fingerprint-results.json` dentro de
su propio directorio de ejecución. Un observer leyó los archivos de cada copia activa y escribió el resultado
en su storage privado, que la misma ejecución archivó antes de cleanup; su receta se preserva como
`phase-eleven-observe-fingerprints.py`. El checker final contrasta esas capturas y las posteriores capturas
integradas con los SHA finales, incluidos validator y dispatcher MCP. Los tres recorridos que carecían de
captura (02 ambos y04 Essential) se repitieron con captura integrada y pasaron, incluido Codex real en04; no se reconstruyeron huellas retroactivas.

El cierre autorizado sólo cambia metadata de VERSION/CHANGELOG y estado documental respecto al gate.
`VersionFileTest` e initialize/capabilities verifican 1.0.0 en Full y Essential en copias privadas, sin
servidor ni conexión a datos reales. Los fingerprints del candidato permanecen como prueba inmutable del
gate previo; el release-proof externo enlaza el commit y documenta la única diferencia funcional de huella:
VERSION de 0.54.2 a 1.0.0, sin cambios en aplicación ni assets.
