# Fase 8 — Custom Code

Custom Code está implementado y aceptado en ambos perfiles para la entrega 0.54.0. El coordinador y la
revisión independiente aprobaron el gate, incluidos los fingerprints finales y las capturas móvil/iframe
actualizadas, sin hallazgos pendientes. El commit es local; no se empezó la fase 9 ni se hizo push.

## Contrato entregado

- Versiones completas e inmutables para HTML/CSS/JavaScript, nombre/descripción, posición y orden. Guardar
  genera una propuesta; activar identifica la versión exacta y verifica su payload/fingerprint. Historial,
  comparación, autoría y auditoría permanecen disponibles en el panel.
- Operaciones compartidas entre panel, REST y los siete tools `custom_code_*`; catálogo idéntico en full y
  essential. Revisiones, locks de usuario/snippet y recibos durables rechazan bases obsoletas y reintentos
  con otros argumentos. Los fingerprints son canónicos: el orden de claves del JSON de MySQL no los altera.
- Permiso administrativo `users.can_manage_custom_code`, inicialmente falso, concedido por Usuarios. Las
  credenciales requieren además el scope específico `custom-code`. Full/editorial existente no gana código.
  OAuth exige consentimiento/contraseña/2FA y usa los mismos parámetros validados para consentimiento y emisión;
  rechaza discrepancias entre query y body en los siete parámetros de autorización.
- Destinos estables Page, Post y colecciones públicas que optan al registro; índice `blog` con identidad
  propia. La homepage usa su Page; welcome de instalación sin contenido no anuncia un índice ficticio.
  Traducción exacta por defecto y grupo sólo explícito; exclusión prevalece. Referencia eliminada se diagnostica;
  una exclusión desaparecida falla cerrada. Evaluación compartida y respuestas paginadas sin código ejecutable,
  con hash/tamaño y recuperación completa mediante get de la versión exacta.
- Una evaluación por documento sirve las tres inserciones del layout público y canvas. Login, OAuth,
  backoffice, APIs, errores y previews ordinarias carecen de destino. La prueba ejecutable exige permiso,
  versión/revisión/destino y reconocimiento explícitos, dentro de un documento separado de origen opaco;
  padre administrativo y sesión permanecen inaccesibles. No se promete que cualquier JavaScript sea seguro.
- Desactivación individual y apagado por configuración/CLI; marcador operativo independiente de DB/frontend
  y de configuración cacheada. Google Tag/Meta Pixel/Search Console conservan sus ajustes dedicados; firmas
  conocidas generan diagnóstico de posible duplicación, sin inventar deduplicación de scripts.

Contrato operativo y recuperación: [custom-code.md](../operations/custom-code.md). Descubrimiento, rutas
exactas, límites e idempotencia: [mcp.md](../operations/mcp.md). AGENTS, la skill create-config y el mapa de
docs enlazan el flujo. No hay una nueva fuente duplicada de ajustes/integraciones.

## Verificación

El E2E se ejecutó con MySQL real, cuatro trabajadores HTTP independientes y Chromium, en dos copias
independientes con esquemas desechables `ruweb_phase0_*`, storage/caches propios y producción Vite compilada.
Nunca se usó localhost:8000 ni se modificó .env, DB o cuentas reales. Todos los tests externos quedaron
bloqueados: sólo se observaron intentos del Google Fonts administrativo existente, registrados como assets
bloqueados. No se instaló tracking ni se envió correo externo.

Cada perfil cubrió tres páginas y sus traducciones (default, HTML y canvas), CSS guardado/activado desde el
panel y leído byte por byte con MCP, cuatro scripts locales con orden head/body-start/body-end, inclusión
exacta inglesa, exclusión global de una página, ejecución única y borrador pendiente inerte. Se recorrieron
108 documentos públicos por perfil (seis destinos × dos anchos × nueve estados), además de login, OAuth,
preview ordinaria y admin. El panel y MCP devolvieron exactamente la misma evaluación.

Ocho requests simultáneos para guardar y otros ocho para activar devolvieron resultados idénticos con una
sola versión/evento por operación. Se rechazaron sin mutación full, editor, draft, read y un administrador
con scope custom-code pero sin la concesión de cuenta. Se comprobaron desactivar/reactivar, apagar/reabrir,
romper deliberadamente el header de una página de prueba y recuperar mediante CLI. El mismo CLI pasó también
con un puerto DB inaccesible. La preview explícita ejecutó el head local en su iframe, comprobó el rechazo
al acceso a `parent.document`, mantuvo el padre sin marcador y no introdujo overflow en 1440/390.

Las capturas nuevas del panel usan la compilación final, fieldsets acotados con scroll y sidebar cerrado en
móvil. Una primera inspección detectó que faltaba compilar las nuevas clases Blade/Tailwind y que un hash
largo desbordaba el diagnóstico: ambas condiciones se corrigieron antes del recorrido final congelado.
La revisión independiente detectó y se corrigieron el conflicto OAuth query/body, evaluación no acotada y
lookup de previews históricas más allá de las primeras veinte versiones; sus reproducciones y regresiones pasan.

- `composer test`: **525 tests, 4013 assertions**, aprobados.
- Regresión específica de snapshots: **33 tests, 171 assertions**, aprobados.
- JavaScript: **17 tests**, aprobados. Build Vite aprobado; conserva la advertencia existente del chunk TinyMCE.
- Convenciones y `git diff --check`: aprobados.
- E2E-08 completo full/essential, security HTTP y CLI sin DB: aprobados.
- E2E-00/01 + trabajador de cola real: regresión aprobada en full con el candidato compilado.

Resultados sanitizados, capturas representativas 1440/390 y SHA-256 del source/harness/build ejecutado:
[full](phase-8-evidence/full/source-build-sha256.json),
[essential](phase-8-evidence/essential/source-build-sha256.json).
La única diferencia de configuración entre perfiles es `cms.admin_profile=essential`; el manifiesto de
verificación registra ese override y compara el resto con la fuente congelada. No se conserva .env, secretos,
manifest de credenciales, URLs de preview, cookies, trazas privadas ni logs de servidor/mail en evidencia.

## Salvaguarda de snapshots y límite de fase 10

El exportador existente descubre automáticamente tablas nuevas; dejarlo sin guard permitiría restaurar
código activo y permisos. Esta fase agrega el guard mínimo: `custom_code_receipts` no se exporta ni importa,
los recibos locales se limpian en restore, el permiso de usuario no viaja y **todos** los snippets quedan
inactivos sin active_version_id, incluso cuando una copia legacy no contiene tablas Custom Code. Se conserva
código/versiones y se registra `restore_inactive` en su auditoría específica, sin agregar markers al ledger.
El orden de tablas asegura parent→versions→events también con FK activas. Las pruebas usan restore real de
archivo actual y archivo legacy con manifest/checksums válidos.

Inventario: `custom_code_snippets`, `custom_code_versions`, `custom_code_events`, `custom_code_receipts`;
`users.can_manage_custom_code`; `scope` en códigos/tokens OAuth. El marcador local de apagado es estado
operativo, no contenido portable. La fase 10 conserva su gate completo de migración/import validado,
compatibilidad entre instalaciones, traslado de medios y piloto de snapshot; no se declara aprobado aquí.

## Limpieza y reversión

Se detuvieron todos los servidores/workers de fixture; se revocaron las credenciales y eliminaron ambos
esquemas mediante el cleanup con guard. Las copias, .env privados, caches, marcadores y logs de pruebas se
eliminaron después de guardar evidencia no secreta. El checkout real conserva sólo código/docs/evidencia.
En una instalación desplegada, `cms:custom-code disable` recupera el frontend conservando versiones; retirar
la concesión o revocar el credential impide nuevas operaciones. Antes de servir los nuevos hooks hay que migrar.
