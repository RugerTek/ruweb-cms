# Runtime mínimo y regresiones acumuladas del candidato de fase 11

PHP **8.3.35 real**, Laravel 13.3.0, MySQL desechable y Chromium. La instalación independiente de
Composer contiene 109 paquetes; `check-platform-reqs` confirma PHP y todas las extensiones. La suite
completa ejecutada con ese binario pasa: **545 pruebas, 6290 aserciones, 28.91 s**. JavaScript pasa
17 pruebas; build pasa en 537 ms y conventions informa OK. El aviso existente del chunk TinyMCE permanece.
El conteo final observado sustituye las 6313 aserciones de una lectura anterior: pasan las mismas 545
pruebas, sin skips ni pruebas faltantes. No se atribuye esa variación a una causa no verificada.

[runtime-artifacts.json](runtime-artifacts.json) identifica el bottle, el binario privado relocalizado,
sus dependencias privadas, `composer.lock`, el código funcional y los assets exactos. Los archivos emitidos
coinciden con el build del source; el manifest difiere únicamente en claves/src de imágenes Leaflet por el
symlink de `node_modules` de la copia. El PHP global sigue siendo 8.5.4: se extrajeron PHP y aspell a una
carpeta privada, sin instalar, vincular ni cambiar la configuración global. El ini privado usa
`short_open_tag=Off`, UTC y memoria suficiente; el valor predeterminado On de un ini vacío produjo un 500
al interpretar XML y se corrigió antes de los pases definitivos.

| Gate | Perfiles y recorrido |
|---|---|
| E2E00/01 | Ambos: descubrimiento, middleware real, permisos, publicación, REST/MCP, formulario y trabajador local. Full conserva el editor visual original; Essential comprueba panel operativo, preview autorizado con el contenido guardado y 404 en rutas visuales. |
| E2E02 | Full y essential: entrega histórica determinista de `tests/Fixtures/admin-profiles`, HTTP, navegador y tres transiciones con caches. No se afirma nueva autoría de agentes. |
| E2E03 | Ambos: binario real, traducciones, SEO, draft/preview/publicación y reloj de publicación, navegador por idioma y tamaño. |
| E2E04 | Instalador real en essential, primer admin, editor/credencial por panel, cliente Codex real limitado a seis herramientas MCP, denegación de publicación; panel essential y transición a full con edición visual. |
| E2E05 | Ambos: propuestas, revisión, aprobación, conflictos, recuperación histórica, revocación y retiro; navegador sobre previews/publicación. |
| E2E06 | Ambos: formulario real, ocho solicitudes concurrentes, estados y notas, cuatro fallos del trabajador, recuperación idempotente y una entrega local. |
| E2E07 | Ambos: HTML/empty/composed, traducción, medios, saneamiento DOM, accordion real, restauración; essential pasa además a full con caches y aviso de compatibilidad en navegador. |
| E2E08 | Ambos: creación por panel, versiones/activación concurrentes, seis documentos por idioma/destino, aislamiento del iframe, kill switch y rechazos OAuth. CLI disable/status/enable funciona con MySQL inaccesible en puerto 1. |
| E2E09 | Ambos: identidad y 2FA, sesiones, contraseñas, OAuth con revocación y recuperación; Full incluye cliente Codex real creando y actualizando un draft. Ambos pasan HTTP con respuesta perdida, trabajador, recuperación y verificación final. |

`accumulated-summary.json` y cada `phase-*-execution.json` contienen el estado definitivo y los comandos
reales; los directorios por fase/perfil conservan resultados y capturas. Los manifiestos de fingerprints
identifican la copia, el perfil y cualquier diferencia con el source. Los cambios de fixture de E2E02
están descritos explícitamente y no pasan al starter. E2E10 y el piloto nuevo E2E11 se verifican por separado.

Los primeros reportes de E2E02 y E2E04 no conservaban fingerprints de la copia ejecutada. Se repitieron
E2E02 Full/Essential y E2E04 Essential con el runner final: los tres pasan y capturan los bytes reales
después de preparar perfil/fixture, antes de arrancar la aplicación.
[provenance-reruns.json](provenance-reruns.json) vincula cada repetición con su reporte y digest.

## Incidencias del harness y aislamiento

El contador histórico de E2E07 se actualizó de 52 a las 59 herramientas actuales. El harness de transiciones
E2E02 presupone un solo servidor; se evitó heredar cuatro workers que sobrevivían a su `terminate`.
La lectura E2E06 espera ahora la navegación y el documento de detalle antes de comparar el cuerpo.
E2E09 respeta el límite real de cinco POST por cuenta/minuto: sus desafíos consumen dos POST cada uno,
así que el OAuth final espera una ventana real de 60 segundos, sin limpiar ni desactivar el limitador.

Los fixtures usan schemas nunca existentes, `storage` y caches privados y puertos loopback. Cada fixture
terminado comprueba el hash original de `.env`, detiene su grupo de servidor, revoca las claves, elimina
su schema con el guard y destruye la copia con auth, configuración, manifests, previews y logs privados.
El runtime privado, la copia independiente de dependencias y el storage del CLI con DB inaccesible
también se eliminaron al cerrar la validación; el PHP global no cambió.
No se conservan claves, contraseñas, recovery codes ni URLs privadas. Una invocación de suite con cwd
incorrecto arrancó brevemente en el source y ejecutó `config:clear`; se detuvo inmediatamente y se repitió
en la copia privada. No cambió `.env` ni la base real. Ese incidente impide afirmar que ningún comando
de caches se ejecutó sobre el source.
