# ruweb-cms
Fast, flexible and intuitive LLM-first CMS.

## Deploy / desarrollo local

### Requisitos
- PHP 8.3+ (con extensiones típicas de Laravel; `cms:install` valida `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`)
- Composer
- Node.js (recomendado 20+)
- MySQL (o MariaDB) corriendo en local

### Setup inicial
1. Instalar dependencias PHP:
   ```bash
   composer install
   ```

2. Crear el `.env`:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Instalar dependencias frontend:
   ```bash
   npm install
   ```

4. Instalar el CMS (crea la DB si el usuario tiene permisos, corre migraciones, crea admin y hace `storage:link`):
   ```bash
   php artisan cms:install
   ```

   El instalador pregunta por el perfil **full** (panel editorial completo) o **essential** (panel operativo y edición por MCP), el idioma base y las credenciales del administrador. El **nombre de usuario es obligatorio**: 3–32 letras sin acentos, números, punto, guion o guion bajo; se guarda en minúsculas y debe ser único. Podés iniciar sesión con ese nombre o con el email.

   Para elegir el perfil desde la línea de comandos: `php artisan cms:install --profile=essential` (o `full`). Se guarda en `config/cms.php`, sin variable de entorno; el instalador limpia los cachés de configuración y rutas. Si reinstalás, reiniciá los procesos persistentes. Una cuenta cuyo email ya existe conserva sus credenciales; si todavía no tiene nombre de usuario, el instalador lo solicita.

5. (Opcional) Sembrar menú de ejemplo:
   ```bash
   php artisan db:seed --class="Database\\Seeders\\MenuSeeder"
   ```

### Levantar el proyecto
En desarrollo, lo más simple es usar el runner incluido (servidor + queue + logs + Vite):
```bash
composer run dev
```

URLs:
- App: `http://localhost:8000`
- Admin login: `http://localhost:8000/login`

### Credenciales admin
Durante `php artisan cms:install` podés elegirlas. Los defaults del repo (si no cambiás nada) también están en `.env.example`:
```env
CMS_ADMIN_USERNAME=admin
CMS_ADMIN_EMAIL=admin@ruweb.local
CMS_ADMIN_PASSWORD=password
```

### Troubleshooting rápido
- Si te conecta a SQLite “sin querer”, revisá que en `.env` esté `DB_CONNECTION=mysql`.
- Si `cms:install` no puede crear la DB, creala manualmente y asegurate de que `DB_DATABASE/DB_USERNAME/DB_PASSWORD` sean correctos.

### Pruebas

`composer test` ejecuta la suite con SQLite en memoria. Los tests de editores visuales seleccionan Full mediante `$this->useAdminProfile('full')`; los contratos exclusivos de Essential seleccionan `essential`. Para comportamientos compartidos, usá `#[DataProvider('adminProfiles')]` y seleccioná cada `$profile` con el mismo helper. No fuerces un perfil global en `Tests\TestCase`: la suite debe funcionar con cualquiera de las dos elecciones versionadas en `config/cms.php`.

La suite puede ejecutarse en una copia del proyecto sin `.git`, pero requiere el ejecutable de Git para crear sus repositorios temporales privados. Los contratos de assets usan fixtures de Vite; su verificación no depende del manifest ni del servidor de desarrollo local.

## Phase 4 highlights

- Blog collection with admin CRUD (`/admin/posts`) and public routes (`/blog`, `/blog/{slug}`)
- Role hardening via `PostPolicy` (editor can create/update, admin can delete)
- Maintenance commands:
  - `php artisan cms:user:reset-password`
  - `php artisan cms:media:audit-orphans`
- Feature tests for blog visibility, post permissions, and auth/media smoke paths

## Documentation

The docs are written for agents. `AGENTS.md` (also reachable as `CLAUDE.md`) is the entry point: repo-wide
rules plus a task → skill → doc routing table. `docs/README.md` maps every doc to the decision it owns.

Reusable workflows live in `.agents/skills/`, symlinked into `.claude/skills/`, so Claude Code and Codex both
pick them up automatically: creating pages, templates, sections, collections, forms and settings, updating or
translating content, checking translations, reviewing a change against the docs, and the changelog rules.

Image optimization and responsive media strategy:
- `docs/content/images.md`
