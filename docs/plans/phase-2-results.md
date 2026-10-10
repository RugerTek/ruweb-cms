# Fase 2 — Resultados de aceptación

Fecha: 2026-10-10. Fase 2 implementa la elección versionada `full`/`essential`, el descubrimiento común
local/MCP y el contrato de desarrollo por perfil. Essential sigue experimental; su panel operativo final
se verifica en fase 4. No se implementaron HTML de composición ni capacidades futuras.

## Entrega

`AdminCapabilities` resuelve el perfil, requisitos de editores, compatibilidad de secciones, enlaces y
manifiesto sin secretos. `full` es el valor predeterminado; valores distintos producen un diagnóstico
explícito en el arranque. No existe setting de base de datos ni override de entorno. La herramienta
`cms_capabilities`, REST `GET /mcp/capabilities` y `cms:capabilities` devuelven el mismo manifiesto. Los
23 tools se registran igual en ambos perfiles; rol y scope de credencial autorizan cada operación aparte.

Essential bloquea creación/edición especializada de páginas/posts, preview del editor y sus leases; sus
listados usan historial y ocultan accesos a creación/traducción. Los assets se separan: essential carga
controles compartidos operativos, sin el engine/feedback/recovery especializado. Full conserva su editor
incluyendo la cola de registro de recovery necesaria cuando Vite evalúa primero el módulo compartido.
La vuelta desde páginas conserva filtros/paginación/origen en full.

AGENTS, skills y docs requieren consultar el perfil antes de decidir entregables. La convención de editor
faltante examina campos personalizados del schema, reutiliza el primer partial registrado del mismo
section key igual que el editor y sólo exige ese partial en full. Los contratos de datos, medios,
publicación, permisos, formularios y traducciones permanecen exigibles en ambos.

## E2E-02: dos agentes reales

Se entregó el mismo [brief](phase-2-evidence/brief.md), candidato, AGENTS/skills/docs a dos agentes Sol 6.1
en copias independientes. Ambos añadieron testimonios al template default con heading, visibility y lista
de quote/author/role/portrait_id, esquema de idioma y renderer con placeholder cuadrado por id.
Full añadió editor/repeater y normalización de lista vacía; essential no creó partial editorial.
Los contextos y diffs originales están en [full](phase-2-evidence/full/context.md) y
[essential](phase-2-evidence/essential/context.md). Esas funcionalidades del brief se mantienen sólo como
artefactos del ejercicio; el starter incluye una fixture determinista equivalente para regresión.

Se crearon esquemas MySQL nuevos y storage/caches distintos. HTTP usó el panel con CSRF, formulario
codificado real en full y MCP sin cookies en essential. El contenido exacto se leyó desde MCP y del form
reabierto, se publicó y renderizó públicamente, preservando la sección hermana. La URL del borrador y la
publicación futura devolvieron 404. Un retrato como URL fue rechazado sin cambiar datos en ambos perfiles.
CLI, REST y JSON-RPC coincidieron para scopes read-only y draft-only.

Chromium/Playwright verificó full: inicialización síncrona, reconstrucción recovery, añadir/reordenar,
eliminación con confirmación y guardado desde navegador con texto público actualizado. Essential: sólo
entrypoint/chunks compartidos del build, sin editor/TinyMCE en listados, modal de medios funcional y URL
editorial directa 404. Ambos finalizaron sin errores JavaScript. Se guardaron capturas desktop/mobile.
El primer intento detectó `public/hot` heredado; se eliminó en las copias y se añadieron guard y bloqueo de
orígenes al test. La evidencia aprobada usa exclusivamente el build copiado, cuya huella está registrada.

Con cachés de configuración/rutas regeneradas se recorrió full → essential → full y essential → full →
essential. Cada paso arrancó un servidor nuevo y conservó el registro exacto y render público. La entrega
essential informa editor ausente al seleccionar full y falla convenciones antes de activarla/desplegarla;
no transforma datos. El mismo partial ausente falla en full y pasa en essential; un img literal falla en
ambos. Un valor inválido falla el comando local con diagnóstico `Invalid cms.admin_profile`.

Evidencia HTTP, browser, caches y capturas: [full](phase-2-evidence/full/phase-two-results.json),
[essential](phase-2-evidence/essential/phase-two-results.json),
[transiciones full](phase-2-evidence/full/phase-two-transition-results.json) y
[transiciones essential](phase-2-evidence/essential/phase-two-transition-results.json).
Reproducción: [harness](../../tests/E2E/README.md). Credenciales revocadas, esquemas eliminados y
manifiestos/.env/config caches con secretos borrados tras la aceptación; no se escribió en el sitio real.

## Validación y límites

- Suite PHP: **400 pruebas / 2495 verificaciones**; JavaScript: **15 pruebas**, build Vite y convenciones OK.
- Pruebas de cada agente: full **4 / 25**, essential **3 / 28**; convenciones OK. La copia full también pasó
  su suite completa antes de las pruebas HTTP.
- E2E-00 y E2E-01 acumulados volvieron a pasar sobre MySQL aislado con el catálogo actualizado a 23 tools.
- Revisión independiente Sol 6.1: retorno al origen y assets corregidos; fixture traducida sólo para el test;
  revisión final de arquitectura, diff, runtime/browser y transiciones sin pendientes.
- Runtime comprobado: PHP **8.5.4**, MySQL real y Chromium 151. PHP mínimo 8.3 no se ejecutó en esta máquina.
- La ausencia de assets especializados se verificó en los recorridos operativos indicados. Controles comunes
  y rich text de pantallas operativas existentes continúan compartidos; no se anuncia panel essential final.
- Las limitaciones previas del transporte RPC y propuestas/publicados siguen documentadas en fases 0/1;
  esta fase no pretende completar los recorridos de MCP/editorial/identidad posteriores.

Reversión: seleccionar full en la configuración versionada, completar editores de las extensiones que
carezcan de ellos, verificar convenciones, regenerar caches y reiniciar procesos persistentes. El perfil
no convierte contenido, permisos ni credenciales.
