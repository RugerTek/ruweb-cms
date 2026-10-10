# E2E02 Essential section

Scope: isolated essential copy only. Discovered admin_profile=essential via php artisan cms:capabilities before implementing. Profile remains essential; no specialized section partial created. No commit, push, server start or real-site database writes.

Delivery: default adds testimonials after existing content. Schema preserves visibility and author; heading, quote and role translate; portrait_id is localized media. Existing shared PageOperations/PageInput validate nested repeater arrays, booleans and existing media ids. Renderer requires visible nonempty quotes, escapes editable text, batches portrait lookup and uses responsive-img square avatar slots. Missing/empty sections safely render nothing.

Deterministic fixture: heading=Clientes que confían; quote=El equipo cumplió cada detalle.; author=Ana Torres; role=Directora. PlaceholderMediaSeeder runs only in test SQLite memory database with fake public storage; the square placeholder id is stored in portrait_id. Existing content survives the operation patch. Public draft and future publication stay 404; published renders; hidden/empty/missing section does not render. Editor may save a draft but cannot publish. Invalid array/media/visibility rejected atomically.

Verification: php artisan test --filter=DefaultTestimonialsTest: 3 passed, 28 assertions. php artisan cms:check-conventions: Conventions OK. Initial test attempt found missing storage/framework/views in copied checkout; created runtime directories within this copy and re-ran successfully.

Diff includes only section deliverables and test; excludes evidence and runtime/cache files. Parent supplied unrelated base route guard changes are not included. No changelog/version changed because no commit requested.
