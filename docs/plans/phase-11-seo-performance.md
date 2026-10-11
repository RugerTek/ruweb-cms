# Fase 11 — SEO y rendimiento público comparable

Resultado: **SEO y criterios técnicos adoptados para esta validación pasan** en candidato `full` y
`essential`, comparados con el artefacto real 0.47.0. No son presupuestos acordados anteriormente ni una
certificación de Core Web Vitals de producción. No se declara ausencia absoluta de incremento de bytes:
el candidato suma 2.193 bytes por documento de la fixture (aproximadamente 1,53%).

## Identidad y método

Baseline `f337e7d7b2eba14c9e6bcb2b1cb79a1978bb79a5` (VERSION 0.47.0); candidato
`2b65a272f25470d89b0edbd70f7e6b0a8820d41e` (0.54.2). El runner extrae el artefacto antiguo, instala
realmente `cms:install` en un esquema MySQL ausente y crea una página publicada ES con metadatos,
traducción EN, media social por ID, un draft y una publicación futura. Actualiza esa misma copia/esquema
con las cinco migraciones aditivas del candidato; los manifiestos de contenido, asociaciones, permisos,
URLs y hashes de archivos públicos quedan idénticos. Después cambia únicamente el perfil a essential.

Las tres etapas ejecutan `npm run build`, limpian vistas, cachean configuración/rutas y reinician servidor.
No hay Vite dev server, sesión de admin ni snippet activo. PHP 8.5.4, Node 24.13.1 y Chromium 151.0.7922.34;
se conservan [contexto](phase-11-evidence/seo-performance/context.json) y fingerprints completos de
[baseline](phase-11-evidence/seo-performance/baseline-047-full-fingerprints.json),
[full](phase-11-evidence/seo-performance/candidate-full-fingerprints.json) y
[essential](phase-11-evidence/seo-performance/candidate-essential-fingerprints.json).
La validación final añadió después un guard de argumentos al dispatcher MCP (`ToolArguments` y `McpServerController`). Sus pruebas afectadas se repiten en los E2E acumulados y el piloto; no cambia las rutas públicas, plantillas ni assets de esta comparación. Los fingerprints finales distinguen esa corrección de la medición aquí registrada.

Los lockfiles de dependencias son idénticos; vendor/node_modules comparten las dependencias instaladas.
Esto permite comparar source/build, no certifica reinstalar dependencias desde un registro externo.

Chromium hace siete navegaciones por URL y viewport, cada una con contexto/caché nuevos; el servidor y
plantillas ya están calientes. ES/EN, 1440×1000 y 390×844: 28 navegaciones por etapa, 84 en total. Sin
throttling de red/CPU; loopback 8030, PHP CLI y MySQL locales, ejecución secuencial. Se bloquea tráfico
externo (ningún origen externo fue solicitado). Se registran bytes **sin comprimir** del cuerpo de cada
respuesta y todas las muestras de TTFB, FCP, LCP, carga, DOMContentLoaded y CLS. No se confunden estos
bytes con transferencia gzip/Brotli ni estas medianas con latencia de una CDN o usuario real.

## Resultado SEO y navegador

Las tres etapas verifican valores almacenados y DOM: título con sufijo global, description, título OG,
canonical exacto, lang, alternates ES/EN y x-default, H1 SEO y heading de sección. Ambas URLs aparecen
en sitemap; draft y futuro responden 404 y están ausentes del XML. La imagen OG local devuelve 200 y
bytes no vacíos. La fixture textual no tiene imágenes inline; no se atribuye a esta medición un LCP de
hero, variantes responsive ni carga de medios dentro del cuerpo.

En escritorio/móvil no hay overflow horizontal ni errores JavaScript. Se inspeccionaron las capturas
móviles [baseline](phase-11-evidence/seo-performance/baseline-047-full-es-390.png) y
[essential](phase-11-evidence/seo-performance/candidate-essential-es-390.png): disposición preservada.
Cada documento solicita cuatro recursos: HTML, CSS público, JS público y su chunk compartido.
No solicita admin, editor-engine/editor-ui, TinyMCE ni CodeMirror. Full y essential sirven los mismos
bytes públicos para el mismo contenido; el perfil no introduce una carga editorial pública.

## Mediciones

Medianas en milisegundos, siete muestras por celda; CLS fue 0 en **todas** las muestras.

| Etapa | URL/viewport | Bytes sin comprimir | Solicitudes | TTFB | FCP | LCP |
|---|---|---:|---:|---:|---:|---:|
| 0.47 full | ES / 1440 | 143.516 | 4 | 22,8 | 48 | 56 |
| Candidato full | ES / 1440 | 145.709 | 4 | 21,6 | 44 | 52 |
| Candidato essential | ES / 1440 | 145.709 | 4 | 24,3 | 52 | 56 |
| 0.47 full | EN / 1440 | 143.008 | 4 | 25,6 | 60 | 60 |
| Candidato full | EN / 1440 | 145.201 | 4 | 23,6 | 48 | 52 |
| Candidato essential | EN / 1440 | 145.201 | 4 | 21,2 | 52 | 52 |
| 0.47 full | ES / 390 | 143.516 | 4 | 25,0 | 52 | 56 |
| Candidato full | ES / 390 | 145.709 | 4 | 17,4 | 48 | 48 |
| Candidato essential | ES / 390 | 145.709 | 4 | 19,2 | 44 | 48 |
| 0.47 full | EN / 390 | 143.008 | 4 | 20,2 | 52 | 52 |
| Candidato full | EN / 390 | 145.201 | 4 | 17,8 | 48 | 48 |
| Candidato essential | EN / 390 | 145.201 | 4 | 19,0 | 44 | 48 |

El incremento se descompone en CSS +1.874 bytes, JS público +280, HTML +39 y chunk compartido +0.
El source añade el vocabulario precompilado `composition.css` y `initComposition` al frontend público;
no hay incremento de requests ni dependencia del editor. Los timings no muestran una regresión
significativa en esta fixture: la diferencia positiva máxima de mediana es +1,5 ms TTFB y +4 ms FCP;
LCP nunca aumenta. La corrida única no establece una mejora estadística del producto.

## Criterios adoptados para esta validación

Se adoptaron al revisar la línea base, con la autorización de ejecución autónoma del gate; no se presentan
como umbrales históricos pactados. El [comparador durable](../../tests/E2E/phase-eleven-seo-compare.py)
y [resultado](phase-11-evidence/seo-performance/comparison.json) los aplican a las ocho comparaciones:

- Semántica SEO/publicación exacta, sin errores/overflow ni recursos del editor; ninguna tolerancia.
- Solicitudes no superiores a la base (cuatro). Total y assets CSS/JS admiten hasta **4 KiB adicionales**
  sin comprimir por fixture: margen fijo pequeño para el nuevo vocabulario/runtime público, con inspección
  individual obligatoria; no permite incorporar un bundle editorial ni crecer proporcionalmente sin límite.
- Medianas TTFB/FCP/LCP ≤ baseline + `max(25 ms, 50% de baseline)`: tolerancia de laboratorio para
  planificación local y granularidad de frames, conservando siete muestras crudas. Este umbral no es un
  objetivo de usuario final ni autoriza una regresión sustancial en páginas reales.
- CLS mediano ≤ 0,01; todas las muestras observadas son exactamente cero.

Todas las comparaciones pasan. El alcance no incluye páginas con imágenes grandes, formularios activos,
terceros, tracking, snippets de cliente, compresión, tráfico concurrente, caché CDN, hosting ni datos de campo.
La matriz completa de fase 11 debe combinar esto con su E2E funcional de landing/media/formulario.

## Reproducción y retención

Desde el checkout candidato, con MySQL, dependencias y Playwright disponibles:

```sh
python3 tests/E2E/phase-eleven-seo-run.py
python3 tests/E2E/phase-eleven-seo-run.py cleanup
python3 tests/E2E/phase-eleven-seo-compare.py
```

El runner rechaza state previo, esquema existente, lockfiles distintos y puerto ocupado. Reutiliza control,
router y guard de fase 10 sólo dentro de la copia temporal, adaptando el guard a la raíz `ruweb-phase11-seo-`
y puertos 8030–8033 (usa 8030). Nunca arranca el source ni la instalación de localhost:8000. Lee únicamente
cuatro credenciales MySQL de `.env` para crear/eliminar el esquema desechable; no imprime valores.
Tras un fallo, ejecutar cleanup antes de repetir. La comparación offline puede repetirse con los JSON.

[Cleanup](phase-11-evidence/seo-performance/cleanup.json) confirma esquema eliminado, proceso detenido,
clave/configuración/logs privados y copia temporal destruidos, `.env` original sin cambios. No se crearon
snapshots ni dumps en este laboratorio. Se conservan sólo resultados, capturas y hashes sin credenciales.
Dos primeras tentativas corrigieron expectativas del harness (sufijo global del título y heading H2);
ambas se limpiaron antes de la corrida completa, sin modificar comportamiento del producto.
