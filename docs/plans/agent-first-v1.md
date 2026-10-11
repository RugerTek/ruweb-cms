# Rüweb CMS 1.0 — Plan de evolución hacia operación por agentes

**Estado:** fases 0–9 implementadas y aceptadas; fase 9 con excepción explícita para Claude pendiente. Fases 10–11 autorizadas, sin iniciar.
**Fecha:** 10 de octubre de 2026.
**Revisión:** 15 — Fase 9 aceptada; el propietario aplaza la prueba real de Claude sin certificarla ni reducir los gates 10–11.
**Base inspeccionada:** Rüweb CMS 0.47.0.
**Entrega actual:** fases [0](phase-0-results.md), [1](phase-1-results.md), [2](phase-2-results.md), [3](phase-3-results.md), [4](phase-4-results.md), [5](phase-5-results.md), [6](phase-6-results.md) [7](phase-7-results.md), [8](phase-8-results.md) y [9](phase-9-results.md) aceptadas con resultados; fase 9 conserva la excepción explícita de Claude. El propietario autorizó la ejecución autónoma completa hasta 1.0; cada fase exige su E2E aprobado antes de avanzar, con programación y pruebas realizadas por Sol 6.1.

## 1. Objetivo y valor del producto

Mantener un único repositorio que entregue un sitio público funcional, un núcleo de contenido reutilizable y la experiencia de la agencia expresada en documentación, skills, contratos y verificaciones. Cada proyecto podrá elegir administración completa o administración esencial. Ambos perfiles ofrecen la misma superficie MCP; no habrá un tercer perfil sin panel.

El ahorro buscado consiste en no construir editores visuales para funcionalidades que se operarán mediante agentes. No basta con ocultar el panel después de programarlo. El perfil debe determinar tanto las superficies disponibles como los entregables exigidos al desarrollar.

La versión **1.0.0** se publicará cuando los perfiles acordados puedan operar de extremo a extremo, la migración desde la base actual esté probada y se cumplan los criterios de aceptación de este plan. No es un cambio de número anticipado ni requiere recorrer todas las versiones intermedias hasta 0.99.

### Resultados esperados

- [ ] Conservar el frontend público y las capacidades actuales del CMS.
- [ ] Mantener el comportamiento actual por defecto en instalaciones existentes.
- [ ] Crear páginas, secciones y colecciones sin desarrollar su interfaz editorial cuando el perfil no la requiere.
- [ ] Administrar el sitio por MCP sin depender de una sesión del backoffice.
- [ ] Permitir estructura visual flexible mediante bloques controlados, sin confundir contenido con ejecución arbitraria de código.
- [ ] Compartir reglas, permisos y operaciones entre panel, MCP y herramientas operativas.
- [ ] Conservar seguridad, SEO, idiomas, medios, formularios, historial y portabilidad entre dominios.

## 2. Alcance y límites

### Incluido en la propuesta de 1.0

Un repositorio; perfiles de administración; operaciones compartidas; ampliación de MCP; panel esencial; operación cotidiana mediante agentes en ambos perfiles; frontend con bloques flexibles y páginas de un solo campo HTML; gestor de código personalizado con reglas de aplicación; documentación y comprobaciones conscientes del perfil; instalación, actualización y recuperación verificadas.

### Fuera de alcance inicial

- Reescribir Laravel o sustituir el stack para parecerse a otro CMS.
- Convertir Rüweb en un SaaS multicliente con facturación y aprovisionamiento de infraestructura.
- Ofrecer una terminal arbitraria, SQL libre o ejecución de PHP mediante MCP.
- Crear colecciones nuevas modificando el esquema de producción desde una conversación sin pasar por código y migraciones.
- Ejecutar scripts desde campos editoriales comunes o desde el HTML saneado de una página. El nuevo gestor de Custom Code será una superficie administrativa explícita para código de navegador confiable, con permiso propio; no habilita ejecución de código en el servidor.
- Generar automáticamente un Live Editor especializado para cualquier HTML o componente inventado.
- Garantizar cambios de diseño ilimitados con una credencial que solo autoriza operaciones de contenido.

El agente podrá programar funcionalidades nuevas mediante el repositorio, pruebas y despliegue. MCP cubrirá las operaciones soportadas por el sistema. La integración de un workspace remoto de programación queda como evolución posterior, no como requisito oculto de este plan.

## 3. Diagnóstico de la base actual

Diagnóstico inicial por lectura del repositorio. Las pruebas y correcciones posteriores de fase 0 están en el registro de resultados; esta tabla conserva el contexto de partida.

| Área | Estado observado | Consecuencia |
|---|---|---|
| MCP | 22 herramientas en `app/Mcp/ToolRegistry.php`, sobre páginas, posts, medios, ajustes y menús. | Existe una base reutilizable, pero no paridad funcional con el panel. |
| Transporte | REST y JSON-RPC delegan en `McpController`; el servidor anuncia un contrato de protocolo concreto. | Hay que verificar compatibilidad real de clientes antes de estabilizar la interfaz pública de 1.0. |
| Permisos | Claves con ámbitos generales; clasificación de herramientas en `EnforceMcpScopes`. | Ampliar operaciones exige clasificar permisos explícitamente, no hacer que toda herramienta nueva herede acceso total. |
| Rutas | El arranque registra todo el admin; el login también se registra en las rutas web. OAuth tiene rutas propias. | No alcanza con condicionar un archivo de rutas: hay que separar identidad, panel y operación pública. |
| Esquemas | `ContentSchemaRegistry` trata páginas, posts y taxonomías explícitamente. | Las nuevas colecciones necesitan un contrato extensible, sin construir un framework genérico innecesario. |
| Desarrollo | Skills y documentos de templates, secciones y colecciones exigen trabajo administrativo. | El perfil debe modificar estas obligaciones de manera verificable. |
| HTML | El sanitizador de rich text elimina, entre otros elementos, estilos, scripts y formularios. | Un bloque de composición visual requiere un contrato distinto; no se debe debilitar globalmente el rich text existente. |
| Custom Code | Analytics admite identificadores de Google Tag/Meta Pixel y token de Search Console; el layout tiene extensiones Blade para desarrolladores. No existe un gestor de snippets con reglas por página. | Implementar una capacidad nueva sin reemplazar ni duplicar las integraciones existentes. |
| Editorial | Historial, revisión, recuperación y previews ya existen, con controladores y pantallas administrativas. | Reutilizar sus capacidades desde servicios y MCP. |
| Operación | Hay comandos de instalación, snapshots, medios y recuperación de contraseña. | Complementan el panel para instalación, mantenimiento y recuperación. |

Documentos base: [arquitectura](../concepts/architecture.md), [MCP](../operations/mcp.md), [templates](../content/templates.md), [Live Editor](../admin/live-editor-ui-ux.md), [rich text](../admin/rich-text.md), [seguridad de cuentas](../admin/account-security.md), [flujo editorial](../admin/editorial-workflow.md) y [snapshots](../operations/snapshots.md).

## 4. Perfiles propuestos

Los perfiles `full` y `essential` están implementados desde fase 2; el panel operativo de essential superó E2E-04 y la revisión independiente de fase 4. Las capacidades posteriores de esta tabla conservan su fase de entrega.

| Capacidad | `full` — Completo | `essential` — Esencial |
|---|---|---|
| Sitio público, SEO, idiomas, medios y formularios | Sí | Sí |
| MCP con permisos del titular | Sí | Sí |
| Páginas HTML y Custom Code | Sí, según permisos | Sí, según permisos |
| Live Editor y formularios editoriales especializados | Sí, para contenido compatible | No |
| Ajustes generales, cuentas, accesos y seguridad | Panel | Panel |
| Biblioteca multimedia | Completa | Consulta, búsqueda, subida y metadatos |
| Páginas y colecciones | Edición y gestión | Listado, búsqueda, estado y preview |
| Formularios recibidos | Bandeja | Bandeja cuando existan formularios |
| Historial y revisiones | Pantallas actuales | Consulta de estado/diff/preview; edición por agente |
| Diagnóstico, copias y restauración | Herramientas actuales | Estado básico; operación por CLI/MCP autorizado |
| Login/consentimiento para conectar una identidad | Sí | Sí |

### Reglas de selección

- Perfil configurado en el proyecto/despliegue; no como ajuste editorial modificable por cualquier agente.
- Valor predeterminado `full`; un valor inválido debe impedir una configuración ambigua y mostrar un diagnóstico.
- Un único resolvedor de capacidades para rutas, navegación, vistas, assets, herramientas e instrucciones.
- Perfil y permisos son dimensiones distintas. Activar un perfil nunca concede publicación, gestión de usuarios o acceso a secretos.
- La superficie MCP es la misma en ambos perfiles. El perfil no recorta herramientas: las operaciones efectivas dependen de los módulos instalados y los permisos de la cuenta y credencial.
- Ambos perfiles conservan login, usuarios, roles, auditoría y segundo factor.
- No ofrecer combinaciones arbitrarias de decenas de flags en 1.0: dos perfiles definidos reducen la matriz de pruebas.
- El modo esencial no debe convertirse gradualmente en otro Live Editor. Cualquier nueva pantalla necesita justificar su utilidad operativa.

### Ubicación del perfil y lectura por agentes

- Propuesta de fuente canónica: `config/cms.php`, clave `admin_profile`, con valores `full` o `essential`. El nombre queda sujeto a la implementación aprobada.
- El perfil queda explícito y versionado en cada proyecto derivado. No duplicarlo en la tabla de ajustes ni permitir que MCP lo cambie como contenido. No se necesita un override de entorno en el alcance inicial.
- El resolvedor central traduce el perfil a capacidades de interfaz y requisitos de desarrollo. La autorización de operaciones se resuelve por separado.
- Una consulta local devuelve al agente el perfil efectivo, capacidades y compatibilidad de bloques sin leer secretos. El descubrimiento MCP expone la misma información relevante al agente remoto.
- `AGENTS.md` y las skills indican consultar el perfil antes de crear páginas, templates, secciones o colecciones. Las comprobaciones verifican entregables según ese perfil.
- Ejemplo: testimonios necesita datos, esquema, renderer, medios, validación y operaciones en ambos modos. En esencial no exige controles visuales; en completo sí, cuando el contrato del contenido requiere un editor especializado.
- Comprobar el perfil del destino antes de desplegar; si difiere del utilizado al desarrollar, informar incompatibilidades antes de activar el cambio.

### Primer acceso: del panel al agente

1. El proceso de instalación crea el primer administrador mediante un mecanismo protegido de aprovisionamiento. El comando de instalación existente es el punto de partida; no se añade registro público de administradores.
2. El propietario entra por el login disponible tanto en completo como en esencial.
3. Desde Usuarios puede crear las cuentas adicionales y asignar sus roles.
4. Desde Integraciones/Accesos puede emitir una credencial con permisos adecuados o autorizar la conexión mediante OAuth. La denominación final de la pantalla queda pendiente; se reutilizará la gestión de claves existente.
5. Conecta su cliente de agentes y continúa el trabajo por MCP. No necesita tener un agente conectado para dar de alta usuarios o preparar esa primera conexión.
6. El panel sigue disponible para revisar medios, gestionar usuarios, revocar accesos o recuperarse de una conexión fallida.

La instalación inicial y el login son pasos distintos: el login autentica una cuenta existente. Si se desea un asistente web para crear el primer administrador, deberá ser de un solo uso y estar protegido por una credencial de instalación; se decidirá en fase 0 y no se considera necesario para el primer incremento.

## 5. Arquitectura objetivo

```text
Panel completo / Panel esencial / MCP / REST / CLI
                        ↓
      Operaciones compartidas y contexto de autorización
                        ↓
     Modelos, publicación, medios, historial y validación
                        ↓
              Base de datos y almacenamiento

Frontend público → contenido publicado → renderer y componentes
```

### Contrato de una operación

Cada operación nueva o extraída define entrada validada, identidad y permisos, estado inicial esperado, resultado, efectos secundarios y errores recuperables. Las escrituras relevantes deben contemplar transacciones, conflictos de versión, auditoría e idempotencia cuando un reintento pueda duplicar efectos.

- Un controlador adapta HTTP; no debe ser el lugar obligatorio donde vive una regla de negocio.
- MCP no simula una sesión de navegador para poder operar.
- Lecturas de secretos devuelven estado o valores enmascarados; configurar un secreto no implica permitir recuperarlo.
- Permisos de contenido no permiten cambiar código, habilitar herramientas administrativas o ampliar los permisos de la propia credencial.
- La extracción será por flujos verticales, manteniendo entradas existentes durante la transición.

## 6. Ampliación de la superficie para agentes

El inventario definitivo se cerrará en la fase 0. Los nombres de herramientas se diseñarán por tarea, evitando inflar el contexto con una herramienta por campo.

| Grupo | Operaciones que debe cubrir | Condición de cierre |
|---|---|---|
| Descubrimiento | Capacidades efectivas, perfil, esquemas, templates, secciones, idiomas, reglas de contenido y ejemplos. | El agente no inventa campos, templates ni permisos. |
| Contenido | Crear, leer, buscar, modificar, proponer cambios en publicado, publicar, retirar, programar y gestionar asociaciones. | Páginas, posts y colecciones registradas comparten garantías editoriales. |
| Idiomas | Crear traducciones, obtener cambios de origen, reconocer campos revisados y configurar locales con permisos. | URLs y asociaciones locales correctas; no copiar rutas absolutas. |
| Medios | Subir, buscar, inspeccionar, traducir metadatos, consultar usos y borrar con protección. | Mismo pipeline y validación que el panel, sin pasar archivos grandes dentro del contexto del modelo. |
| Layout y ajustes | Menús, header, footer, contacto, SEO y configuración admitida. | Un solo dato persistido por concepto, sin ampliar indiscriminadamente la allowlist. |
| Formularios | Consultar envíos, asignar, cambiar estado, anotar y reintentar notificaciones. | La estructura, validación y destinos de formularios siguen definidos por código. |
| Editorial | Versiones, diferencias, restauración, propuestas, revisiones, comentarios, previews y revocación. | Un editor no modifica contenido publicado eludiendo el flujo de aprobación. |
| Custom Code | Listar, crear borradores, inspeccionar reglas, revisar diferencias, activar, desactivar y restaurar snippets. | Misma selección de páginas que el panel, permiso administrativo específico y activación explícita. |
| Identidad | Credenciales, revocación y gestión de cuentas según política administrativa. | El panel permite iniciar y recuperar la conexión; una clave editorial no puede autoelevarse. |
| Operación | Salud, colas, scheduler, redirecciones, diagnóstico y snapshots. | Restauraciones y cambios destructivos requieren un flujo explícito de inspección/aplicación y permisos específicos. |

### Contratos transversales de MCP

- Mantener compatibilidad de las herramientas actuales o proporcionar transición explícita.
- Paginación, selección de campos y respuestas acotadas; documentación recuperable a demanda.
- Errores con causa, campo y posible recuperación; no esconder fallos como respuestas exitosas.
- Conflictos de revisión que impidan sobrescribir silenciosamente cambios humanos o de otro agente.
- Reintentos y trabajos largos con identidad de operación, estado consultable y resultado recuperable cuando corresponda.
- Subidas autorizadas mediante un flujo específico. La importación desde URL es opcional y necesita controles de destinos y tamaño antes de habilitarse.
- Probar tokens reales sin sesión en REST y JSON-RPC, además del flujo de conexión de los clientes objetivo.
- Acordar antes de 1.0 qué modos de conexión se soportan realmente. No prometer compatibilidad solo por implementar JSON-RPC.

## 7. Frontend flexible sin Live Editor obligatorio

### Tres clases de bloque

1. **Estructurado:** componente existente con datos tipados, relaciones y medios por id.
2. **HTML de composición:** fragmento saneado con estructura visual flexible y vocabulario de estilos disponible.
3. **Interactivo registrado:** componente con comportamiento desarrollado en el repositorio y parámetros validados que el agente puede configurar.

Las tres clases comparten orden, visibilidad, identidad estable, versión, preview y publicación. No requieren generar controles visuales en el perfil esencial.

### Contrato del bloque HTML

- Crear un contrato de composición separado del rich text actual, con lista explícita de etiquetas, atributos y estilos admitidos.
- No interpretar Blade, PHP, scripts ni manejadores de eventos almacenados en la base.
- Mantener los medios gestionados por id: referencias semánticas admitidas por el renderer, resueltas a imágenes responsivas al renderizar. No pegar URLs persistentes para esquivar el pipeline.
- Enlaces internos relativos o referencias semánticas; normalización al mover contenido entre dominios.
- Conjunto de clases y tokens de diseño precompilado/documentado. No asumir que Tailwind encontrará clases nuevas guardadas solamente en la base.
- Límites de tamaño y complejidad, saneamiento en todas las entradas y pruebas del HTML efectivamente renderizado.
- Declarar estrategia de idioma: para 1.0 se propone un fragmento por locale, con revisión del fragmento completo; no prometer diferencias por cada nodo HTML.
- Verificar encabezados, enlaces, textos alternativos, responsive y accesibilidad; separar validaciones automáticas de revisión visual.
- Los formularios y componentes interactivos se insertan mediante referencias registradas. El fragmento HTML no define nuevos destinos de envío ni reglas de validación.

### Páginas de un único campo HTML

Añadir una alternativa explícita a las páginas compuestas por secciones. Reutiliza el modelo de página; no necesita una colección paralela ni duplicar URL, SEO, idioma, publicación e historial.

- Un campo de cuerpo HTML saneado, con el mismo contrato de medios, enlaces y estilos de los bloques de composición.
- Dos variantes propuestas: `html` dentro del header/footer del sitio y `empty` como lienzo sin header/footer. Los nombres definitivos se fijan en fase 0.
- El lienzo conserva el documento base, metadatos, idioma y puntos de integración del sitio. Recibe un fragmento de cuerpo, no un documento anidado con sus propias etiquetas `html`, `head` y `body`.
- Flujo MCP: crear página, guardar HTML como borrador, obtener preview, revisar y publicar. Actualizar un HTML publicado respeta propuestas, permisos y conflictos.
- No es obligatorio dividir ese cuerpo en tarjetas/campos ni desarrollar un Live Editor específico. En completo se puede ofrecer edición de fuente con permiso adecuado; no se promete convertir el fragmento en controles visuales.
- Estilos ya disponibles pueden usarse sin build. CSS adicional puede gestionarse como Custom Code autorizado; PHP/Blade siguen fuera del contenido.
- El HTML editorial no incluye scripts. Las integraciones y scripts de navegador se administran por la superficie separada de Custom Code.

### Custom Code global con reglas por página

Nueva sección administrativa disponible en completo y esencial, con las mismas operaciones expuestas por MCP según permisos. Permite integrar código adicional sin modificar las plantillas del repositorio en cada caso.

#### Registro de un snippet

- Nombre, descripción, tipo (`HTML`, `CSS` o `JavaScript`), contenido, posición y orden de carga.
- Posiciones propuestas: final de `head`, inicio de `body` y final de `body`. El renderer controla los wrappers de CSS/JavaScript y valida compatibilidad de tipo y posición.
- Estado borrador/activo/inactivo, versión, autor y registro de activación. Editar genera una propuesta de versión; no modifica silenciosamente el código activo.
- Reglas: todo el sitio público, solo páginas seleccionadas o todo excepto páginas seleccionadas. Si coinciden reglas, la exclusión prevalece.
- La selección usa referencias estables de entidad, no URLs absolutas. Debe distinguir página, post y cualquier entidad pública registrada.
- Un selector de idioma permite aplicar a una traducción concreta o a todo el grupo de traducciones de forma explícita. No inferir que seleccionar español habilita inglés.
- En 1.0, selección exacta de destinos registrados; patrones arbitrarios de URL y reglas por dispositivo quedan fuera. Los índices públicos deben tener identificadores de destino propios si son seleccionables.

#### Renderizado y comportamiento

- Un resolvedor central decide qué snippets aplican al destino público; panel y MCP muestran una previsualización de ese resultado antes de activarlos.
- El alcance global cubre el sitio público, incluidas las páginas HTML y lienzo. Nunca incluye login, OAuth, backoffice o endpoints de API; páginas de error quedan excluidas inicialmente.
- Las exclusiones prevalecen también sobre selección global; referencias eliminadas no se convierten en coincidencias globales y deben diagnosticarse.
- No cargar snippets activos en el Live Editor ni en previews ordinarias por defecto. Un modo explícito de prueba debe advertir que ejecutará código real y puede producir eventos externos.
- Orden determinista y una sola inserción por snippet. No prometer deduplicación automática de código equivalente ni orden de ejecución garantizado entre scripts externos asíncronos.
- Mantener Google Tag, Meta Pixel y Search Console como integraciones específicas, con sus configuraciones actuales. Señalar posibles duplicaciones al introducir snippets; no crear una segunda fuente para sus identificadores.
- No ejecutar PHP, Blade ni código del servidor. No almacenar secretos del servidor: el código insertado en el navegador es visible para el visitante.

#### Permisos y recuperación

Custom Code es código de navegador confiable y puede acceder al documento público. No se presenta como HTML editorial sanitizado ni como un sandbox.

- Permiso específico para gestionar/activar código, separado de editar páginas. Una credencial editorial existente no obtiene automáticamente este permiso.
- En MCP, guardar y activar son operaciones distintas; la activación identifica exactamente la versión revisada y detecta conflictos.
- Proteger la propia interfaz administrativa: el listado y editor muestran el código escapado, nunca lo ejecutan.
- Validaciones de tamaño, tipo, estructura y compatibilidad; no prometer que una validación sintáctica demuestra que cualquier script es seguro.
- Historial, auditoría, desactivación individual y un mecanismo operativo de apagado global por configuración/CLI si un snippet rompe el frontend.
- Incluir snippets y versiones en snapshots. Una restauración conserva el código para revisión, pero deja los snippets inactivos hasta reactivación explícita en el destino.
- La autorización de este plan no autoriza ejecutar ni publicar ningún snippet real.

### Lo que implica cambiar de perfil

Cada tipo de bloque declara su compatibilidad de edición. Pasar a `full` debe conservar y renderizar los bloques creados por agentes. Los que no tengan editor visual muestran estado/preview y una explicación; no se convierten silenciosamente ni pierden información.

La conversión a campos visuales especializados es trabajo adicional explícito. Desactivar el Live Editor nunca debe migrar o borrar contenido por sí mismo.

Los artifacts empaquetados con JavaScript arbitrario en un entorno aislado quedan fuera de la primera versión. Son distintos del gestor de scripts confiables de Custom Code incluido en este plan. Si se incorporan después, requerirán su propio contrato de aislamiento, recursos, red y comunicación con el sitio.

## 8. Fases de implementación y puertas E2E

**Orden recomendado:** fases 0 a 11. Cada fase entrega una capacidad utilizable y termina con un recorrido E2E obligatorio. Una fase puede dividirse en varios commits, pero no se considera aprobada por tener código o pruebas unitarias: su recorrido debe pasar sobre la aplicación desplegada en un entorno aislado.

El propietario autorizó la ejecución autónoma completa del plan hasta 1.0, con programación y pruebas por Sol 6.1. Cada fase debe aprobar su E2E antes de comenzar la siguiente. Los E2E de fase 0 ya fueron ejecutados; los de fases posteriores siguen por construir y no se consideran aprobados anticipadamente.

### Protocolo común de verificación

- **Entorno:** instalación aislada, MySQL, almacenamiento temporal, servidor HTTP real, assets compilados, worker de cola y scheduler cuando corresponda. Sin datos ni credenciales de clientes.
- **Identidades:** administrador, editor y credencial de solo lectura; claves reales en el transporte MCP, sin autenticación simulada del framework. Incorporar permisos adicionales cuando cada fase los implemente.
- **Datos:** fixture repetible con páginas de inicio/contacto, una colección, español/inglés, medios, borrador y publicación. Restaurar el estado inicial antes de cada escenario y controlar el reloj para publicaciones, caducidad y tokens de formulario.
- **Canales:** navegador para panel y web pública; cliente MCP por HTTP para operaciones de agente; CLI solo para instalación/configuración/recuperación. No usar escrituras directas en la base para completar pasos funcionales.
- **Aserciones:** comprobar el resultado desde otra superficie: escritura MCP → navegador público, edición administrativa → lectura MCP. La inspección de datos sirve como evidencia adicional; un HTTP 200 o una captura por sí solos no aprueban el flujo.
- **Servicios externos:** buzón/transporte de correo de pruebas y respuesta de reCAPTCHA controlada en el límite externo. Documentar estas sustituciones; mantener reales aplicación, cola, almacenamiento y persistencia. Ningún test envía correo o tracking real a terceros.
- **Evidencia por fase:** revisión de código probada, perfil, versiones de entorno, fixture, pasos o comando reproducible, aserciones, resultado y artefactos pertinentes. Guardar trazas/capturas y respuestas MCP sin tokens, cookies ni secretos.
- **Regla de salida:** E2E propio aprobado, regresiones de fases afectadas aprobadas, convenciones y suite exigida por el repositorio correctas. Un fallo bloquea la promoción; corregirlo y repetir el recorrido afectado.
- **Automatización:** preferir recorridos deterministas de navegador y protocolo para la regresión. Añadir pruebas reales con agentes en las fases 2 y 11; no depender de decisiones variables del modelo como único verificador.
- **Reversión:** ensayar cada mecanismo indicado en el entorno aislado antes de declararlo disponible. No promover configuraciones parciales como perfiles soportados para producción.

### Resumen de entregas

| Fase | Entrega | E2E de aprobación |
|---|---|---|
| 0 | Reparación del MCP actual, inventario y línea base | Codex descubre las herramientas; edición, idiomas, paginación y permisos superan regresiones, y el sitio sigue funcionando. |
| 1 | Operaciones compartidas | Panel y MCP cambian el mismo contenido sin divergencias. |
| 2 | Perfil y contrato para agentes | La misma sección requiere editor en completo y no en esencial. |
| 3 | MCP de contenido, idiomas y medios | Página bilingüe con imagen, menú y SEO publicada desde MCP. |
| 4 | Panel esencial | Persona inicia sesión, conecta el agente y supervisa sin Live Editor. |
| 5 | Editorial por MCP | Propuesta, revisión, publicación y restauración sin alterar antes el sitio vivo. |
| 6 | Formularios y seguimiento por MCP | Envío público llega a la bandeja y completa notificación/reintento. |
| 7 | Páginas HTML y componentes | Página con layout y lienzo cambian por MCP sin nuevo build. |
| 8 | Custom Code | Snippet se aplica solo donde corresponde y puede apagarse. |
| 9 | Identidad y paridad de clientes | Conexión, revocación y recuperación funcionan en ambos perfiles. |
| 10 | Migración, snapshots y piloto | Actualización y vuelta entre perfiles conservan el sitio. |
| 11 | Candidato y lanzamiento | Recorrido completo reproducible habilita la publicación de 1.0.0. |

### Fase 0 — Arreglar el MCP actual, inventario y línea base

**Objetivo:** resolver los defectos y límites básicos detectados antes de ampliar la superficie MCP o introducir perfiles. Esta fase incluye correcciones de producto autorizadas por el propietario. Estado detallado y evidencias: [resultados de fase 0](phase-0-results.md).

**Evidencia inicial (2026-10-10):** en la conversación «Listar páginas y estados» (`01a1273a-d236-70c2-9651-d1536a39d588`), Codex listó 13 páginas y 32 medios, creó el borrador 15 y guardó/releyó su descripción. No se probaron publicación, permisos negativos, restauración ni conservación de secciones: el borrador tenía `content_json: null`. El intento de estado inválido no llegó al servidor y no cuenta como validación aprobada. Estos resultados manuales orientan las correcciones; no sustituyen los E2E aislados siguientes.

**Trabajo**

- [x] Inventariar pantallas, rutas, herramientas, comandos y reglas; completar matriz panel/MCP/CLI con permisos.
- [x] Cerrar alcance del panel esencial, templates HTML, selección de destinos de Custom Code y clientes MCP objetivo.
- [x] Preparar fixture, procedimiento de instalación aislada y mediciones iniciales de frontend.
- [x] Registrar límites actuales y distinguir defectos previos de regresiones nuevas.
- [x] Reparar el descubrimiento: el registro contiene 22 herramientas, pero Codex expone 20; faltan `menus_list` y `settings_list`. Investigar y corregir el esquema/transporte, sin duplicar herramientas existentes. Ambas usan `properties: []`; es una hipótesis de causa, no un diagnóstico confirmado del cliente.
- [x] Definir e implementar edición parcial de `content_json` que conserve claves no enviadas. Documentar sustitución de listas, eliminación explícita y tratamiento de `null`; no asumir que una mezcla recursiva genérica resuelve todos los casos. Actualizar descripción/esquema de la herramienta.
- [x] Evitar que una edición retire silenciosamente una página publicada. Hasta disponer del flujo editorial de la fase 5, rechazar la edición por esta herramienta sobre publicados, también para administradores, con un error que explique la limitación y sin mutar contenido ni estado. La fase 5 añadirá preparación de cambios manteniendo la versión pública.
- [x] Exponer `locale` y `translation_group_id` en consultas de páginas/posts y permitir idioma explícito validado al crear contenido. Revisar unicidad de slugs por idioma y los contratos existentes. La creación/vinculación completa de traducciones continúa en fase 3.
- [x] Añadir paginación utilizable por MCP a páginas, posts y medios, con parámetros documentados y metadatos coherentes. No depender de aumentar `per_page` ni de inferir que la primera página es el inventario completo.
- [x] Verificar restricciones reales de claves y roles, errores de validación y rechazo sin mutación. No considerar la ausencia de errores en operaciones válidas como prueba de seguridad.

- [x] Corregir rechazos de controlador comunicados como éxito: MCP devuelve `isError: true` para respuestas HTTP >= 400, con regresión 422/404 y sin mutaciones; HTTP 200 por sí solo no aprueba una llamada.
- [x] Cerrar la aceptación con una sesión nueva de Codex: 22 herramientas descubiertas; `menus_list` y `settings_list` ejecutados con `isError: false`, y `menus_get` consultado con un ID descubierto. Ajustes contraseña redactados. HTTP y regresiones aprobados.

**E2E-00-MCP — Reparaciones verificables antes de ampliar capacidades**

Preparar contenido bilingüe enlazado, un menú, un ajuste no sensible consultable, una página publicada y un borrador con varias secciones y metadatos. Usar credenciales aisladas de administrador, editor y solo lectura. Registrar el contrato anterior y el nuevo para cada corrección.

| Caso | Acción verificable | Resultado exigido |
|---|---|---|
| Descubrimiento | Conectar un cliente HTTP real y una sesión nueva de Codex; descubrir herramientas y ejecutar ambos listados. | Paridad con el catálogo registrado para esa versión e identidad; `menus_list` y `settings_list` visibles y ejecutables por identidad autorizada. Consultar después un menú por el ID descubierto, sin suministrarlo desde fuera del MCP. No registrar secretos de ajustes. |
| Edición parcial | Leer un borrador con hero, CTA, listas y SEO; cambiar solo `meta.description`; releerlo y abrirlo en el panel. | Solo cambia el campo solicitado y la metadata de auditoría esperada; las secciones y referencias se conservan. Añadir casos de sustitución de listas y eliminación conforme al contrato elegido. |
| Publicado protegido | Intentar editar por `pages_update_draft` una página publicada como administrador y como editor; consultar de nuevo y abrir su URL sin sesión. | Rechazo explícito, sin cambios en contenido, estado o fecha de publicación; el sitio público mantiene la versión anterior. Probar también el flujo equivalente de posts. |
| Idiomas | Consultar traducciones enlazadas y crear un borrador indicando un idioma instalado; consultar el resultado y abrirlo en el panel. | Idioma y grupo son explícitos y correctos. Un idioma no instalado se rechaza sin crear registros. La unicidad de slug respeta el contrato por idioma. |
| Paginación | Preparar más de 100 registros por dominio paginado y recorrer todas las páginas mediante MCP. | Todos los IDs esperados aparecen una sola vez en un conjunto estable, con total y fin de paginación correctos. |
| Permisos | Con clave de solo lectura, consultar e intentar una escritura; con editor o clave limitada a borradores, intentar publicar. | Lectura permitida y mutaciones no autorizadas rechazadas; verificar estado y contenido desde una identidad de lectura autorizada. |
| Validación | Enviar un slug duplicado dentro del mismo idioma y un valor inválido permitido por el tipo de entrada, por ejemplo `per_page: 0`, usando el cliente de protocolo. | Error explícito y sin mutación. Si el cliente bloquea antes de enviar, registrar ese resultado por separado y probar el rechazo del servidor con el cliente HTTP del E2E. No repetir el falso positivo de un `status` que no se llegó a enviar. |

Cada caso conserva evidencia antes/después y debe aprobarse por separado. Una sesión real de Codex confirma interoperabilidad; las regresiones automatizadas reproducen los mismos contratos sin depender del modelo. Las comprobaciones de SEO público distinguen valores almacenados de fallbacks renderizados; las diferencias entre traducciones no se corrigen automáticamente.

**E2E-00 — El sitio actual funciona antes de migrarlo**

1. Instalar la versión base; entrar como administrador y crear una página mediante el editor actual.
2. Publicarla, abrirla sin sesión y comprobar título, cuerpo, URL, SEO e imagen.
3. Leer esa misma página mediante MCP con una clave válida.
4. Enviar el formulario público después del tiempo mínimo, procesar la cola y comprobar recepción en la bandeja y el buzón de pruebas.
5. Solicitar el contenido con una credencial inválida y comprobar rechazo sin mutación.

**Aprobación:** E2E-00 y todos los casos de E2E-00-MCP aprobados sobre la base corregida; conteos/contenido esperado y evidencia de navegador, MCP y correo registrados. La conexión que funciona no basta para aprobar edición ni permisos. El inventario no deja operaciones necesarias sin vía prevista. No comenzar la ampliación de fase 1 con estos defectos abiertos.

**Reversión:** aplicar las correcciones en incrementos independientes y reversibles. Conservar snapshot previo y ensayar restauración de datos/esquema si una corrección requiere migración; revertir el incremento afectado y repetir la línea base. Si revertir reintroduce edición destructiva o despublicación inesperada, bloquear esa escritura MCP hasta resolverlo. Eliminar únicamente fixtures y credenciales del entorno aislado; no borrar automáticamente la página 15 de la instalación local.

### Fase 1 — Núcleo de operaciones compartidas

**Trabajo**

- [x] Extraer primero guardar borrador/publicar páginas a operaciones independientes de controladores y sesión.
- [x] Mantener adaptadores admin/REST/MCP compatibles; unificar validación, permisos, transacciones y errores.
- [x] Mantener los demás dominios para su fase funcional, evitando una reescritura masiva inicial.

**Estado de entrega:** aceptada en 0.47.2; operaciones con actor explícito, validación compartida y transacciones con historial/revisiones. E2E-00, worker y E2E-01 aprobados sobre MySQL aislado; suite completa y convenciones aprobadas. [Evidencia y límites](phase-1-results.md).

**E2E-01 — Una operación, varias entradas**

1. Crear un borrador desde el panel y leerlo por MCP.
2. Modificarlo por MCP, reabrirlo en el panel y comprobar exactamente los valores guardados.
3. Publicar mediante una identidad autorizada y comprobar el resultado en el sitio público.
4. Intentar la misma escritura con una clave de solo lectura y la publicación con un editor; comprobar rechazo y ausencia de cambios.
5. Repetir la mutación equivalente sobre un fixture independiente por REST y comprobar los mismos efectos.

**Aprobación:** datos y reglas coinciden en las tres entradas, sin dependencia de cookies para MCP. E2E-00 permanece correcto.

**Reversión:** revertir la extracción del flujo afectado manteniendo formato de datos e interfaces previas.

### Fase 2 — Perfil, descubrimiento y contrato de desarrollo

**Trabajo**

- [x] Implementar perfil central `full`/`essential`, predeterminado `full`, consulta local y descubrimiento MCP.
- [x] Introducir capacidades coherentes para rutas, enlaces y assets; esencial aún no se anuncia estable.
- [x] Adaptar AGENTS, skills y convenciones para que no exijan controles visuales en esencial.

**E2E-02 — La misma solicitud produce los entregables correctos**

1. En dos copias desechables del proyecto, seleccionar completo y esencial respectivamente y comprobar el perfil reportado localmente y por MCP.
2. Pedir al agente añadir una sección de testimonios con campos personalizados, usando el mismo brief y datos en ambas copias.
3. En completo, comprobar que se genera su editor y permite guardar/renderizar los datos. En esencial, comprobar que la sección se renderiza y se opera sin crear un partial editorial específico.
4. Ejecutar convenciones: la falta del editor requerido falla en completo y no en esencial; violaciones de medios/publicación siguen fallando en ambos.
5. Cambiar solo el perfil, regenerar cachés y reiniciar procesos pertinentes; verificar lectura correcta sin cambiar contenido. Una configuración inválida produce un diagnóstico explícito.

**Aprobación:** dos entregas funcionales revisadas por artefactos y comportamiento, no por la afirmación del agente. Guardar brief, contexto y diffs; incorporar un fixture determinista equivalente a la regresión automatizada.

**Reversión:** seleccionar `full`; no hay transformación de datos al cambiar perfil.

### Fase 3 — MCP de contenido, idiomas, medios y layout

**Trabajo**

- [x] Descubrimiento de esquemas/templates y operaciones para contenido, asociaciones, traducciones y programación.
- [x] Subida de medios mediante el pipeline actual; usos, metadatos localizados y borrado protegido.
- [x] Menús, contacto, layout y ajustes aprobados; respuestas acotadas y contratos de conflictos/reintentos.

**E2E-03 — Publicar una página bilingüe desde una conexión MCP**

1. Sin sesión de navegador, consultar capacidades, elegir un template válido y subir una imagen.
2. Crear la página en español y su traducción al inglés, referenciar el medio por id y configurar alt, SEO y menú.
3. Generar preview y comprobar el contenido en navegador; el borrador no aparece en la URL pública ni en el sitemap.
4. Publicar y comprobar ambos idiomas, enlaces, canonical/hreflang aplicables e imagen responsiva.
5. Intentar borrar la imagen utilizada y sobrescribir una revisión obsoleta; ambas acciones fallan sin pérdida de datos.
6. Programar una publicación futura en otro registro; comprobar ausencia antes y presencia después del instante previsto con reloj controlado.

**Aprobación:** los resultados se observan en el sitio público y coinciden con los datos MCP. Repetir en ambos perfiles y verificar paridad de herramientas para idéntica identidad.

**Reversión:** desactivar herramientas nuevas afectadas, conservando las actuales, los datos y el panel completo.

**Resultado:** fase aceptada con [evidencia](phase-3-results.md), 36 herramientas comunes, E2E-03 real en ambos perfiles y regresiones acumuladas.

### Fase 4 — Panel esencial utilizable

**Trabajo**

- [x] Reutilizar login, cuentas, accesos, ajustes, biblioteca y bandeja; listados ligeros con estado y preview.
- [x] Retirar navegación y rutas del Live Editor en esencial; evitar cargar su runtime y dependencias editoriales.
- [x] Mantener acceso administrativo a la configuración de integraciones; no crear CRUD editorial alternativo.

**E2E-04 — Entrar como persona y continuar con el agente**

1. Aprovisionar el primer administrador y entrar por el login en esencial.
2. Crear un usuario con rol definido y una credencial para operar el sitio; conectar el cliente MCP con esa credencial.
3. Cambiar contenido mediante MCP y comprobarlo desde el listado/preview del panel y desde la URL pública según su estado.
4. Buscar un archivo y editar un ajuste desde las pantallas conservadas; comprobar sus efectos.
5. Abrir directamente una URL del Live Editor: no debe habilitarlo. Revisar la red del navegador: no se carga su bundle ni TinyMCE en estas pantallas esenciales.
6. Volver a completo; la edición visual compatible funciona y el contenido se conserva.

**Aprobación:** no hay enlaces rotos, formularios editoriales encubiertos ni cargas de editor en esencial. El cambio de perfil no altera permisos ni reduce MCP.

**Reversión:** configurar `full` y regenerar cachés; verificar recuperación de rutas existentes.

**Resultado:** fase aceptada con [evidencia](phase-4-results.md): instalación real, usuario/credencial desde navegador, cliente Codex Sol 6.1, panel esencial sin editor, vuelta a Full, revisión independiente y regresiones acumuladas aprobadas.

### Fase 5 — Revisión, versiones y recuperación por MCP

**Trabajo**

- [x] Compartir y exponer propuestas, revisiones, comentarios, diferencias, restauración y previews revocables.
- [x] Proteger conflictos entre agentes y humanos; conservar contratos de publicación y programación.

**E2E-05 — Revisar sin cambiar prematuramente lo publicado**

1. Publicar una página; un editor propone por MCP una modificación y solicita revisión a un administrador.
2. Verificar que la página pública sigue intacta y que la preview de la propuesta muestra el cambio exacto.
3. Añadir comentario; aprobar con el revisor autorizado y comprobar el cambio público.
4. Crear otra revisión, cambiar su base y tratar de aprobar la revisión obsoleta: debe rechazarse.
5. Restaurar una versión anterior como borrador, revisarla y republicarla; comprobar contenido y asociaciones.
6. Revocar la preview y comprobar que deja de mostrar el borrador. Con reloj controlado, comprobar caducidad y retiro programado.

**Aprobación:** trazabilidad de autor y versiones, permisos y conflictos correctos en ambos perfiles. Ningún paso editorial requiere el Live Editor.

**Reversión:** desactivar las nuevas entradas MCP afectadas, conservando historial y flujos administrativos existentes.

**Cierre:** implementación y E2E-05 aceptados; [resultados](phase-5-results.md).

### Fase 6 — Formularios y seguimiento operativo por MCP

**Trabajo**

- [x] Exponer búsqueda de envíos, asignación, estados, notas y reintento de notificaciones.
- [x] Mantener formularios definidos por código y reutilizar las reglas antispam, cola y permisos actuales.

**E2E-06 — Del visitante al seguimiento y la entrega**

1. Abrir una página de contacto publicada, completar el formulario y enviarlo con token válido tras el tiempo mínimo.
2. Consultar el envío desde MCP y verificar el mismo registro en la bandeja del panel.
3. Asignar responsable, añadir nota y cambiar estado por MCP; recargar el panel y comprobarlo.
4. Provocar un fallo controlado del transporte de correo de pruebas, agotar sus reintentos y comprobar estado fallido visible.
5. Recuperar el transporte, solicitar reenvío y procesar la cola; comprobar recepción en el buzón y estado enviado. Repetir la petición tras el éxito y comprobar que no genera otro envío.
6. Intentar enviar con token inválido y desde una página no publicada; comprobar que no aparecen nuevos registros válidos ni mensajes.

**Aprobación:** persistencia, worker, estados y buzón concuerdan; no se envía correo real. El escenario no promete entrega exactamente una vez ante fallos del proveedor tras aceptar el correo.

**Reversión:** desactivar nuevas operaciones MCP de seguimiento; conservar bandeja, formularios y cola.

**Cierre:** implementación, revisión independiente y E2E-06 aceptados; [resultados](phase-6-results.md).

### Fase 7 — Páginas HTML, bloques y componentes

**Trabajo**

- [x] Implementar cuerpo HTML saneado, variantes con layout/lienzo y vocabulario de estilos.
- [x] Añadir composición de bloques con identidad estable, medios por id, idiomas, historial y un componente interactivo registrado.
- [x] Definir presentación en completo de contenido sin editor visual compatible.

**E2E-07 — Rediseñar una landing sin generar un editor ni recompilar**

1. Desde MCP crear una página con un campo HTML dentro del layout y otra tipo lienzo; incluir títulos, columnas, enlaces y un medio por id.
2. Abrir previews en escritorio y móvil; comprobar header/footer solo en la primera y metadatos base en ambas.
3. Cambiar el HTML, el orden de bloques y parámetros del componente registrado; verificar el resultado y su interacción sin ejecutar un nuevo build.
4. Introducir marcadores de script y atributos de evento inocuos de prueba en el HTML editorial: no se ejecutan; el contenido admitido se conserva.
5. Traducir el fragmento, publicar y comprobar idioma, SEO y medio; restaurar una versión y revisar sus referencias.
6. Cambiar a completo: el contenido sigue renderizando y se informa qué no tiene editor especializado, sin conversiones destructivas.

**Aprobación:** capturas desktop/móvil, aserciones de DOM/comportamiento y huella del build sin cambios. No aparecen scripts editoriales ejecutables ni URLs de medios persistidas para saltarse el contrato.

**Reversión:** desactivar escrituras nuevas conservando el renderer del formato ya publicado; restaurar la versión de contenido si procede.

**Resultado:** fase aceptada con [evidencia](phase-7-results.md), 52 herramientas comunes, E2E-07 final en ambos perfiles, traducción inglesa distinta, build sin cambios durante operación y revisión independiente sin hallazgos pendientes.

### Fase 8 — Custom Code y selección de destinos

**Trabajo**

- [x] Snippets versionados, tipos/posiciones, orden y reglas exactas de inclusión/exclusión e idioma.
- [x] Pantalla en ambos perfiles y operaciones MCP con permiso específico; guardar separado de activar.
- [x] Apagado independiente del frontend, auditoría y diagnóstico de integraciones duplicadas.

**E2E-08 — Aplicar código exactamente donde corresponde**

1. Preparar tres páginas y sus traducciones, incluida una de lienzo. Crear por el panel un CSS de prueba y leerlo mediante MCP.
2. Crear mediante MCP un JavaScript inocuo que marque su ejecución localmente. Activar una versión concreta con alcance global y exclusión de una página.
3. Abrir cada destino y comprobar: CSS aplicado, marcador ejecutado una vez, ausencia en la excluida y posición/orden de inserción correctos.
4. Cambiar a inclusión exacta de una traducción; comprobar ausencia en su idioma hermano. Editar un borrador sin activarlo y comprobar que sigue sirviéndose la versión activa.
5. Visitar admin, login, consentimiento OAuth y preview ordinaria: los marcadores están ausentes. Una credencial editorial no puede activar el snippet.
6. Desactivar individualmente; reactivar y usar el apagado global operativo. El código desaparece del sitio sin necesitar el frontend para recuperarlo.
7. Activar un snippet de prueba que afecte deliberadamente solo al layout público de la instalación aislada y verificar recuperación mediante el mismo apagado.

**Aprobación:** matriz destino/idioma/posición automatizada, sin tráfico externo; exclusiones prevalecen y panel/MCP muestran las mismas reglas. La reversión devuelve el sitio a su estado anterior.

**Reversión:** apagado global conservando snippets/versiones. El guard mínimo de restore deja el código inactivo y no recupera permisos; el gate completo de snapshots se conserva en fase 10.

**Resultado:** fase aceptada en 0.54.0 con [evidencia](phase-8-results.md), E2E-08 real en ambos perfiles, permisos específicos, activación de versión exacta, destinos/idiomas/exclusiones, recuperación CLI sin frontend y revisión independiente sin hallazgos pendientes.

### Fase 9 — Identidad, recuperación y compatibilidad de clientes

**Trabajo**

- [x] Completar los recorridos de conexión inicial, consentimiento, segundo factor, rotación, revocación y recuperación.
- [x] Validar Codex real en ambos perfiles y revisar límites, errores, reintentos y estado asíncrono; matriz aceptada con excepción explícita de Claude.
- [ ] Seguimiento aplazado por el propietario: ejecutar Claude real tras recuperar su login; no certificado.
- [x] Asegurar paridad entre perfiles y ausencia de enlaces a pantallas deshabilitadas.

**E2E-09 — Conectar, operar, revocar y recuperar**

1. En completo y esencial, crear desde el panel cuentas de prueba y conectar los clientes objetivo con el mecanismo soportado: clave u OAuth.
2. Completar segundo factor cuando corresponda y ejecutar una lectura y una escritura permitidas desde el cliente real.
3. Comparar las capacidades para la misma identidad en ambos perfiles; comprobar rechazo de una operación fuera del ámbito.
4. Revocar la conexión y repetir la operación: no modifica datos. Emitir una nueva conexión desde el panel y comprobar recuperación.
5. Ejecutar el procedimiento documentado ante pérdida de acceso con cuentas ficticias, comprobando invalidación de credenciales antiguas.
6. Interrumpir una respuesta después de una escritura y aplicar su contrato de reintento; comprobar que no duplica el efecto. Probar consulta de estado para las tareas asíncronas incluidas.

**Aprobación:** evidencia de cliente real además de pruebas deterministas del protocolo, sin secretos en el informe. La pantalla de login sigue disponible y no exige un agente ya conectado.

**Reversión:** conservar métodos de acceso compatibles durante la transición; revocar conexiones de prueba y volver al flujo estable documentado.

**Resultado:** fase aceptada en 0.54.1: identidad, recuperación, HTTP/MySQL, navegador y Codex con API key
pasan en ambos perfiles. El propietario autorizó el 2026-10-10 continuar y aplazar Claude
(«continua trabajando, eso lo vemos mas tarde»): queda pendiente y no certificado. OAuth navegador/HTTP
verifica el servidor, sin certificar login OAuth del cliente. [Evidencia y procedimiento](phase-9-results.md).
La excepción permite continuar y conserva íntegros los gates 10–11.

### Fase 10 — Actualización, snapshots, transiciones y piloto

**Trabajo**

- [ ] Actualizar copia aislada de un sitio 0.47.0 e instalar otro desde cero.
- [ ] Probar todos los cambios de perfil y snapshots anteriores/actuales; ensayar rollback de código y datos.
- [ ] Pilotar un sitio en esencial y repetir los flujos MCP en completo; registrar incidencias e intervención necesaria.

**E2E-10 — Un sitio existente atraviesa la migración y vuelve a ser recuperable**

1. Crear un manifiesto inicial de URLs, idiomas, contenido, medios, estados y permisos; exportar snapshot previo.
2. Actualizar código y migrar por el procedimiento documentado. Reejecutar migraciones y comprobar que no duplican datos.
3. Recorrer completo → esencial → completo con configuración/rutas cacheadas; verificar publicación y datos contra el manifiesto.
4. Crear contenido HTML y Custom Code nuevos; exportar y restaurar en otra instalación compatible.
5. Comprobar medios y versiones, perfil del destino conservado, credenciales/previews no reactivadas y snippets restaurados inactivos.
6. Restaurar también el snapshot antiguo y comprobar sus datos; rechazar un archivo de formato incompatible con diagnóstico.
7. Ensayar el retorno al despliegue previo con su copia de datos correspondiente, no con migraciones inversas destructivas.

**Aprobación:** comparación de manifiestos sin diferencias inesperadas, recorridos públicos/administrativos/MCP correctos y rollback demostrado. Piloto sin incidencias bloqueantes abiertas.

**Reversión:** restaurar el conjunto de código, datos y archivos validado antes del cambio.

### Fase 11 — Candidato y publicación de 1.0.0

**Trabajo**

- [ ] Congelar contratos y resolver bloqueos del piloto.
- [ ] Completar guía de actualización, operación, limitaciones y recuperación.
- [ ] Ejecutar los E2E acumulados sobre la revisión candidata exacta y los dos perfiles.

**E2E-11 — Entrega completa reproducible**

1. Partir de instalación limpia; el operador entra al panel y conecta un agente real.
2. Solicitar una landing bilingüe con medios, formulario, una página HTML/lienzo y una integración Custom Code de prueba restringida a un destino.
3. Obtener previews, revisar, publicar y comprobar el sitio desde navegador sin sesión, en escritorio y móvil.
4. Recibir un formulario, procesarlo por MCP y comprobar correo; modificar contenido mediante revisión y restaurar una versión.
5. Verificar exclusiones del snippet, desactivarlo y recorrer una recuperación de acceso y de snapshot.
6. Repetir en el otro perfil; ejecutar también la suite determinista completa y comprobar los presupuestos acordados de SEO/rendimiento.

**Aprobación:** todos los recorridos y la matriz de aceptación pasan, la evidencia permite reproducirlos y el propietario dispone de un informe de salida. No se aprueba por número de herramientas ni por capturas aisladas.

**Publicación:** solo entonces actualizar conjuntamente `VERSION` y changelog a `1.0.0`. Si el commit de release cambia comportamiento, repetir sus E2E afectados; comprobar que el artefacto publicado corresponde a la revisión validada.

**Reversión:** conservar el artefacto y copia de datos previos del piloto; documentar la recuperación antes de cualquier despliegue real.


## 9. Política de datos, despliegue y reversión

1. Preferir migraciones aditivas: nuevas columnas/tablas y lectores compatibles antes de cambiar escritores.
2. No borrar tablas, partials o lógica antigua al introducir perfiles; retirar dependencias solo con evidencia de que ya no se usan.
3. Los backfills deben ser repetibles, con inspección previa y conteos verificables; probar en copia aislada antes de producción.
4. Cambiar un perfil no equivale a migrar contenido y no debe alterar visibilidad pública, permisos o credenciales.
5. El perfil pertenece al despliegue. Restaurar contenido no debe cambiarlo inadvertidamente; registrar el origen del snapshot solo como información de compatibilidad.
6. Validar compatibilidad por esquema/formato, además de la versión declarada. No dar por hecho que subir a 1.0 vuelve compatibles todos los archivos previos.
7. Ante un fallo, detener nuevas escrituras del flujo afectado, conservar evidencia y recuperar con el procedimiento ensayado. No usar migraciones destructivas inversas como rollback automático de producción.
8. Reiniciar trabajadores y regenerar cachés/rutas cuando lo requiera el cambio de configuración; verificar también configuración y rutas cacheadas.

## 10. Documentación y skills como parte del producto

- Separar contratos invariantes de datos/publicación de las instrucciones exclusivas del Live Editor.
- Añadir una guía breve por perfil, accesible desde el mapa de documentación y descubrible por el agente.
- Cada skill identifica el perfil antes de determinar entregables. No debe leer toda la documentación del panel para una tarea que no necesita editor visual.
- En completo: mantener el contrato UI/UX actual para los editores que sí existan.
- En esencial: exigir esquema, renderer, operaciones, permisos, traducciones, medios y pruebas; no partials visuales por defecto.
- Conservar reglas de formularios, referencias por id, publicación, portabilidad y paridad ES/EN donde haya interfaz humana.
- Mantener ejemplos de solicitudes completas: nueva página, nueva colección, nueva sección HTML, traducción, cambio en publicado y recuperación.
- Las guías no sustituyen controles del servidor: reglas críticas deben tener validación o pruebas.

## 11. Matriz de aceptación de 1.0

| Recorrido | Evidencia requerida |
|---|---|
| Actualización de instalación existente | Sin cambios involuntarios en URLs, contenido, medios, permisos ni experiencia completa. |
| Nueva sección | En completo tiene editor conforme; en esencial se crea sin editor y conserva todos los contratos de datos. |
| Operación editorial por MCP | Crear, traducir, revisar, publicar, retirar y restaurar con tokens reales y sin sesión administrativa. |
| Medios | Subida válida, rechazo de archivo inseguro, variantes, metadatos por idioma y bloqueo de borrado en uso. |
| Formulario público | Antispam completo, persistencia, cola, reintento y seguimiento en cada perfil. |
| HTML flexible | Sanitización, estructura responsive, estilos disponibles, medios por id, traducción y restauración. |
| Página HTML/lienzo | Un campo de cuerpo, con/sin header-footer, SEO y publicación conservados, preview y edición por MCP sin Live Editor. |
| Custom Code | Inclusión/exclusión exacta, idiomas, posiciones, orden, permiso específico, versión activada, ejecución única y ausencia en admin/previews ordinarias. |
| Recuperación de código personalizado | Apagado operativo funciona aunque el sitio público falle; snapshots restauran snippets inactivos. |
| Permisos y concurrencia | Sin autoelevación, escritura sobre publicado fuera del flujo, sobrescritura silenciosa ni duplicación por reintento. |
| Inicio humano y continuidad por agente | Primer administrador, login, creación de usuarios, conexión MCP, renovación, revocación, segundo factor y recuperación probados en ambos perfiles. |
| Cambio de perfil | Sin pérdida de datos; bloques no editables visualmente identificados; rutas y enlaces coherentes. |
| Snapshot | Copia y restauración verificadas, credenciales excluidas y formatos incompatibles detectados. |
| SEO y rendimiento | Metadatos, canonical, idiomas, sitemap y publicación correctos; frontend sin assets del editor y sin regresión frente a la base medida. |
| Cliente MCP | Conexión y recorridos reales en los clientes acordados, con límites y errores comprensibles. |

La CI debe combinar pruebas unitarias de operaciones con pruebas funcionales de rutas y perfiles. Ejecutar la suite completa y convenciones antes de cada commit de implementación según las reglas actuales; añadir pruebas de navegador donde el comportamiento visual o la ausencia de editor lo requiera. Validar migraciones y concurrencia en MySQL además de las pruebas rápidas en SQLite.

No se fijan porcentajes de ahorro ni presupuestos de rendimiento inventados: establecer una línea base reproducible en fase 0 y acordar umbrales antes del piloto.

## 12. Riesgos y decisiones que evita este plan

| Riesgo | Tratamiento propuesto |
|---|---|
| Un flag que solo esconde pantallas | Hacer dependientes del perfil las rutas, assets, skills y verificaciones. |
| Multiplicar variantes difíciles de mantener | Dos perfiles sobre operaciones comunes; pruebas de transiciones. |
| Ampliar MCP copiando reglas del admin | Extracción vertical a operaciones compartidas antes de extender flujos. |
| Confundir HTML flexible con código sin límites | Renderer y estilos explícitos; componentes registrados para comportamiento. |
| Clases de CSS que no existen en producción | Vocabulario precompilado y validación del HTML propuesto. |
| Bloquearse al conectar el primer agente | Login, usuarios y credenciales permanecen en esencial; instalación inicial y recuperación verificadas. |
| Convertir esencial en otro panel enorme | Mantener el alcance por pantalla; edición especializada a través del agente. |
| Prometer una conversión automática a Live Editor | Declaración de compatibilidad por bloque y conversión explícita. |
| Confundir código confiable con HTML editorial | Permisos y renderizadores separados; scripts solo en Custom Code, nunca en campos saneados. |
| Snippet global que afecta todo el sitio | Activación versionada, reglas inspeccionables y apagado independiente del frontend. |
| Anunciar 1.0 porque terminaron las fases | Publicar solo al pasar los recorridos de aceptación y el piloto. |

## 13. Versionado y organización de entregas

- La base actual permanece en **0.47.0** durante esta planificación.
- Este roadmap vive separado del changelog: el changelog no debe registrar funcionalidades futuras como entregadas.
- Cada incremento implementado y confirmado se versiona según la skill `changelog-version`: cambios funcionales MINOR; correcciones y documentación PATCH según las reglas del repositorio.
- Las fases pueden requerir varios commits/versiones. No reservar números exactos antes de conocer el tamaño real de cada entrega.
- Cada entrega documenta resultado, validación, compatibilidad y reversión; `VERSION` y changelog se actualizan juntos cuando corresponda al commit.
- La versión **1.0.0** será el hito de estabilidad del conjunto, no una rama paralela ni un repositorio distinto.
- Antes de cualquier despliegue, el propietario debe poder revisar qué cambió y qué migración se ejecutará. La autorización actual cubre la implementación completa hasta 1.0 y sus pruebas; cada fase queda condicionada a aprobar el E2E de la anterior. Push y publicación remota siguen sin ejecutarse en el cierre de fase 0.

## 14. Decisiones propuestas para la revisión del propietario

1. **Repositorio único**, conservando Rüweb CMS como producto base.
2. **Dos perfiles: completo y esencial**, con completo por defecto. Se elimina el perfil exclusivo de agentes.
3. **Configuración de despliegue**, no toggle editorial accesible desde una clave de contenido.
4. **Páginas de un campo HTML y bloques saneados**, con variantes con layout o lienzo.
5. **Custom Code incluido en 1.0**, con selección/exclusión de páginas, permisos propios y recuperación. Los artifacts aislados se evaluarán por separado.
6. **Componentes interactivos registrados** para ampliar comportamiento sin construir controles administrativos.
7. **MCP completo en ambos perfiles**, con login, gestión de usuarios y conexión del agente disponibles desde el panel.
8. **Crear nuevas capacidades mediante código y migraciones**; MCP descubre y opera lo que el proyecto tiene registrado.
9. **1.0 condicionada a aceptación y piloto**, sin fecha ni número de versiones intermedias impuestos.

Las fases 0 y 1 están aceptadas y su implementación y evidencia están registradas en sus documentos de resultados. La fase 2 está aceptada con [evidencia](phase-2-results.md). La fase 3 está aceptada con [evidencia](phase-3-results.md). La fase 4 está aceptada con [evidencia](phase-4-results.md). La fase 5 está aceptada con [evidencia](phase-5-results.md). La fase 6 está aceptada con [evidencia](phase-6-results.md). La fase 7 está aceptada con [evidencia](phase-7-results.md). La fase 8 está aceptada con [evidencia](phase-8-results.md). La fase 9 está aceptada con [resultados y excepción explícita](phase-9-results.md); Claude queda pendiente de seguimiento y no certificado por aplazamiento autorizado del propietario. Las fases 10–11 no se iniciaron.


## 15. Cambios principales respecto a la primera versión del plan

1. Se elimina el perfil `agent`: solo quedan `full` y `essential`.
2. Se explicita que ambos perfiles tienen la misma superficie MCP; los permisos y módulos instalados determinan el acceso efectivo.
3. Se conserva el inicio humano: primer administrador en instalación, login, usuarios y conexión del agente desde el panel.
4. Se concreta la ubicación propuesta del perfil y cómo lo consultan aplicación, agentes, skills y verificadores.
5. Se incorporan páginas de un único campo HTML, con layout del sitio o lienzo sin header/footer.
6. Se incorpora Custom Code global o por destinos seleccionados, con exclusiones, idiomas y posiciones de inserción.
7. Se distingue HTML editorial saneado de código de navegador confiable; se actualiza el límite anterior que excluía todo JavaScript almacenado.
8. Custom Code tiene una fase propia y pruebas de permisos, renderizado, apagado y snapshots; en la revisión 3 corresponde a la fase 8.
9. Se mantienen la migración incremental, el perfil completo por defecto y la aprobación pendiente antes de implementar. La meta sigue siendo 1.0.0 tras cumplir aceptación y piloto.

## 16. Cambios de esta revisión: fases y E2E

- Se reorganiza la implementación en doce fases numeradas del 0 al 11, sin fases intermedias con letras.
- Se separan editorial, formularios e identidad para que cada entrega tenga un recorrido acotado.
- Cada fase incorpora un E2E identificable con preparación, pasos, resultado observable, criterio de aprobación y reversión.
- Se define un entorno aislado común y la evidencia mínima; las pruebas externas controladas se distinguen de los componentes reales.
- Se añaden comprobaciones de comportamiento entre superficies y fallos controlados, no solo respuestas exitosas.
- El cambio de contrato para agentes se prueba construyendo la misma sección en ambos perfiles.
- La publicación 1.0.0 queda condicionada a ejecutar los recorridos sobre el candidato exacto, además de actualización y piloto.
- Esta revisión modifica únicamente planificación; ninguno de estos nuevos E2E se ha implementado o ejecutado.

## 17. Revisión tras pruebas reales de MCP en Codex

- La fase 0 pasa de inventario a reparación de la base existente antes de ampliarla.
- Incorpora descubrimiento incompleto (22 herramientas registradas frente a 20 expuestas), edición parcial segura, protección de publicados, identidad multilingüe explícita, paginación y pruebas negativas de permisos/validación.
- Añade E2E-00-MCP con aprobación individual por caso y corrige la reversión para contemplar cambios de producto futuros.
- Mantiene en fases posteriores las nuevas capacidades: traducciones completas en fase 3, flujo editorial con versión pública y borrador en fase 5, e interoperabilidad ampliada en fase 9.
- En aquella revisión solo se actualizó este documento; posteriormente la implementación y los tests de fase 0 fueron autorizados y completados, y se autorizó la ejecución del plan completo.

## 18. Implementación de fase 0

Se implementaron las correcciones y se ejecutaron las regresiones funcionales y HTTP con MySQL aislado. Se añadió protección de secretos en ajustes al detectar su exposición durante la revisión. El [registro de resultados](phase-0-results.md) contiene inventario, evidencia, limitaciones y línea base. Una sesión nueva de Codex confirmó el descubrimiento de las 22 herramientas y ejecutó ambos listados reparados y la consulta de un menú descubierto. La fase 0 está aceptada; las fases posteriores se ejecutarán bajo autorización global, cada una con su gate E2E.
