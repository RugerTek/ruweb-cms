# Fase 3 — Resultados de aceptación

Fecha: 2026-10-10. Entrega: **0.49.0**. MCP permite operar contenido bilingüe, medios y layout en
`full` y `essential` con identidad explícita y sin sesión administrativa. Essential sigue experimental
hasta fase 4. No se implementaron propuestas MCP contra publicados, HTML de composición ni Custom Code.

## Entrega

El catálogo pasa de 23 a **36 herramientas**, idénticas para ambos perfiles. `cms_schema` descubre el
schema del template/tipo y un ejemplo; los locales se consultan/configuran mediante las mismas operaciones
que el panel, instalando sólo códigos del catálogo. La base permanece activa/pública y ningún idioma o
contenido se elimina al cambiar flags. Las colecciones instaladas se descubren sin permitir despacho
arbitrario de modelos: Post es la colección incluida con operaciones MCP; otras requieren su operación y
política explícitas en código.

`PostOperations` y `PostInput` extraen el flujo vertical del panel, REST y RPC: validación, contenido
saneado, asociaciones de categorías del mismo idioma, historial, publicación y programación. Páginas y
posts comparten creación de traducciones, source hashes, consulta de cambios y reconocimiento por campo.
La traducción conserva el grupo y medios, remapea taxonomías disponibles y nace como draft. Editar por MCP
un publicado sigue rechazado; el flujo de propuestas es fase 5.

Las respuestas editoriales exponen revisión. Las escrituras con `expected_revision` bloquean/releen el
registro y rechazan revisiones obsoletas; REST 409 y RPC `-32009` incluyen causa, campo, revisión esperada,
actual y recuperación. La validación/diagnósticos anteriores conservan su contrato. Los clientes anteriores
pueden omitir la revisión; los nuevos deben enviarla. Tipos de revisión malformados devuelven errores por
campo en páginas/posts/medios/menús. `operation_key` vincula identidad, operación e input de creación;
recibo y contenido se confirman juntos. Repetir input recupera la misma identidad, con contenido actual;
un input diferente con la misma clave falla sin duplicar efectos.

La subida usa multipart HTTP y el pipeline del panel, sin binarios/base64 en argumentos RPC. Un permiso
cifrado dura diez minutos y se vincula al usuario y bearer. Un permiso autoriza un archivo y sus reintentos
usan un recibo durable; REST directo conserva lotes. Se prevalidan extensión/MIME/tamaño/SVG y se generan
optimización/variantes. Fallar un lote o un commit exterior elimina sólo archivos nuevos y revierte filas.
El borrado protegido consulta usos completos —incluidos drafts, futuras publicaciones, locales, settings,
previews editoriales y versiones restaurables— y elimina archivos después del commit. La respuesta de
usos pagina 1–100 referencias; ese recorte nunca limita la protección del borrado. URL import no está incluido.

Menús y ajustes comparten operaciones con el panel. Menús validan idioma de destino, ciclos, revisiones y
reintentos; un menú con items no cambia de idioma. Ajustes de layout/contacto/SEO amplían una allowlist
explícita de valores públicos existentes, validan tipos y escriben la misma fila canónica. Los secretos
siguen redactados y no son escribibles. El lote es atómico; overrides sólo en claves localizables. CTA
conserva referencia de página o URI personalizada, y las URLs propias se guardan relativas también desde
REST/RPC/panel. No se duplicaron settings ni se agregaron defaults de cliente.

Las lecturas aceptan selección de campos y límites de respuesta. Recortes declaran `truncated_fields` y
recuperación, sin modificar datos almacenados. Claves JSON excesivas se identifican por hash. La revisión
queda íntegra. `content_read_field` pagina strings/arrays; cambios de origen paginan campos y rangos de
texto preservando los hashes necesarios para reconocerlos. Un payload recortado nunca se debe reenviar
como reemplazo de contenido.

## E2E-03 real

Se prepararon copias independientes con MySQL nuevo, storage/caches aislados, credenciales ficticias y
el mismo usuario/rol/scope en ambos perfiles. REST y JSON-RPC usaron bearer real sin cookies MCP.
Chromium/Playwright navegó anónimamente en escritorio y móvil y sólo permitió el origen de loopback.
Las copias excluyeron `.env`, `public/hot` y secretos del checkout; el servidor de desarrollo del proyecto
se preservó. La huella del código, build y VERSION identifica el candidato probado.

En ambos perfiles se descubrió un template válido, configuró inglés y subió un PNG real de 1600×900 por
permiso multipart. Se verificaron bytes optimizados/variantes, reintento sin duplicación y alt localizado.
Se creó ES, reintentó la creación, tradujo EN manteniendo grupo/medios y editó copy/SEO conservando una
sección hermana. Luego se tradujo también esa sección visible EN. Se probaron error de revisión obsoleta,
error recuperable de revisión de menú malformada y paginación real de usos en REST/RPC.

Se configuraron menús ES/EN y CTA portátil. Las previews sirvieron el idioma correcto sin sesión, mientras
las URLs públicas de drafts devolvieron 404 y el sitemap los omitió. Tras publicar, ambos documentos
mostraron copy/SEO/canonical/hreflang, imágenes responsive con bytes reales y hrefs locales exactos del
menú y CTA tanto en desktop como en el menú móvil. Se rechazó borrar el medio usado sin pérdida.
Otro registro se programó por HTTP; antes del instante estuvo ausente del público/sitemap y después de
avanzar sólo el reloj de la fixture apareció en ambos. No se usó SQL para los pasos editoriales.

El gate detectó y corrigió dos defectos públicos: el sitemap cacheado una hora no reflejaba nuevas
publicaciones/programación; ahora consulta visibilidad temporal por request. El header buscaba un slug
literal en vez de la ubicación canónica; desktop/móvil resuelven `location=header` igual que el footer.
La revisión también cerró normalización de URLs propias, rollback de archivos, límites de respuestas,
validación de revisiones y coherencia de idioma de menús antes del candidato final.

Evidencia final: [HTTP full](phase-3-evidence/e2e03/full/phase-three-results.json),
[HTTP essential](phase-3-evidence/e2e03/essential/phase-three-results.json),
[browser full draft](phase-3-evidence/e2e03/full/phase-three-browser-draft-results.json),
[browser full public](phase-3-evidence/e2e03/full/phase-three-browser-public-results.json),
[browser essential draft](phase-3-evidence/e2e03/essential/phase-three-browser-draft-results.json),
[browser essential public](phase-3-evidence/e2e03/essential/phase-three-browser-public-results.json) y
[huella del candidato](phase-3-evidence/e2e03/candidate-source-build-sha256.json).
Ocho capturas públicas permanecen en esas carpetas; no se conservaron URLs privadas de preview.

## Regresión, revisión y limpieza

Se repitieron E2E-00/01 en full y E2E-02 HTTP/browser y transiciones cacheadas en ambos perfiles.
E2E-02 empleó la fixture documentada equivalente a la sección de los agentes de fase 2; no incorporó
esa sección de ejercicio al starter ni fingió nuevas entregas de agentes. Se verificaron nuevamente
permisos, publicación, formularios/worker real con correo sólo a log, assets por perfil, operación de
medios, invalidez del perfil y preservación de contenido exacto al cambiar perfil y reiniciar servidor.
[Contexto de regresión](phase-3-evidence/regression/context.json) y
[transiciones full](phase-3-evidence/regression/full/phase-two-transition-results.json) /
[essential](phase-3-evidence/regression/essential/phase-two-transition-results.json).

La revisión independiente Sol 6.1 cerró sus hallazgos y no dejó bloqueantes. La suite final pasó **430 pruebas / 2802 verificaciones**; convenciones y diff whitespace OK. No hubo cambios JS; las pruebas de navegador usaron el build
copiado y sus hashes, sin assets del servidor Vite vivo. El entorno ejecutó PHP 8.5.4; verificar el mínimo
PHP 8.3 sigue pendiente para el candidato de release.

Las claves fueron revocadas y los esquemas nuevos eliminados mediante guards de nombre/manifiesto.
Se retiraron `.env`, config caches con credenciales, manifiestos, fixtures con previews y logs de servidor.
[Cleanup full](phase-3-evidence/e2e03/full/cleanup-result.json) /
[essential](phase-3-evidence/e2e03/essential/cleanup-result.json). Ningún dato del sitio real se modificó.

## Límites y reversión

Las herramientas nuevas necesitan migrar la tabla aditiva `mcp_operation_receipts` antes de habilitar
la entrega. Revertir consiste en deshabilitar entradas nuevas/conservar lectores y panel previos, sin
borrar contenido o medios. El perfil no concede permisos; read-only/draft-only siguen restringidos.

El recorrido prueba HTTP REST/JSON-RPC y navegador real. La validación de clientes externos completos,
OAuth/rotación/reconexión y los modos de conexión soportados se cierran en fase 9; no se promete
interoperabilidad adicional por el número de herramientas. El catálogo cliente debe redescubrirse tras
actualización. La importación de medios por URL permanece opcional y ausente.

Fase 10 debe decidir explícitamente conservar o excluir los recibos operativos de snapshots: contienen
actor, clave de operación, hash y referencia de resultado, sin bearer/ticket/secretos. Hoy siguen la regla
existente de exportar tablas no excluidas; no son credenciales ni cambian perfil. La migración/restore debe
probar su identidad/reintento junto con el resto de datos, sin reactivar permisos de subida expirados.
