# Fase 4 — Resultados de aceptación

Fecha: 2026-10-10. El panel `essential` permite operar cuentas, accesos, ajustes, biblioteca,
integraciones y bandeja, y consultar contenido sin habilitar formularios del Live Editor. La
aceptación E2E-04 cierra el carácter experimental del perfil; el perfil sigue siendo una decisión
del despliegue y no concede permisos a usuarios ni credenciales.

## Entrega

Los listados esenciales de páginas, posts y colecciones registradas muestran idioma y estado efectivo
(draft, published, scheduled o expired), búsqueda, filtros y enlaces a detalle y preview. El detalle
muestra fechas, versiones y diferencias sin formularios editoriales. La preview autenticada utiliza
el renderer público y permite comprobar un draft sin publicarlo ni emitir un enlace compartido.
Las rutas de edición, composición de layout, menús, taxonomías y mutación editorial continúan
requiriendo `full`; las operaciones MCP conservan sus permisos independientes.

El dashboard y la navegación esencial llevan a las pantallas operativas conservadas. El admin bar
público autenticado omite enlaces a editores no disponibles. El control editorial informa capacidades
y la configuración de integraciones sigue accesible. Las colecciones se resuelven por su registro
explícito y contrato editorial; no se permite resolver modelos arbitrarios por un nombre de ruta.

## E2E-04 real

Se preparó una copia descartable con MySQL nuevo, storage y caches independientes, assets de producción
y sin `public/hot`. El bootstrap guardado rechazó un esquema existente. Se ejecutó el comando real
`cms:install` con respuestas de prueba: migraciones, placeholders, páginas iniciales y exactamente un
primer administrador. Ningún dato ni configuración del checkout usado por el sitio real se modificó.

Chromium abrió el login como ese administrador y creó un usuario `editor` desde la pantalla de usuarios.
El navegador inició sesión como el nuevo editor y emitió su propia credencial `draft-only`. Un **Codex CLI
real con `gpt-6.1-sol`** descubrió las 36 herramientas del servidor aislado, consultó capacidades y schema,
creó un draft y lo leyó/actualizó con revisión, conservando sus campos hermanos. El intento de publicación
fue rechazado explícitamente: `This key does not allow this operation.` No se usó SQL para ese recorrido
editorial ni se simuló la respuesta del agente.

La primera ejecución del cliente con `-s read-only` rechazó localmente herramientas MCP con el mensaje
`MCP tool call requires approval, but approval policy is never`. Fue una incompatibilidad de la
configuración no interactiva del CLI; no hubo rechazo de una revisión de seguridad ni auto-review. La ejecución válida configuró el cliente hijo con `--dangerously-bypass-approvals-and-sandbox` dentro
del ejercicio aislado, un home efímero con sólo este servidor y una copia de autenticación existente;
el prompt permitió únicamente herramientas MCP de la fixture. La configuración global no se editó.
La evidencia conserva los eventos de herramientas exitosos y la denegación de publicación, sin bearer,
auth, cookies ni URLs privadas. La consulta real del cliente ocurrió antes del cierre y declaró essential `experimental`. Después
del gate, el cambio de metadata a `stable` fue comprobado por las transiciones cacheadas y la suite;
no se presenta como una nueva consulta del cliente Sol. Las pruebas completas de interoperabilidad
siguen siendo fase 9, que deberá preferir permisos locales limitados al servidor y herramientas de la
fixture cuando el cliente permita configurarlos, en lugar de desactivar globalmente aprobaciones.

El administrador comprobó el título y estado del draft en el listado y el contenido exacto en su preview
nativa. Su URL pública devolvió 404 anónimamente. Se buscaron placeholders reales desde biblioteca y se
editó `site_name` desde ajustes; el valor reabierto y el HTML público coincidieron. Se visitaron dashboard,
perfil, usuarios, MCP, control editorial, bandeja y posts con respuesta 200. Los listados y detalles no
incluyeron formularios editoriales. La URL directa del Live Editor devolvió 404; las solicitudes de scripts
cargaron el entrypoint esencial y ninguna dependencia del editor o TinyMCE.

Las capturas a 1440 px y 390 px verificaron listado, estado, biblioteca, ajustes y admin bar público sin
scroll horizontal del documento. El gate detectó y corrigió desbordamientos en filtros de biblioteca y
pestañas de ajustes. La revisión independiente detectó enlaces del admin bar a pantallas retiradas; la
prueba adicional de navegador comprobó su ausencia después de corregirlos. Este recorrido documenta las
pantallas visitadas; no presenta las capturas como prueba de toda variante posible del panel.

Se detuvo el servidor, seleccionó `full`, regeneró caches de configuración/rutas y abrió un proceso nuevo.
El mismo registro conservó título y heading creados por MCP. El Live Editor inicializó y permitió cambiar
el heading mediante su control visual, guardar y comprobar el valor después de reabrir. Se capturó el
editor en desktop y móvil. El regreso a essential mantuvo el admin bar operativo. No hubo workers
persistentes en esta fixture; un cambio de perfil en despliegue requiere reiniciarlos.

Evidencia: [instalador](phase-4-evidence/e2e04/phase-four-install-results.json),
[cliente real Sol 6.1](phase-4-evidence/e2e04/phase-four-client-results.json),
[identidad desde navegador](phase-4-evidence/e2e04/phase-four-browser-identity-results.json),
[panel esencial](phase-4-evidence/e2e04/phase-four-browser-essential-results.json),
[cambio a full](phase-4-evidence/e2e04/phase-four-browser-full-results.json),
[admin bar](phase-4-evidence/e2e04/phase-four-browser-essential-bar-results.json),
[huella del código/build probado](phase-4-evidence/e2e04/candidate-source-build-sha256.json) y
[limpieza](phase-4-evidence/e2e04/cleanup-result.json). Las trazas de assets contienen sólo rutas; las
pantallas de credenciales no se capturaron y los logs privados del transporte se eliminaron. Una
[fixture de render final](phase-4-evidence/e2e04/final-rendering/context.json), con instalador, login y
credencial reales, recreó el mismo payload mediante HTTP MCP para recapturar la navegación móvil tras
su transición real de 400 ms. Sus [trazas de red saneadas](phase-4-evidence/e2e04/final-rendering/phase-four-browser-essential-results.json)
y [huella final](phase-4-evidence/e2e04/final-rendering/candidate-source-build-sha256.json) se conservan
separadamente del cliente original. No se ocultó la navegación mediante CSS de prueba. La recaptura final verificó también el texto
`Volver al listado` y su destino `/admin/content/pages`, evitando llamar editor al panel readonly.

## Regresión, revisión y limpieza

Se repitieron E2E-00/01 en full, incluyendo formulario antispam y worker con correo sólo a log, y
E2E-02/03 HTTP y navegador en ambos perfiles. El ejercicio de E2E-02 utilizó la fixture documentada
sin añadir testimonios de cliente al starter. Se conservaron permisos, previews, publicación programada,
medios reales, idiomas/SEO y contenido exacto al cambiar perfiles con caches y procesos regenerados.
Las transiciones finales incluyen la nueva declaración `stable` y paridad CLI/HTTP de descubrimiento.
[Contexto de regresión](phase-4-evidence/regression/context.json),
[transiciones full](phase-4-evidence/regression/full/phase-two-transition-results.json) y
[transiciones essential](phase-4-evidence/regression/essential/phase-two-transition-results.json).

La suite final informada por la implementación pasó **439 pruebas / 3013 verificaciones**, junto con
pruebas JS, build y convenciones. El entorno E2E ejecutó PHP 8.5.4; verificar PHP 8.3 sigue pendiente
para el candidato de release. La revisión independiente y las pruebas específicas se documentan en
el cierre general de la entrega. No se realizaron commits desde el arnés de aceptación.

Se revocaron las claves y eliminaron únicamente los esquemas de los ejercicios, con guard de nombre y
manifiesto. Se retiraron `.env`, caches con credenciales, auth del cliente efímero, manifiestos y logs de
servidor/correo. El build vivo de desarrollo y la base del sitio real se conservaron.

## Reversión

Seleccionar `full` en `config/cms.php`, regenerar caches y reiniciar procesos persistentes recupera
las rutas visuales. El perfil no convierte, borra ni reescribe contenido; roles y scopes siguen vigentes.
Las propuestas, aprobación y recuperación editorial por MCP pertenecen a fase 5.
