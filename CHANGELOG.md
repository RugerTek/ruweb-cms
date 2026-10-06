# Changelog

Este changelog empieza **desde el primer snapshot funcional** del proyecto y se mantiene **por commit**.

- Esquema: **SemVer** (pre-1.0): `0.MINOR.PATCH`
- Regla práctica: **MINOR** para features/cambios funcionales, **PATCH** para fixes/docs/ajustes menores.
- Nota: el commit raíz `4c90c74` sólo creó `.gitignore`/`README` (scaffolding), por eso no se considera un “release” funcional.

## Unreleased

## 0.42.0 - 2026-10-05

- El preview del Live Editor oculta el admin bar y su espacio superior; bloquea enlaces, ventanas nuevas y envíos de formularios sin interferir con acordeones o sliders. Se evita abrir otro editor dentro del preview y se permite refrescar `about:srcdoc`.
- Páginas y posts reservan el contenido en la base de datos mientras se editan, con renovación, liberación al salir y expiración a los dos minutos sin conexión. Otra sesión recibe un aviso con el nombre del editor; guardar o eliminar exige el token vigente.
- Si se pierde el bloqueo, se deshabilita guardar y se conservan los cambios locales y el aviso al salir. Las respuestas antiguas del preview no sustituyen la más reciente y los cambios hechos durante un guardado siguen pendientes.
- Los bloqueos temporales quedan fuera de los snapshots. Se documenta el contrato compartido y se agregan pruebas de exclusividad, expiración, navegación y recuperación del editor.

## 0.41.0 - 2026-09-13 (5c96887)

- Nuevas pestañas **Redirecciones** y **Monitor 404** en Developer tools (solo admins) con las reglas 301/302 del sitio y los 404 que las alimentan. Un sitio que reemplaza a otro hereda sus enlaces: los marcadores, los backlinks y las URLs indexadas del sitio viejo tienen que aterrizar en la URL nueva y no en un 404. Va en Developer tools y no en el menú principal porque es una herramienta de migración, no contenido del día a día. Las reglas y el monitor son pestañas separadas: una se edita, la otra se consulta.
- La importación CSV queda plegada en un acordeón —se usa una sola vez, al migrar— para que el alta manual, que es el caso frecuente, ocupe el ancho completo. El acordeón se abre solo si la subida vuelve con error.
- Las reglas se resuelven en un único punto (`App\Support\RedirectResolver`) desde dos entradas: el middleware `redirects` sobre las rutas públicas —para que una regla gane sobre una URL que todavía resuelve— y el render de `NotFoundHttpException` en `bootstrap/app.php` para las rutas que ya no existen. Sólo `GET`/`HEAD`, sólo reglas activas, y las rutas de `cms.redirects.ignore_paths` (panel, `mcp`, OAuth, `storage`, `build`, `/up`) quedan fuera.
- `source_path` se guarda siempre normalizado (barra inicial, sin barra final, sin query ni fragmento), así que pegar la URL absoluta del sitio viejo crea la misma regla que escribir la ruta. La query string entrante se arrastra al destino cuando la regla no trae una propia, para no perder los parámetros de campaña en el 301.
- Se rechazan las reglas que hacen bucle: origen igual al destino, y destino cuya ruta ya redirige de vuelta al origen. La portada no se redirige desde esta pantalla.
- Monitor 404: una fila por ruta inexistente con contador, referencia y última visita, y una acción **Redirigir** que precarga el formulario con esa ruta. Ese es el flujo previsto tras una migración: publicar, esperar tráfico y redirigir lo que efectivamente lo recibe. Se apaga con `CMS_MONITOR_NOT_FOUND=false`.
- Importación masiva por CSV (`source_path`, `target_url`, `status_code`, `is_active`), con detección de delimitador, actualización de las filas existentes y resumen de creadas/actualizadas/omitidas en vez de abortar ante una fila inválida. Incluye descarga del CSV de ejemplo.
- Nuevo bloque `cms.redirects` en el registro (`status_codes`, `monitor_not_found`, `ignore_paths`, `per_page`): agregar un código de estado o excluir un prefijo de ruta es una sola línea de configuración.
- El bloque de Migración de Developer tools estaba en un `@else`, así que se renderizaba en cualquier pestaña que no fuera Sistema. Pasa a `@elseif` y cada pestaña muestra sólo lo suyo.
- Documentación canónica nueva en `docs/operations/redirects.md`, enlazada desde `docs/README.md`, `docs/content/seo.md` y la tabla de ruteo de `AGENTS.md`. Tests en `RedirectsTest`: resolución, normalización, precedencia sobre una página publicada, exclusión del panel, bucles rechazados, importación CSV, separación de pestañas y precarga del formulario desde un 404.

## 0.40.1 - 2026-09-13 (2bc173d)

- `.gitignore` ignoraba `.claude/` entero, así que el symlink `.claude/skills` no viajaba en el repositorio: un clon nuevo dejaba a Codex con sus skills y a Claude Code sin ninguno. Ahora se ignora el contenido de `.claude/` salvo ese symlink.
- `DocsReferencesTest` verifica que ambos agentes encuentren los skills en un clon limpio: que `.claude/skills` resuelva a `.agents/skills` y que esté trackeado por git.
- La descripción de `check-translations` pone el síntoma al frente (una clave cruda visible en el admin, un label sin traducir), que es como se pide el arreglo en la práctica.

## 0.40.0 - 2026-09-13 (3ffab81)

- La documentación pasa a estar escrita para agentes: `AGENTS.md` en la raíz (leído por Claude Code y Codex, con `CLAUDE.md` como symlink) reúne comandos, reglas duras del repo y una tabla de ruteo tarea → skill → doc, de modo que un clon nuevo no necesita que nadie le indique qué archivo leer.
- Los `docs/` se reorganizan en `concepts/`, `content/`, `admin/` y `operations/`, con `docs/README.md` como mapa de qué decide cada documento. Contenido reescrito contra el código: se eliminan los bloques `Purpose` y `Scope Boundary`, las reglas quedan en forma verificable y la regla de placeholders, que estaba repetida en ocho archivos, vive sólo en `docs/content/images.md`.
- Los prompts de `docs/skills/` y el skill suelto de changelog se convierten en 12 skills reales bajo `.agents/skills/` (symlinkeado a `.claude/skills/`), que ambos agentes activan solos por su descripción: `create-page`, `create-template`, `create-section`, `create-collection`, `create-form`, `create-config`, `create-layout-field`, `update-content`, `translate-content`, `check-translations`, `review-conventions` y `changelog-version`.
- Nuevo comando `php artisan cms:check-conventions` con las reglas deterministas de la documentación: throttle y anti-spam en formularios, partials y traducción de cada formulario registrado, paridad `lang/es`↔`lang/en`, templates registrados sin vista, imágenes hardcodeadas en templates, URLs absolutas en seeders y sincronía entre `VERSION` y el changelog. Acepta `--staged` y `--since`.
- Hooks versionados en `.githooks/` (`composer setup` los activa): **pre-commit** corre `php -l` sobre lo staged más `cms:check-conventions --staged`, y **post-commit** completa el SHA de la entrada de changelog que quedó sin él, dejando el cambio para el commit siguiente sin hacer `amend`.
- Las descripciones de los skills se validaron con sesiones frías reales (`claude -p` en modo solo-lectura, pedidos naturales sin mencionar archivos): 21 de 22 corridas eligieron el skill correcto, incluidos dos controles negativos que no debían disparar ninguno. El único fallo, un label crudo del admin que se iba a `grep` en vez de a `check-translations`, se corrigió poniendo el síntoma al frente de la descripción y se reverificó con tres fraseos.
- Nuevos tests `ConventionsTest` y `DocsReferencesTest`: el repo debe cumplir sus propias reglas, y todo link o ruta citada en la documentación y en los skills tiene que resolver.

## 0.39.0 - 2026-09-15 (8c5303f)

- Nuevas pantallas **Header** y **Footer** en el admin (solo admins) que centralizan todo lo editable de cada región: el logo compartido, el botón de llamada a la acción del header, el texto y la línea de copyright del footer, y tarjetas de acceso directo hacia el menú de la ubicación correspondiente y hacia Ajustes > Contacto. El código de ambas regiones se versiona; el cliente edita solo datos.
- Nueva pestaña **Contacto** en Ajustes con teléfono, WhatsApp, email, dirección, coordenadas y un repeater de redes sociales: el cliente agrega solo las redes que tiene, eligiéndolas de un catálogo central (`App\Support\SocialNetworks`) con íconos incluidos. Agregar una red nueva es una sola entrada en el catálogo.
- Nuevo tipo de setting `social_links` y helper `App\Support\BusinessContact` como único punto de lectura de los datos del negocio (enlaces `tel:`, `mailto:`, `wa.me` y mapa derivados en un solo lugar), más el componente `<x-social-links>`.
- El footer público muestra logo, texto, menú de la ubicación `footer`, datos de contacto, redes y copyright con `{year}` y `{site}`; el header muestra el botón opcional. Los bloques vacíos no se renderizan.
- El botón del header es condicional (interruptor "Mostrar botón") y su destino usa el selector semántico de la guía UI/UX: páginas publicadas del sitio, opción Personalizado con URL manual, y "abrir en pestaña nueva". Partial reutilizable `admin.partials._link-destination` y resolutor `App\Support\LinkDestination`.
- Footer: texto bajo el logo en texto enriquecido y crédito de desarrollo opcional (interruptor + texto enriquecido con enlace, que puede abrir en otra pestaña). Nuevo tipo de setting `rich_text`, sanitizado al guardar.
- Ubicación del negocio con un mapa (Leaflet + OpenStreetMap, sin API key): el cliente suelta o arrastra el pin y las coordenadas se completan solas. Nuevo tipo de setting `map_point` y configuración en `cms.maps`.
- El ítem Ajustes del sidebar abre siempre la pestaña General.
- La admin bar pública suma el desplegable **Editar layout** con accesos a Header y Footer, solo CSS.
- El renderizado de campos de settings se extrae a un partial compartido y el guardado a un trait común, para que un mismo setting se vea y valide igual en cualquier pantalla.
- Documentación canónica nueva en `docs/layout-header-footer.md`, skill `docs/skills/create-layout-field.md` y ajustes en `docs/settings.md`, `docs/menu-guide.md`, `docs/live-editor-ui-ux.md` y `docs/index.md`.

## 0.38.1 - 2026-09-14 (76da042)

- Las opciones del motivo en el editor de contacto se listan como filas planas con el input, los controles de orden y el botón de borrar, sin acordeón por opción. La regla queda escrita en la guía de UI/UX del editor para los repeaters de un solo campo.

## 0.38.0 - 2026-09-14 (4093739)

- Módulo de formularios públicos con la página de contacto como implementación de referencia: los campos y el comportamiento viven en el código, y el Live Editor solo edita etiquetas, texto del botón, mensaje de éxito, opciones del motivo y notificaciones.
- Nuevo menú Envíos en el admin con una pestaña por formulario, contador de no leídos, filtros por estado, búsqueda y rango de fechas, y una pantalla de detalle con los metadatos de cada envío.
- Capa anti-spam compartida por todos los formularios: honeypot con tiempo mínimo de envío, límite por IP y Google reCAPTCHA v3 configurable desde la nueva sección Seguridad de los ajustes.
- Notificación por email de cada envío, activable desde la sección del formulario en el editor, a través del sistema de correo existente con Brevo.
- Repeater genérico para listas de opciones administrables en el editor.
- Documentación canónica en `docs/forms.md` y skill `docs/skills/create-form.md` para crear formularios nuevos.

## 0.37.4 - 2026-09-13 (06d828b)

- Restaurar un snapshot conserva el `.gitignore` de `storage/app/public`, que antes quedaba borrado al reemplazar los uploads.

## 0.37.3 - 2026-09-13 (68c3818)

- Se corrige un error 500 en la pantalla de revisión de snapshots cuando había riesgos que mostrar; el test del flujo de subida ahora renderiza al menos un riesgo.

## 0.37.2 - 2026-09-13 (423c581)

- El modo mantenimiento durante un restore se controla con `cms.snapshots.maintenance_mode` y queda apagado en tests, que antes ponían el sitio local en 503 mientras corría la suite.

## 0.37.1 - 2026-09-13 (bd8f225)

- La carpeta base del módulo de snapshots se configura con `cms.snapshots.root` (`CMS_SNAPSHOTS_ROOT`), y los tests la apuntan a un directorio temporal aislado. La suite ya no borra los snapshots reales de `storage/app/private/snapshots` del desarrollador.

## 0.37.0 - 2026-09-13 (edb6070)

- Cada cambio de contenido (páginas, posts, media, settings, menús, taxonomías, idiomas) deja una revisión con hash en la tabla `content_revisions`, sin payload, para poder comparar copias entre instalaciones.
- Al inspeccionar un snapshot, el sistema dice si contiene todos los cambios del destino (avance limpio) o lista los cambios que se perderían y exige aceptar el riesgo; ya no hace falta comparar copias a mano.
- Las revisiones se podan solas: se conservan 90 días o al menos 10 mil filas, lo que sea mayor, desde `snapshot:prune`.
- El panel de sistema muestra la cantidad de revisiones y la cabeza actual.

## 0.36.0 - 2026-09-13 (6bd363d)

- Los enlaces e imágenes del editor que apuntan al propio sitio se guardan como rutas relativas a la raíz, y las variantes responsive construyen su URL al renderizar; el contenido ya no queda atado al dominio donde se creó.
- Al restaurar un snapshot de otro dominio, las URLs guardadas se reescriben al dominio de la instalación destino, tanto en texto como en columnas JSON. `snapshot:restore` acepta `--url-from` y `--url-to` para forzarlo.
- Nuevo comando `cms:rewrite-url --from --to` para reescribir el dominio en una base viva, con `--dry-run`.
- Los enlaces personalizados del menú también se normalizan a relativos si apuntan al propio sitio.

## 0.35.0 - 2026-09-13 (01a383a)

- La versión de la aplicación vive en el archivo `VERSION` y se expone como `config('cms.version')`; el panel de sistema y el nombre del archivo descargado la muestran.
- Cada snapshot guarda la versión, la lista de migraciones aplicadas y las columnas de cada tabla en su manifest.
- Al inspeccionar, un snapshot de una versión más nueva, con migraciones desconocidas o con columnas que el destino no tiene se bloquea; uno más viejo se puede restaurar aceptando el riesgo y viendo qué migraciones se saltaría.
- Un test verifica que `VERSION` coincide con la última entrada versionada del changelog.

## 0.34.0 - 2026-09-13 (c362291)

- Restaurar un snapshot ahora pasa por una inspección previa: el panel muestra un resumen y los riesgos detectados, y cada advertencia se acepta por separado antes de tocar nada.
- Nuevo comando `snapshot:inspect` y `snapshot:restore` imprime el mismo reporte, pide confirmación por riesgo en modo interactivo y acepta `--accept=<clave>` o `--yes` para scripts.
- Un snapshot ya no reemplaza la tabla `migrations` del destino, que antes quedaba desfasada al restaurar una copia más vieja.
- Nuevo comando `snapshot:prune`, programado a diario y disponible como botón en Developer Tools, que borra subidas pendientes y carpetas de trabajo viejas y conserva solo los 3 pre-backups más recientes.
- Documentación canónica del módulo en `docs/snapshots.md`.

## 0.33.1 - 2026-09-13 (859e04c)

- Se corrige el mensaje de confirmación al quitar un logo, que mostraba barras invertidas alrededor del nombre.

## 0.33.0 - 2026-09-13 (aa32f13)

- El contenido de los posts se edita con un editor WYSIWYG (TinyMCE) en lugar de un textarea plano, con títulos, listas, citas, enlaces, tablas y bloques de código.
- Insertar una imagen abre directamente la media library: no se piden URLs ni se suben archivos pegados.
- El HTML se sanea en el servidor con una allowlist antes de guardarse, venga del formulario, de la API MCP o de un seeder.
- El blog público pasa a renderizar el HTML del post; el contenido plano anterior se sigue mostrando igual que antes.
- Se instala el plugin de tipografía de Tailwind, que las vistas ya asumían al usar `prose` sin que estuviera presente.

## 0.32.0 - 2026-09-13 (08ca037)

- El botón de refrescar caché pasa a estar también dentro del panel de administración, además de la barra pública, visible solo para administradores.
- Cualquier formulario marcado con `data-confirm` se confirma con el modal compartido en lugar del diálogo nativo del navegador.

## 0.31.0 - 2026-09-13 (b4ddf20)

- Salir del editor con cambios sin guardar ahora abre un aviso con `Seguir editando` y `Salir sin guardar`, en vez de dejar que se pierdan en silencio.
- El modal compartido de confirmación pasa a aceptar título, mensaje, etiquetas y tono, así que sirve para cualquier acción que necesite confirmarse.

## 0.30.0 - 2026-09-13 (e64500d)

- La sección de features de la home suma un carrusel de logos en bucle infinito, responsive y con pausa al pasar el cursor.
- El carrusel se administra con un repeater: cada logo es un acordeón propio con su imagen de la media library, su nombre, y controles para ordenar y quitar.
- Quitar un logo pide confirmación en un modal compartido en lugar de borrarse de una, y el cambio se aplica al guardar la página.
- La home de ejemplo llega con cuatro logos usando el placeholder cuadrado.

## 0.29.1 - 2026-09-13 (6da34fe)

- `docs/image-strategy.md` documenta los tres placeholders, con qué orientación corresponde a cada tipo de slot y cómo se siembran y reparan.
- Las guías de páginas, contenido, plantillas, colecciones, blog, settings, multilenguaje, MCP y los skills apuntan a esa convención donde hablan de campos de imagen.
- Se incorpora `docs/live-editor-ui-ux.md`, el contrato de UI/UX del Live Editor que hasta ahora existía sin versionar.

## 0.29.0 - 2026-09-13 (893c81f)

- Una instalación nueva siembra tres imágenes placeholder (horizontal, cuadrada y vertical) en la media library, con su versión WebP y sus variantes responsive.
- Nuevo comando `cms:placeholders` que siembra los placeholders y restaura sus archivos cuando el registro existe pero el archivo no, como pasa al clonar el repo con `storage/app/public` vacío.
- La home de ejemplo llega con su imagen destacada y su hero completos, en vez de marcos vacíos.
- `composer setup` ahora corre `storage:link` y `db:seed`, así que un clon queda usable sin pasos manuales.
- `cms:install` avisa al terminar si faltan los assets compilados, que hasta ahora dejaban el sitio devolviendo 500 sin explicación.

## 0.28.0 - 2026-09-13 (5bb8038)

- La media library suma un campo `Enlace` con botón para copiar la URL pública del archivo al portapapeles.
- Nuevo filtro por fecha que lista solo los meses que tienen archivos y se combina con los filtros de tipo y búsqueda.
- Cuando un filtro no devuelve resultados se muestra un estado propio con acción para limpiar filtros, en vez del mensaje de biblioteca vacía.

## 0.27.0 - 2026-05-17 (b071d5b)

- La URL de login del admin pasa a vivir en la raíz del sitio y se resuelve dinámicamente desde Settings.
- El acceso no autenticado a `/admin` ahora devuelve una 404 pública sin exponer la ruta real de login.
- La validación de `admin_login_path` rechaza colisiones con locales y slugs de páginas del locale base.
- La configuración de login se crea y reconstruye automáticamente cuando falta, incluyendo soporte para route cache.
- Los tests de admin y smoke cubren el nuevo flujo de login público y la reconstrucción de rutas en caliente.

## 0.26.0 - 2026-05-17 (033179f)

- Nueva setting `URL de login` en Administración para mover el acceso al panel fuera de `/admin/login`.
- El acceso no autenticado a rutas protegidas de `/admin` ahora responde `404` y ya no revela la URL de login personalizada.
- La ruta de login activa pasa a resolverse de forma dinámica en redirects, emails y enlaces internos del admin.
- La checklist del dashboard ahora marca como pendiente la personalización de la URL de login hasta que se configure.
- Validación endurecida para rechazar segmentos reservados incluso si llegan con mayúsculas o formatos equivalentes.

## 0.25.0 - 2026-05-17 (808ba6e)

- Sidebar admin reordenado y simplificado: `Posts` agrupa categorías y `Archivos` agrupa salud de media; `Perfil` se mueve al footer del usuario.
- Checklist del dashboard ampliado con tareas de branding: favicon, logo del sitio e imagen para compartir por defecto.

## 0.24.0 - 2026-05-17 (ae7beac)

- Centro de control editorial reorganizado en tabs separadas para completitud de contenido y traducciones pendientes/desactualizadas.
- Dashboard admin reemplazado por métricas accionables de foto destacada, SEO title, SEO description y traducciones pendientes, con cobertura por idioma cuando aplica.
- Modelo SEO de Pages y Posts unificado en SEO title y SEO description, reutilizados para meta, Open Graph y Twitter.
- Live editor simplificado para editar sólo SEO Title y SEO Description, eliminando campos OG duplicados.
- Migración agregada para normalizar metadata SEO existente y limpiar claves `og_*` persistidas.
- Contratos MCP, documentación y tests actualizados para el nuevo flujo editorial y SEO.

## 0.23.0 - 2026-05-17 (eae84d2)

- Dashboard admin corregido para contar sólo Pages y Posts del locale base, evitando duplicar traducciones en los totales.
- Nueva tabla de checklist de setup inicial con pendientes arriba, completadas opcionales abajo y enlaces directos a Analytics y Email.
- Checks de arranque agregados para Google Tag ID, Meta Pixel, Search Console, email sending y soporte HTTP/2.
- Listado de taxonomías alineado con Pages y Posts mostrando badges y acciones de traducción por idioma.
- Cobertura de tests agregada para los nuevos contadores y el comportamiento del checklist del dashboard.

## 0.22.0 - 2026-05-15 (8783ad5)

- Sistema multilenguaje extendido a Pages, Posts, Taxonomies, Menus y Settings con locales instalables, grupos de traducción y fingerprints por campo.
- Admin actualizado con gestión de idiomas, control editorial de traducciones, badges por locale y acciones de crear/editar traducciones en los listados principales.
- Frontend público alineado al locale activo en navegación, blog, SEO, sitemap y settings localizados, con copy público traducible por idioma.
- Contrato y documentación de multilanguage actualizados para reflejar el comportamiento real del código y cubrir el flujo esperado para agentes.
- Cobertura de tests agregada para rutas públicas localizadas, remaps de traducciones, menús localizados y detección de contenido desfasado.

## 0.21.0 - 2026-05-04 (f6ceba3)

- La pantalla de `Profile` fue refactorizada para usar tabs separadas de Información y Seguridad.
- Información de cuenta simplificada a `Full name`, `Email` y `Role` de solo lectura.
- Cambio de contraseña movido a una tab de Seguridad dedicada con una UI más ordenada.
- La gestión de `MCP API Key` se mueve desde `/admin/profile` a `/admin/claude-mcp`.

## 0.20.0 - 2026-05-04 (d968920)

- Nueva sección admin `Developer tools` con tabs de diagnóstico del sistema y migración entre entornos.
- Exportación portable de snapshots `.appbackup` con `manifest`, `checksums`, dump JSONL por tabla y `uploads` públicos.
- Restore seguro con validación de ZIP, checksums, tablas compatibles, pre-backup automático, maintenance mode y rollback de uploads.
- Nuevos comandos Artisan `snapshot:create` y `snapshot:restore` para operar backups grandes por CLI.
- Documentación de deploy actualizada para separar flujo de código por Git y datos dinámicos por snapshots.

## 0.19.2 - 2026-04-28 (7bcb7ae)

- Completado el seguimiento por commit del changelog resolviendo el SHA pendiente de la entrada anterior.

## 0.19.1 - 2026-04-28 (c2a9737)

- Protegida la suite de tests contra config cache local, limpiando `bootstrap/cache` antes de bootear PHPUnit.
- Evitado que `RefreshDatabase` pueda correr contra la base MySQL local cuando existe `config.php` generado por `php artisan optimize`.

## 0.19.0 - 2026-04-28 (ac38c2b)

- Agregado botón de refresco de caché de Laravel en la admin bar pública, visible solo para admins.
- Corregido el idioma de la admin bar pública para que use `admin_locale` también fuera de rutas `/admin`.
- Actualizado `.env.example` para alinearlo con las variables reales de OAuth, mail e imágenes del proyecto.
- Actualizado `reqs.md` para reflejar la nueva capacidad de refresco de caché y el comportamiento de localización del admin bar.

## 0.18.0 - 2026-04-28 (448cb32)

- Agregadas acciones de borrado directo en las tablas de Pages y Posts del admin.
- Cada fila ahora expone la convención completa de acciones: abrir si existe detail público, editar y borrar con confirmación.
- Skill y documentación de colecciones actualizadas para exigir esta convención en nuevos listados admin.

## 0.17.0 - 2026-04-28 (dd27976)

- Tema de acento del sistema cambiado de verde esmeralda a azul `sky-600`.
- Botones, tabs activas, focus states, badges y enlaces del admin fueron alineados al nuevo color de acento.
- Ajustado el glow del login y otros acentos derivados para mantener coherencia visual con el nuevo tema.

## 0.16.3 - 2026-04-28 (63266b4)

- Documentación de arquitectura y guías alineada con las capacidades reales del sitio actual.
- Referencias a comportamientos no implementados fueron ajustadas para evitar desfasajes entre docs y producto.

## 0.16.2 - 2026-04-28 (9a9ef3f)

- Rebrand del starter desde Flaxt a Rüweb en configuración, textos visibles, seeders y documentación.
- Defaults de instalación actualizados para usar `ruweb-cms` y `admin@ruweb.local` en lugar de los valores anteriores.
- Prefijo de API keys MCP y nombre expuesto por el servidor MCP alineados con la nueva identidad del proyecto.

## 0.16.1 - 2026-04-28 (f43a785)

- Starter público simplificado para usar únicamente `favicon.ico` por defecto.
- Eliminadas referencias rotas a variantes de favicon que ya no existen en `public`.
- El favicon configurado desde Settings ahora publica el MIME correcto según el archivo real subido por el admin.
- Limpieza de assets públicos sobrantes del starter, incluyendo el logo no utilizado.

## 0.16.0 - 2026-04-27 (5370c6c)

- Contrato editorial común para Pages y Posts mediante interfaces `Publishable`, `Seoable`, `Mediable` y `Previewable`.
- Trait compartido para la regla de publicación estándar (`status = published` y `published_at <= now()`).
- SEO público actualizado para consumir entidades `Seoable` sin depender del tipo concreto de contenido.
- Documentación del contrato editorial y guías enlazadas para nuevas colecciones.
- Cobertura de tests para contratos, publicación y metadata SEO de Pages y Posts.

## 0.15.1 - 2026-04-27 (e080e07)

- Live editor: eliminado auto-guardado en localStorage; los cambios solo persisten al guardar explícitamente.
- Live editor: aviso nativo del navegador al intentar salir con cambios pendientes.

## 0.15.0 - 2026-04-27 (d6e3a46)

- Paginación por páginas en el modal de la media library, reemplazando el infinite scroll anterior.

## 0.14.0 - 2026-04-27 (97bf5c5)

- Configuración SEO consolidada en la tab General de Settings (antes dispersa en tab propia).
- Test de email movido a un controller dedicado.

## 0.13.0 - 2026-04-27 (a3e38d2)

- Alertas del panel admin estandarizadas visualmente.
- La tab activa en Settings persiste entre recargas de página.

## 0.12.2 - 2026-04-27 (0538ecd)

- Scrollbars del live editor estilizados para coincidir con el tema oscuro.

## 0.12.1 - 2026-04-21 (b86aec6)

- Documentación del warning de persistencia de `content_json` en el live editor.

## 0.12.0 - 2026-04-17 (15f3f67)

- Sistema de email transaccional con Brevo: transport HTTP API propio (sin paquetes externos), solo requiere pegar una API key en el admin para funcionar.
- Tab **Email** en Settings con API key cifrada, from address/name, master switch, acordeón de instrucciones paso a paso y botón "Enviar email de prueba".
- Password reset completo desde el panel admin (flujo forgot → email → reset con vistas propias).
- Welcome email al crear usuarios: el nuevo usuario recibe sus credenciales por email y su cuenta queda verificada de inmediato.
- `User` implementa `MustVerifyEmail`; backfill automático en seeder para usuarios existentes.
- Comando `php artisan mail:test {email}` para verificar la configuración desde CLI.
- Documentación actualizada: `settings.md` (nuevo tipo `password` y grupo `email`), `architecture.md` (nuevos directorios `app/Mail/`, `emails/`), `maintenance.md` (comando `mail:test`).

## 0.11.0 - 2026-04-17 (4483642)
- Panel de analytics rediseñado con navegación por tabs (Google tag / Meta Pixel / Search Console) con indicador de estado de configuración por tab.
- Botón "Guardar cambios" promovido al header para visibilidad inmediata sin scroll.
- UI estandarizada en los tres tabs: mismo patrón de campo + resumen + acordeón "Cómo configurarlo" colapsado por defecto.
- Añadidas guías paso a paso para Google tag y Meta Pixel, equivalentes a las que ya tenía Search Console.
- Eliminadas cajas anidadas redundantes (valor guardado, meta tag generado, acordeón DNS).
- Tono de avisos informativos cambiado de warning amber a gris neutral.

## 0.10.0 - 2026-04-16 (6d4e0c9)
- Nueva sección `Analytics` en el admin para centralizar configuración de Google tag, Meta Pixel y Search Console.
- El frontend público ahora inyecta automáticamente Google tag, Meta Pixel y el meta tag de verificación de Search Console a partir de IDs/tokens normalizados.
- Validación y copy del panel unificadas para pedir solo IDs/tokens, sin aceptar snippets HTML/JS pegados.
- Documentación SEO actualizada para reflejar la nueva inyección centralizada de analytics y verificación.
- Cobertura de tests agregada para acceso admin, validación de inputs, renderizado público y defaults de settings.

## 0.9.3 - 2026-04-16 (9a9049b)
- Skill del changelog actualizada para versionar el commit actual sin depender de un SHA previo.
- Nuevo flujo: el siguiente commit completa el SHA faltante de la entrada anterior y crea una nueva entrada sin SHA.
- `Unreleased` queda reservado solo para trabajo todavía no committeado.

## 0.9.2 - 2026-04-16 (dfd5374)
- Fix en subidas múltiples: el lote ahora se valida completo antes de persistir archivos o registros.
- Fix en el live editor: publicar ya no deja el estado `published` pegado si el guardado falla.
- Ajustado el pipeline de media para tratar GIF como raster soportado en dimensiones y health metrics, sin variantes ni conversión.

## 0.9.1 - 2026-04-16 (765bc58)
- Agregado `CHANGELOG.md` inicial con historial desde `0.0.0` hasta `0.9.0`.
- Agregada skill de repo para estandarizar bumps y formato del changelog.

## 0.9.0 - 2026-04-14 (a53af7c)
- Pipeline de optimización de imágenes.
- Dashboard de “media health” para detectar/seguir issues del media.

## 0.8.0 - 2026-04-14 (7807dd3)
- Motor del live editor estandarizado.
- Modo de preview para tablet.

## 0.7.0 - 2026-04-14 (36b84a3)
- Infinite scroll en la media library.
- Subidas multi-archivo en la media library.

## 0.6.2 - 2026-04-14 (f7c2f99)
- Previews de media en admin con `object-contain` para mejor encuadre.

## 0.6.1 - 2026-04-14 (69d7724)
- Fix a drag & drop uploads en media.
- Remoción de previews de imágenes en el flujo de subida.

## 0.6.0 - 2026-04-14 (f6f55ff)
- Limpieza de settings: removidos social/footer.
- Logo del admin enlaza al homepage.

## 0.5.0 - 2026-04-14 (c477a46)
- Localización de settings del admin y mensajes de media (ES/EN).

## 0.4.0 - 2026-04-14 (fddc796)
- Homepage configurable vía settings.
- Seeding y traducciones del admin para homepage.

## 0.3.2 - 2026-04-14 (fee7e77)
- README: instrucciones de deploy/local setup.

## 0.3.1 - 2026-04-05 (9cd3641)
- Docs: guía de Git para workflow de proyecto con cliente.

## 0.3.0 - 2026-04-05 (87ef959)
- Generación dinámica de `sitemap.xml`.
- Guía SEO asociada.

## 0.2.0 - 2026-04-05 (1dae4e7)
- Admin bar estilo WordPress para usuarios autenticados.

## 0.1.0 - 2026-04-04 (73a42b0)
- OAuth 2.0 + PKCE para custom connector de `claude.ai`.

## 0.0.1 - 2026-04-04 (ae14124)
- Fix deploy cPanel: pin de PHP 8.3 (platform) y ajuste de `siteName` en vistas de auth.

## 0.0.0 - 2026-04-04 (e89c18f)
- Base Laravel + Vite (estructura, config, dependencias).
- Admin panel con auth, perfiles y control de acceso (policies/roles).
- Módulos CMS: Pages, Posts/Blog, Media, Menús, Taxonomías y Settings.
- Media library con modelo de Media + relación “mediables” para adjuntar media a contenido.
- API/MCP: servidor/controladores + registry de tools + middleware de API key para exponer capacidades del CMS.
- Comandos CLI: instalación del CMS, reset de password y auditoría de media huérfano.
- DB: migraciones y seeders (admin user, settings, menús).
- Documentación inicial (arquitectura, modelo de contenido, guías de colecciones/plantillas/menús/settings/taxonomías, mantenimiento).
