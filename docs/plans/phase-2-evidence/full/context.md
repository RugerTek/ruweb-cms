# E2E02 full section exercise

Scope: isolated full copy only; no main checkout, no server, no MySQL, no real-site requests, no commit or push. No .env values recorded or used for database writes. Test commands explicitly use SQLite :memory: and empty DB_URL.

Discovery before implementation: cms:capabilities reports admin_profile=full, stability=stable, live_editor=true, specialized_editor_delivery=true. Profile unchanged.

Read AGENTS.md, create-section skill, admin profiles, templates, images and full Live Editor UI/UX contracts. Applied shared repeater runtime with independent section visibility supplied by _form, collapsed nested items, author labels, add/move/delete hooks and translated shared-modal confirmation. Portrait selectors explicitly request square placeholders. Public portrait resolves Media by id and responsive component. No runtime dependence on placeholders.

Default has existing content section and now testimonials follows it. Existing pages without testimonials remain harmless. Existing installation data was not read or mutated. The exact requested content is saved and rendered within the deterministic SQLite feature test: Clientes que confían / El equipo cumplió cada detalle. / Ana Torres / Directora. Its square placeholder media id is generated inside that test. No new collection or client-specific seed page added.

Schema: heading/quote/role translatable; author preserved as a proper name, is_visible preserved; portrait localized_media. Shared PageInput schema validates media existence; browser empty repeater normalization added. Shared PageOperations keeps sibling content and permissions; existing public query guards remain verified.

Validation: DefaultTestimonialsTest 4 passed, 25 assertions. Full composer test 398 passed, 2424 assertions, 22.97 seconds. cms:check-conventions OK. git diff --check clean. Full suite preceded a final small accessibility conditional and translated confirmation addition; targeted test and conventions rerun after final changes, pass. Browser interactions were reviewed against existing generic runtime, not driven through a live browser.

The diff includes the base routes/admin.php fix synchronized by the parent agent: both profile middleware guards are retained. It excludes generated cache/storage files and evidence itself; includes untracked source/test files using git diff --no-index to avoid missing additions. CHANGELOG/VERSION unchanged because no commit is prepared; the exercise expressly prohibits commits.
