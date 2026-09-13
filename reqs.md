# Capacidades reales del sitio

Documento actualizado contra el código del repositorio. La fuente de verdad es la implementación actual, no una lista ideal de lanzamiento.

## Sí existe en el código

### Frontend público

- Sitemap dinámico en `/sitemap.xml` con páginas publicadas y posts publicados.
- `robots.txt` público con bloqueo de `/admin/` y referencia al sitemap.
- Home configurable por setting `homepage_slug`.
- Páginas públicas por slug.
- Blog público en `/blog` con detalle por slug.
- Filtro de blog por categoría vía query string `?category=...`.
- Templates de páginas `default`, `home` y `home-alt`.
- Menús renderizables desde el CMS para ubicaciones `header` y `footer`.
- Header con logo, menú y botón de llamada a la acción opcional, editable desde la pantalla Header del admin.
- Footer con logo, texto, menú de la ubicación `footer`, datos de contacto, íconos de redes sociales y línea de copyright con `{year}` y `{site}`.
- Datos de contacto del negocio (teléfono, WhatsApp, email, dirección, coordenadas) y lista administrable de redes sociales en Ajustes > Contacto, consumidos vía `App\Support\BusinessContact`.

### SEO y analítica

- Meta title y meta description dinámicos.
- Canonical URL.
- Open Graph tags.
- Twitter Card tags.
- Favicon configurable desde settings.
- Imagen social por contenido y fallback global `default_social_image`.
- Verificación de Google Search Console por meta tag.
- Inserción condicional de Google Tag / GA4 en el layout público.
- Inserción condicional de Meta Pixel, incluyendo fallback `noscript`.

### CMS / panel admin

- Login de admin en `/admin/login`.
- Recuperación y reseteo de contraseña.
- Roles `admin` y `editor`.
- Dashboard admin.
- Perfil de usuario con cambio de nombre, email y contraseña.
- Gestión de usuarios desde admin.
- Creación, edición, publicación y borrado de páginas.
- Creación, edición, publicación y borrado de posts.
- Preview renderizado para páginas y posts.
- Estado editorial `draft` / `published`.
- Taxonomías jerárquicas desde admin.
- Asociación de categorías a posts.
- Gestión de menús con items anidados.
- Settings generales desde panel.
- Pantallas Header y Footer que centralizan lo editable de cada región con accesos directos a Menús y a Ajustes > Contacto.
- Dropdown "Editar layout" en la admin bar pública con accesos a Header y Footer.
- Configuración de analítica desde panel.
- Configuración de email desde panel.
- Pantalla admin para integración Claude/MCP.
- Acción de refresco de caché de Laravel desde la admin bar pública para admins.

### Media

- Librería de medios con búsqueda, filtros y paginación.
- Upload simple y múltiple.
- Validación de extensiones permitidas.
- Bloqueo básico de SVG malicioso (`<script>` y handlers inline).
- Conversión automática de JPG/JPEG/PNG a WebP cuando la optimización está habilitada.
- Preservación opcional del original al optimizar.
- Lectura y guardado de dimensiones de imágenes raster.
- Generación de variantes responsivas.
- Componente público con `srcset`, `sizes`, `loading="lazy"` y `decoding="async"`.
- Auditoría de salud de media y comandos artisan relacionados.

### Integraciones API / automatización

- API MCP autenticada por API key de usuario.
- Generación y revocación de API key MCP desde perfil.
- Endpoints MCP para páginas, posts, media, settings y menús.
- Endpoint MCP JSON-RPC en `/mcp/rpc`.
- OAuth authorization code + PKCE para clientes configurados.

### Seguridad y controles ya presentes

- CSRF en formularios web estándar.
- Hashing de contraseñas vía Laravel.
- Rate limiting de login: 5 intentos por email + IP.
- Middleware de roles para separar `admin` y `editor`.
- Autorización por policies en posts, menús y taxonomías.
- La admin bar pública respeta el `admin_locale` configurado aunque se renderice fuera de rutas `/admin`.

## No existe o no está implementado en el repo

### SEO / marketing

- Datos estructurados JSON-LD / Schema.org.
- Control `noindex` / `nofollow` por página desde admin.
- Gestión de redirecciones 301.
- Integración con Search Console o Analytics para leer métricas dentro del admin.
- Integración con Google Tag Manager como feature explícita del CMS.

### Seguridad

- Forzado de HTTPS a nivel aplicación.
- Security headers explícitos: HSTS, CSP, `X-Frame-Options`, `X-Content-Type-Options`.
- 2FA para el admin.
- URL de login configurable u oculta.
- Bloqueo/lista negra de IPs por intentos fallidos.
- WAF o monitoreo de integridad de archivos.
- Log de actividad editorial en base de datos.

### Performance / entrega

- Full-page cache.
- CDN integrada desde la aplicación.
- Headers HTTP de caché explícitos para HTML/assets.
- Conversión AVIF.

## Depende del entorno o no puede afirmarse solo con el repo

- Uso real de Brevo en producción.
  El código soporta mailer Brevo por API y test de envío desde admin, pero si se usa `log`, `smtp` u otro mailer depende de configuración.
- HTTPS real, cookies seguras y certificados.
  Hay soporte vía `SESSION_SECURE_COOKIE` en config, pero no hay enforcement en aplicación y el resultado final depende del deploy.
- Cloudflare, DNS, backups automáticos, monitoreo externo y Search Console.
  Son decisiones de infraestructura, no capacidades implementadas en este repo.
- Política de Privacidad, Términos, textos legales y contenido editorial.
  Pueden cargarse como páginas del CMS, pero no vienen como feature preconstruida ni contenido garantizado.
- Revisión responsiva final, performance real y ortografía.
  El repo tiene layouts públicos y assets compilados, pero esos checks siguen siendo QA, no una capacidad certificable por lectura de código.

## Resumen práctico

`reqs.md` antes marcaba como pendientes varias capacidades que ya están implementadas: sitemap, `robots.txt`, GA4/Google Tag, Meta Pixel, verificación de Search Console, optimización de imágenes a WebP, variantes responsivas y lazy loading.

Lo que sí sigue faltando en código es, sobre todo, endurecimiento de seguridad, features SEO avanzadas, activity logging, ocultación/configuración de la URL de login y piezas de infraestructura que viven fuera del repo.
