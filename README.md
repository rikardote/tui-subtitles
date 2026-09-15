# Subtitle Processor — Web

Aplicación web para **detección, extracción y traducción automática de subtítulos** con Inteligencia Artificial (DeepSeek, Ollama, OpenAI) e integración con **Jellyfin**.

Diseñada para trabajar directamente sobre las carpetas multimedia del servidor: escanea la biblioteca, analiza las pistas de cada archivo, traduce los subtítulos que faltan y guarda el resultado junto al video (`Pelicula.es.srt`), sin modificar nunca el archivo original.

---

## Características

- **Escaneo automático** de la biblioteca (registra archivos nuevos y modificados)
- **Análisis con FFprobe** de cada video: pistas internas, subtítulos externos y detección de idioma
- **Traducción con IA** por lotes con contexto entre bloques (frases continuadas naturales)
- **Cola de trabajos** con worker en segundo plano y pre-extracción de subtítulos
- **Revisión manual**: los bloques que salen mal se marcan para corregirlos puntualmente con DeepSeek
- **Español neutro** (variante latinoamericana, sin formas peninsulares)
- **Integración con Jellyfin**: sincroniza el catálogo y traduce lo que falta en español
- **Historial** de tareas con progreso y errores
- **Nunca modifica el video original** y nunca sobrescribe subtítulos existentes

---

## 🚀 Despliegue con Docker (recomendado)

El contenedor incluye **PHP 8.3, FFmpeg, FFprobe y SQLite**, con acceso a tus discos multimedia y a los proveedores de IA.

```bash
# Clonar el repositorio
git clone git@github.com:rikardote/tui-subtitles.git
cd tui-subtitles

# Copiar la configuración de ejemplo y editarla
cp .env.example .env

# Iniciar
docker compose up -d --build
```

Abrir en el navegador: **http://localhost:8585**

### Carpetas multimedia

Edita las rutas en `.env` (deben existir en el host; se montan en el contenedor):

```env
MEDIA_PATH_MOVIES=/mnt/disk/media/movies
MEDIA_PATH_TV=/mnt/disk/media/tv
```

Ajusta también los volúmenes en `docker-compose.yml` si tus rutas son distintas.

---

## Proveedores de traducción

| Proveedor | Modelo | Requisitos | Coste aproximado |
|---|---|---|---|
| `deepseek` ⭐ | `deepseek-v4-flash` | API key | ~$0.012 / película |
| `ollama` | `qwen3.5:9b`, `gemma2:2b`… | [Ollama](https://ollama.com) (local/red) | Gratis |
| `openai` | `gpt-4o-mini`, Groq, OpenRouter… | API key | Variable |

```env
# DeepSeek (recomendado: barato y de alta calidad)
TRANSLATION_PROVIDER=deepseek
DEEPSEEK_API_KEY=sk-...
DEEPSEEK_BASE_URL=https://api.deepseek.com/v1
DEEPSEEK_MODEL=deepseek-v4-flash

# Ollama local (gratis, offline)
#TRANSLATION_PROVIDER=ollama
#OLLAMA_URL=http://localhost:11434
#OLLAMA_MODEL=qwen3.5:9b
```

El proveedor se puede cambiar también desde la interfaz: **⚙ Configuración → Proveedor**, y probar la conexión con el botón *Probar*.

---

## Uso de la interfaz

### Dashboard
Resumen de la biblioteca: total de archivos, cuántos ya tienen español, cuántos faltan, proveedor activo y tareas recientes.

### Explorar biblioteca
Dos vistas para navegar las carpetas configuradas:

- **Árbol**: bibliotecas → carpetas → archivos, con el estado de cada uno
- **Lista**: búsqueda y filtros (con/sin español), con paginación

Cada archivo muestra su estado:

| Indicador | Significado |
|---|---|
| 🟢 **Español** | Ya tiene subtítulos en español |
| 🟡 **N sin subtítulo** | Analizado y sin español → traducible |
| 🔵 **N por analizar** | Aún sin analizar (la app todavía no sabe qué pistas tiene) |
| ⚠ **N a revisar** | Tiene bloques deficientes pendientes de corrección |

### Traducir
- **Individual**: botón *Traducir* en el archivo (encola el trabajo)
- **Por carpeta**: botón *Encolar todos* (traduce los pendientes de esa carpeta)
- La pista se selecciona automáticamente: prefiere la **completa** (normal → SDH) y **nunca** una pista *forzada*
- El progreso se ve en tiempo real en la barra superior

### Revisión de bloques deficientes
Cuando la IA no logra traducir bien un bloque (queda en inglés, devuelve basura o gasta el razonamiento), el bloque se **marca automáticamente** y el archivo muestra un aviso ámbar.

En el detalle del archivo:
1. Pulsa **Ver bloques problemáticos** para inspeccionar qué falló (número, motivo y texto original)
2. Pulsa **Revisar con DeepSeek** para corregir solo esos bloques (usa DeepSeek de forma puntual, independiente del proveedor activo)

### Historial
Lista de tareas de traducción/extracción/revisión con estado, progreso y errores.

---

## Integración con Jellyfin

**No requiere ningún plugin.** La app guarda los subtítulos junto al video (`Pelicula.es.srt`) y Jellyfin los detecta automáticamente.

Además, la app puede usar el catálogo de Jellyfin como fuente para traducir en masa lo que falte:

```env
JELLYFIN_URL=http://host.docker.internal:8096   # desde dentro del contenedor
JELLYFIN_API_KEY=tu-api-key                      # Jellyfin → Dashboard → API Keys
JELLYFIN_CONTAINER_PREFIX=/data
JELLYFIN_PATH_MAP=/data/movies=/mnt/disk/media/movies,/data/tvshows=/mnt/disk/media/tv
```

> Si la app corre en Docker, usa `host.docker.internal` en lugar de `localhost` para alcanzar Jellyfin (el `docker-compose.yml` ya configura `extra_hosts`).

Desde la web: **⚙ Configuración → Sincronización con Jellyfin**.

---

## Escaneo automático

El contenedor ejecuta un escaneo **cada 15 minutos** que registra y **analiza** los archivos nuevos/modificados, de modo que el estado de subtítulos siempre está al día.

```env
SCAN_INTERVAL_MINUTES=15
```

También puedes lanzarlo manualmente desde la web (botón *Escanear*) o por consola:

```bash
docker exec subtitles-web php /app/bin/scan --analyze
```

---

## Arquitectura

```
public/index.php            Front controller + rutas de la API
resources/views/app.php     Interfaz web (Alpine.js + Tailwind)
app/Http/Controllers/       ApiController (endpoints REST)
app/Services/               Lógica de negocio
├── Library/                Rutas, descubrimiento, escaneo, detección de cambios
├── Media/                  Extracción de subtítulos, eliminación
├── Subtitle/               Análisis, parser SRT, idioma, validación, revisión
├── Queue/                  Cola de trabajos + worker en segundo plano
├── Jellyfin/               Cliente API, mapeo de rutas, sincronización
└── Translation/            Proveedores (interfaz) + traducción por lotes
app/Infrastructure/         FFprobe, FFmpeg, ProcessRunner
app/Models/                 MediaFile, SubtitleTrack, ProcessingTask
app/Storage/Database.php    SQLite (PDO) + migraciones
bin/worker                  Worker de la cola (segundo plano)
bin/scan                    Escaneo no interactivo (cron / entrypoint)
```

### Flujo de traducción

```
Web → Cola (SQLite) → Worker
                        │
                        ├─ 1. Extrae el subtítulo (FFmpeg)  ← se pre-extrae mientras
                        │                                      traduce el anterior
                        ├─ 2. Traduce por lotes con contexto
                        │     (marca los bloques que fallan)
                        ├─ 3. Valida el SRT
                        └─ 4. Guarda junto al video y registra en el historial
```

### Decisiones clave

- **El video original nunca se modifica.** Solo se crean archivos nuevos (`Pelicula.es.srt`).
- **Los timestamps se preservan**: la traducción trabaja por bloques y solo cambia el texto.
- **Traducción desacoplada**: `TranslationProviderInterface` — cambiar de proveedor es configuración, no código.
- **Pistas forzadas ignoradas**: si se selecciona una pista *forced* (solo frases especiales), se usa automáticamente la pista completa.
- **Detección de idioma en 3 niveles**: metadata → título → contenido.
- **Marcadores y basura protegidos**: los marcadores (`[Spanish]`, `-`) se conservan y las respuestas de error de la API se rechazan.
- **Anti-duplicados**: una transacción exclusiva evita encolar dos veces el mismo archivo.

---

## Solución de problemas

| Síntoma | Causa habitual |
|---|---|
| Una traducción tarda segundos y queda incompleta | Se seleccionó una pista *forzada* (la app ahora lo evita automáticamente) |
| "No se pudo conectar con Ollama" | El servidor Ollama está apagado o la URL/red no es alcanzable |
| Jellyfin no conecta desde el contenedor | Usa `host.docker.internal` en lugar de `localhost` |
| Una traducción no avanza | Reinicia el worker: `docker restart subtitles-web` (el progreso se conserva por checkpoint) |

---

## Scripts de mantenimiento

```bash
# Detectar subtítulos con bloques deficientes (crea los avisos)
php scripts/scan-review.php --apply

# Revisar/corregir los bloques marcados (usa DeepSeek puntualmente)
php scripts/review-all.php

# Reparar un SRT concreto desde el original en inglés
php scripts/repair-subtitles.php <srt_es> <srt_original_en>
```

---

## Hoja de ruta

- OCR para subtítulos de imagen (PGS/VobSub)
- Salida en formato ASS (conservando estilos avanzados)
- Plugin nativo de Jellyfin (acción "Traducir" dentro del reproductor)
- Notificaciones al finalizar traducciones largas
