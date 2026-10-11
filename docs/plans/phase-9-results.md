# Fase 9 — Identidad, recuperación y clientes MCP

**Estado: fase aceptada con excepción explícita de la matriz de clientes.** El propietario autorizó el
2026-10-10 continuar el trabajo y aplazar la prueba real de Claude («continua trabajando, eso lo vemos mas
tarde»). Claude Code conserva una sesión nativa vencida: su prueba queda pendiente de seguimiento y no
certificada. Esta excepción permite cerrar la fase 9 y continuar; no reduce los gates de las fases 10–11.
Codex CLI completó los tres recorridos con claves emitidas desde el panel en ambos perfiles: borrador,
revocación y conexión sustituta, con negativas de ámbito verificadas.

## Cambios de producto

- Los formularios de 2FA y revocación regresan a Seguridad y conservan la pestaña ante errores. El challenge
  permite volver al login; el formulario de claves conserva scope/expiración y señala las claves vencidas.
- Respuestas administrativas privadas `no-store`; claves y recovery codes aparecen una vez. El setup de 2FA
  permanece disponible hasta confirmar la inscripción.
  El intercambio OAuth entrega tokens con `Cache-Control: no-store` y `Pragma: no-cache`.
- La ruta `/token` excluye la clase real de Laravel 13 `PreventRequestForgery`. La antigua exclusión de
  `VerifyCsrfToken` no excluía el middleware desplegado y provocaba 419 en HTTP real. El POST de consentimiento
  permanece protegido. Se conserva el contrato de parámetros OAuth validado en fase 8.
- `serverInfo.version` procede de `cms.version`/`VERSION`, separado del protocolo `2024-11-05`. Ping responde
  `{}` con todos los ámbitos válidos y no amplía escrituras. Entradas RPC con tipos inválidos reciben
  diagnósticos de protocolo en vez de 500. Las denegaciones legacy mantienen exactamente sus envelopes.
- [Conexión y recuperación](../operations/agent-access.md) documenta inicio humano, rotación/revocación,
  password recovery, 2FA, expiración OAuth, límites de lectura y reintentos. No se promete protección ante
  reemplazos obsoletos cuando un caller legacy omite `expected_revision`.

## Evidencia verificada

Full y essential: navegador real crea una cuenta desde Usuarios, habilita 2FA, verifica códigos de recuperación de un
solo uso, emite claves draft/full/read desde MCP, revoca una conexión y emite su sustituta. OAuth en un
contexto sin sesión exige segundo factor, intercambia S256 y revoca desde Seguridad. El preview real de la
página escrita por Codex confirma los datos. Diez capturas por perfil 1440/390 ocultan los secretos antes de guardarse, más capturas de recuperación.
En essential se verifica la ausencia de enlaces a pantallas deshabilitadas.

HTTP/MySQL real: formulario con antispam completo encola mail local; un proxy loopback drena la respuesta
exitosa de una escritura ya comprometida y corta TCP antes de entregar la respuesta al caller. El reintento
idéntico con revisión anterior y operation_key recupera el mismo resultado, con una sola nota. Input distinto
bajo la misma key conflictúa; scopes read/draft rechazan sin mutación. Trabajador real entrega a mail log
local y MCP consulta queued → sent con un intento. Sólo Google verification/mail boundary están controlados.

Los resultados sanitizados y SHA-256 exactos de source/build/harness están en
[full](phase-9-evidence/full/source-build-sha256.json) y
[essential](phase-9-evidence/essential/source-build-sha256.json), con 476 hashes por perfil. Ningún
secret, cookie, URL de preview, auth log, .env o configuración privada de clientes se guarda como evidencia.

## Matriz de clientes

| Cliente | API key / bearer HTTP | OAuth login propio del cliente | Estado |
|---|---|---|---|
| Codex CLI 0.162.0-alpha.2, Sol 6.1 | Cuenta administradora creada desde el panel con keys draft-only/read-only: borrador leído/creado/editado, ámbito rechazado, key revocada 401, sustituta leída y mutación denegada; ambos perfiles | Opciones configurables disponibles; handshake CLI no probado | API key verificada |
| Claude Code 2.1.245 | Harness listo, ejecución bloqueada antes de MCP | Login de licencia nativo vencido; renovación normal falló | Pendiente de seguimiento por excepción autorizada; no certificado |

Un intercambio OAuth desde navegador/HTTP prueba el servidor; no certifica login OAuth de Codex/Claude.
Los 59 tools y capacidades de autorización/esquemas/reglas son iguales por HTTP para la misma identidad
en ambos perfiles; sólo difieren el perfil y los flags de interfaz/requisitos de editor. El propio Codex
confirma el perfil correcto. No se atribuye interoperabilidad a un caller HTTP simulado. El harness de clientes usa sólo el servidor
fixture y tools autorizados, sin programación con Claude ni cambios de configuración global.

## Comprobaciones

- Suite completa del candidato E2E: **534 tests, 4120 assertions**, aprobados.
- Cierre 0.54.1: `composer test` en copia aislada con SQLite en memoria, **534 tests / 4132 assertions**, aprobado; convenciones aprobadas. El primer intento de esa copia carecía de índice Git para el test de skills: se corrigió sólo el entorno aislado y se repitió la suite completa.
- JavaScript: **17 tests**, aprobados. Build Vite privado aprobado con advertencia TinyMCE existente.
- Convenciones y whitespace: aprobados. La limpieza de whitespace del harness de fase 8 no cambia comportamiento.
- Las negativas del cliente se verifican en la respuesta real: transporte y payload CMS interno, no sólo el resumen
  del modelo. La cuenta revocada falla en startup HTTP 401 con cero calls MCP. La sustituta read-only consulta,
  y su intento de creación es rechazado con `-32603` y el mensaje exacto.
- La recuperación CLI real invalida claves, OAuth y sesiones; login rechaza password antiguo y recovery code
  consumido, exige 2FA con el password nuevo y emite una conexión nueva desde el panel.

## Procedimiento y reversión

Los harnesses `tests/E2E/phase-nine-*` requieren PHASE0_STORAGE/PHASE0_DATABASE de una copia privada con
schema nuevo `ruweb_phase0_` seguido de doce hexadecimales, storage/caches propios, .env descartable,
compilación Vite y puerto 8018. `python3 tests/E2E/phase-nine-fixture.py init` crea ese entorno, rechaza
un estado/puerto ya ocupados y guarda las rutas en `/tmp/ruweb-phase9-state.json` privado. El helper
`cleanup` detiene sólo el proceso fixture verificado, revoca y elimina el schema bajo guard y destruye la copia. El guard verifica manifest/database y evita localhost:8000. El bootstrap
original queda intocado; se copian únicamente credenciales DB a la .env privada del fixture. Los clientes
usan configuraciones efímeras y sus logs se sanitizan antes de conservarlos. En Codex se deshabilitan shell/web
y se autorizan sólo tools MCP por su configuración acotada; no se requiere el bypass global utilizado por
el harness antiguo de fase 4. Claude usa su login nativo y la renovación normal, strict MCP, fuentes de
settings vacías y herramientas no MCP deshabilitadas; la renovación falló y no se reintentó mientras se
solicitaba recuperar el login. El aplazamiento autorizado no requiere nuevos intentos de login.

La ejecución real de Claude tras recuperar su login queda como seguimiento pendiente, fuera del cierre
aceptado por decisión explícita del propietario. Los casos de Codex, identidad, recuperación, permisos,
paridad, reintentos y estado asíncrono están verificados en ambos perfiles. El cierre se entrega en 0.54.1;
los E2E conservaron VERSION 0.54.0 en sus copias privadas. Los hashes históricos no se reescriben para
atribuirles una ejecución sobre 0.54.1. La comparación previa al cierre confirmó la misma fuente y harness;
las únicas diferencias esperadas fueron el build privado y el override de perfil essential. Después sólo
cambian documentación, metadata de aceptación y VERSION/CHANGELOG. OAuth de navegador/HTTP verifica el
servidor; el login OAuth propio de Codex y Claude continúa sin certificación.

La revisión independiente aprobó 72 tests / 822 assertions sin hallazgos pendientes. Convenciones:
traducciones nuevas con paridad es/en, etiquetas admin traducidas; ningún cambio de estructura editorial,
medios, formularios públicos ni settings duplicados.

Limpieza completada: se detuvo el grupo de cuatro workers HTTP, se revocaron las claves fixture y se eliminó
el schema desechable con el guard; se destruyeron copia, .env privada, caches, identidad/recovery codes,
configuraciones/auth temporales y logs. El puerto 8018 quedó libre. .env, cuentas, DB real y localhost:8000
no se modificaron. Sólo se conservan source y evidencia sanitizada.

Para reanudar Claude tras recuperar el login: crear una fixture nueva con `phase-nine-fixture.py init`,
ejecutar el browser de identidad por perfil y escribir los `case_parameters` conservados en
[full](phase-9-evidence/full/phase-nine-client-plan-full-results.json) o
[essential](phase-9-evidence/essential/phase-nine-client-plan-essential-results.json) a su
`phase-nine-client-plan.json` privado; ejecutar `phase-nine-clients.py claude` con los guards y perfil de
esa copia. Las credenciales se resuelven únicamente del manifest nuevo. No reutilizar claves/identidades
de la fixture eliminada. Volver a verificar recuperación/paridad y registrar hashes del candidato exacto
si hubo cambios antes de certificar el seguimiento de Claude.

Conservar métodos existentes durante la transición. El procedimiento
operativo de pérdida de acceso está en agent-access; la recuperación no elimina 2FA.
