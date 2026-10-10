# Fase 7 — Composición de páginas por MCP

Fecha: 2026-10-10. Entrega 0.53.0. E2E-07 final aceptado en full y essential sobre la fuente congelada tras los ajustes de revisión independiente. Commit local autorizado; sin push.

## Entrega verificable

Los templates `html`, `empty` y `composed` usan el registro y las operaciones editoriales existentes.
El catálogo sigue teniendo **52 herramientas** en ambos perfiles. HTML se guarda en
`sections.content.html`; la composición usa la lista ordenada `sections.composition.blocks`, con
IDs estables, tipo, versión, visibilidad y HTML o componente/params. Los componentes registrados son
`callout` (estructurado, con media ID) y `accordion` (interactivo, con `details` nativo y cierre del
hermano desde el código compilado compartido). `empty` conserva SEO y assets pero omite header/footer.

La prueba cambia copia, orden y parámetros mediante una propuesta exacta, solicita revisión y aprueba
con el administrador asignado. El contenido publicado permanece igual antes de aprobar. Después restaura
la versión histórica como draft y republica explícitamente; recupera IDs, orden, media y parámetros.
La traducción se modifica por MCP con título, SEO y copia HTML/estructurada/interactiva distintos en inglés; conserva el grupo, IDs y referencias de media, y deja la fuente española intacta. Un preview anónimo fijado a la versión inicial
sigue mostrando la versión inicial después de las ediciones y de la restauración. El borrado de media usada
se rechaza con 422. No se reconstruyen assets entre estas operaciones.

El fingerprint de copia semántica usa IDs de bloques y excluye configuración, orden, visibilidad y media;
el HTML se trata como fragmento completo. No se implementó CustomCode, diff por nodo ni editor visual
para estos templates. Al pasar essential a full, la URL real de edición redirige al historial editorial con
aviso traducido de compatibilidad y sin formulario de editor; el contenido no se convierte.

## Evidencia final

El build final se compiló antes de la ronda E2E final y no hubo rebuild durante los cambios editoriales; las copias usaron assets de producción sin `public/hot`. La ronda final usó
`app-kke07nZ7.css` y `app-P6f_VT5F.js`. El SHA-256 del build completo se conserva en
[build-fingerprint.json](phase-7-evidence/build-fingerprint.json) y coincide con los resultados de ambos
perfiles antes y después de editar/restaurar. Ese archivo también fija los hashes de fuente funcional y harness.

E2E-07 usó seis esquemas MySQL nuevos en tres rondas; la ronda final completa en ambos perfiles reemplaza
la evidencia E2E-07 inicial. Las regresiones acumuladas se ejecutaron durante la primera ronda sobre el
candidato previo a los últimos refuerzos de sanitización/atributos/fingerprint; no se presentan como una
repetición de esas regresiones sobre la fuente final. E2E-00/01 pasaron en full; E2E-03/05 pasaron en ambos
perfiles, incluidos sus stages de navegador draft/public y proposal/restored. E2E-02/04/06 no se repitieron.

Chromium real verificó los tres templates y la composición inglesa a 1440 y 390 píxeles. Comprobó markup
admitido, imagen `id`/`title` y alt con `>` preservados en el DOM, imágenes responsivas decodificadas,
bytes y content-type de archivos servidos, canonical, header/footer y ausencia de overflow. El CSS
`cms-grid-2` funciona solo, con dos columnas en desktop y una en móvil. El acordeón inicializa el módulo
compilado y un click real abre un item y cierra su hermano. Scripts, handlers, CSS inline, SVG, iframe,
URLs JavaScript (también codificadas) y data URLs no se admiten ni activan el marcador del navegador.
No hubo errores de página ni cargas de TinyMCE/editor-engine en la composición.

Resultados: [full](phase-7-evidence/full/phase-seven-results.json),
[essential](phase-7-evidence/essential/phase-seven-results.json),
[navegador full final](phase-7-evidence/full/phase-seven-browser-restored-results.json),
[navegador essential final](phase-7-evidence/essential/phase-seven-browser-restored-results.json),
[compatibilidad al cambiar de perfil](phase-7-evidence/essential/phase-seven-browser-full-ui-results.json).

Capturas finales de composición:

- [Full desktop](phase-7-evidence/full/phase-seven-full-restored-es-composed-1440.png).
- [Full móvil](phase-7-evidence/full/phase-seven-full-restored-es-composed-390.png).
- [Essential desktop](phase-7-evidence/essential/phase-seven-essential-restored-es-composed-1440.png).
- [Essential móvil](phase-7-evidence/essential/phase-seven-essential-restored-es-composed-390.png).
- [Aviso full, sidebar móvil cerrado](phase-7-evidence/essential/phase-seven-full-compatibility.png).

La suite final informada por el agente coordinador pasó **496 tests / 3695 aserciones**; convenciones y
diff check pasaron. Las **17 pruebas JavaScript** y el build de producción también pasaron. El E2E complementa esas pruebas con HTTP, MySQL, DOM y assets reales. El procedimiento
reproducible está en [tests/E2E/README.md](../../tests/E2E/README.md).

## Aislamiento y limpieza

Todas las operaciones fueron contra copias desechables, con vendor, storage y caches propios, en loopback
8018. El fixture rechaza esquemas existentes y sólo admite nombres generados `ruweb_phase0_*`. Se leyó
la cuenta MySQL del `.env` normal mediante una copia privada; no se alteraron el `.env`, perfil, caches
ni base real del repositorio. No corrieron workers persistentes durante la transición; se regeneraron
config/routes caches y se inició un proceso nuevo del servidor. Un despliegue real debe reiniciar workers.

Todos los servidores se detuvieron; el cleanup guardado eliminó los seis esquemas y revocó sus claves.
Se borraron copias, manifests, previews privados, `.env`, caches con credenciales y logs de servidor/correo.
La evidencia retenida contiene sólo resultados saneados, paths de scripts, hashes y capturas públicas o
panel operativo. No contiene keys, cookies, URLs de preview ni logs privados.

## Revisión y reversión

La revisión independiente verificó el saneamiento DOM, atributos de imágenes admitidos, errores de forma,
null patches, huellas semánticas, límites y evidencia final. Todos sus hallazgos quedaron resueltos antes del
cierre; el coordinador aceptó el gate. No se aplicaron migraciones ni conversiones de contenido. Para revertir
escrituras, conservar el renderer de formatos ya publicados; restaurar versiones mediante las operaciones
editoriales y retirar la publicación explícitamente cuando corresponda. La fase 8 permanece sin iniciar.
