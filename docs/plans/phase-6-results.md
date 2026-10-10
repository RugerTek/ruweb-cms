# Fase 6 — Formularios y seguimiento por MCP

Fecha: 2026-10-10. Fase aceptada tras revisión independiente y E2E final en ambos perfiles.
Base: `cabf2b0f52276bff6f0bb9312b625bcb464c1145` (0.51.0). Entrega local preparada: 0.52.0. No se inició fase 7 ni se ejecutó push.

## Entrega

Seis herramientas amplían el catálogo a **52**, idénticas en full y essential: `submissions_list`,
`submissions_get`, `submissions_assign`, `submissions_set_status`, `submissions_add_note` y
`submissions_retry_notification`. REST ofrece las operaciones equivalentes. `SubmissionOperations` comparte
reglas con el panel y recibe el actor explícitamente; nunca necesita cookies o una sesión administrativa.
Los formularios mantienen campos y validación definidos por código, destinos de notificación y anti-spam existentes.
No se añadió un form builder ni una nueva superficie de configuración de correo.

Admin/editor pueden leer y gestionar la bandeja dentro de su rol. Las credenciales read-only y draft-only
sólo leen; el seguimiento y reenvío exigen full. Cambiar de perfil no concede roles ni scopes. Leer por MCP
no marca read_at, no registra eventos ni reserva correo; el detalle humano conserva su marcado explícito.
El rechazo de rol mantiene el contrato RPC existente: HTTP 200/error -32603 y mensaje exacto; REST 403.
El rechazo de scope responde 403 y error RPC -32603; la normalización global se reserva a fase 9.

Búsqueda acotada a 200 caracteres, páginas de hasta 100 envíos, notas de 20 en 20 y textos de hasta 8192
caracteres con hash y recuperación Unicode por campo/rango. Los usuarios serializados contienen sólo ID,
nombre y rol. Tokens/claims de entrega, destinatario interno y excepciones crudas nunca salen por MCP;
estados y diagnósticos saneados indican la recuperación. Notas internas de hasta 5000 caracteres conservan
su autor, se escapan en el panel y nunca entran en páginas públicas o notificaciones.

Cada nueva mutación MCP exige revisión y operation_key. Locks de actor y registro serializan reintentos,
recibos persistidos devuelven el mismo resultado ante respuesta perdida y claves reutilizadas con otra entrada
conflictúan sin efectos. `submission_events` registra actor, acción y estados antes/después para panel y MCP.
Los campos del visitante permanecen inmutables. El worker usa un claim por intento para impedir que el resultado
tardío de un intento anterior sobrescriba un lease recuperado; callbacks agotados preservan entregas activas/enviadas.
Un error de inserción en cola después del commit deja un estado failed durable y recuperable.

La revisión independiente cerró sin hallazgos de producto. Sus dos refuerzos de aceptación —mensaje exacto
de rechazo por scope y menú móvil cerrado con controles descubiertos por hit-test— se incorporaron al harness
y el E2E-06 se repitió en ambos perfiles antes del cierre.

## Migración, límites y reversión

Migración aditiva: claim nullable en contact_submissions, submission_events y submission_operation_receipts.
No altera envíos existentes ni el contenido público. Recibos/eventos son estado operacional; su inclusión y
portabilidad en snapshots se evalúan expresamente en fase 10. Las entregas ya notificadas no se reservan otra vez.
Sólo failed/skipped pueden generar un nuevo intento; queued/sending/retrying/sent no duplican la reserva.

La entrega conserva los cuatro intentos y backoff existentes. No se promete exactly-once si el proveedor
acepta un correo y después falla la conexión o el worker antes de persistir el éxito. El gate usa transporte
controlado y log local; nunca envía correo externo.

Para revertir la nueva superficie, detener clientes y workers y desactivar las entradas MCP de seguimiento,
conservando formularios, bandeja y cola. No borrar datos para volver de perfil. Si se revierte código, usar
copia compatible y conservar los eventos/recibos como evidencia operacional; no revertir migraciones de
producción de forma destructiva durante una entrega activa.

## Verificación

La suite final pasó **472 pruebas / 3616 aserciones**; los mensajes exactos de rechazo se verifican sin cambiar
el contrato de transporte existente. Convenciones y
Pint/diff check pasan. No se modificaron JS, estilos o vistas del producto; se reutilizó el build de producción
exacto de la base, sin public/hot. Los 17 tests nuevos cubren roles/scopes, ambos perfiles, lectura no mutante,
autoría/auditoría, receipts, conflictos, campos inmutables, recuperación Unicode/notas, fallos de inserción
de cola y resultados tardíos de workers con lease recuperado.

E2E-06 usa MySQL nuevo guardado por perfil, HTTP real sin cookies para MCP, Chromium y worker real. Abre
contacto publicado en el navegador, obtiene CSRF/token firmado y espera el mínimo; reCAPTCHA mantiene
verificación exacta en la frontera externa simulada de Google. La misma fila se observa en la bandeja.
Asignación, nota y estado se escriben por MCP y se verifican en el panel. Ocho peticiones concurrentes de nota
y ocho de reenvío, atendidas por cuatro procesos HTTP contra MySQL real, recuperan un único resultado.

Un transporte controlado provoca cuatro fallos reales del worker y su agotamiento, avanzando sólo el reloj
aislado para cruzar el backoff. El estado failed es visible; la recuperación por MCP y el worker producen
exactamente un mensaje en el log local, con estado sent e intento 1 de la nueva generación. Repetir reenvío
y ejecutar de nuevo el worker no entrega otro mensaje. CSRF/token inválidos y página no publicada no crean
otro registro válido ni correo. Credenciales inválidas/expiradas, roles y scopes son rechazados con motivo
exacto y sin mutación. Capturas a 1440/390 verifican controles descubiertos, sin errores ni desbordamiento.

Regresiones afectadas: E2E-00/01 en una instalación full nueva separada; no se repitieron gates editoriales
no afectados. Suite completa cubre esos contratos. El harness reproducible está en tests/E2E/README.md y
scripts phase-six-*. La evidencia saneada se conserva en [phase-6-evidence](phase-6-evidence/context.json).
Todas las fixtures son copias descartables con vendor, storage, caches y MySQL independientes; excluyen .env,
public/hot y estado real. Cleanup revoca credenciales, elimina sólo schemas generados, detiene servidor/workers
y borra manifests, .env copiado, caches con claves y logs privados. Las capturas sólo contienen cuentas/datos
ficticios. El contexto conserva fingerprints de producto/build/harness y resultados sin tokens o secretos.
