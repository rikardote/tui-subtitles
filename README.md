# Subtitle Processor (Web UI & TUI)

Aplicación moderna para **detección, extracción y traducción automática de subtítulos** con Inteligencia Artificial (Ollama, DeepSeek, Meta Muse, OpenAI, Google Translate) e integración con **Jellyfin**.

---

## 🚀 Despliegue con Docker (Recomendado para Web)

El contenedor incluye **PHP 8.3, FFmpeg, FFprobe y SQLite**, con acceso a tus discos multimedia y a tu servidor de Ollama / APIs de IA.

```bash
# Iniciar contenedor Web en segundo plano
docker compose up -d

# Abrir en el navegador:
http://localhost:8585
```

---

## 💻 Ejecución Nativa (Sin Docker)

```bash
# Iniciar Servidor Web
./bin/serve           # Disponible en http://localhost:8585

# O usar la interfaz de consola TUI
./bin/subtitles        # TUI interactiva en terminal
./bin/jellyfin-sync    # Sincronización automática de Jellyfin
./bin/scan --analyze   # Escaneo + análisis FFprobe
```

### TUI

```
¿Qué desea hacer?
❯ Explorar biblioteca
  Escanear biblioteca
  Ver archivos pendientes
  Ver historial
  Configuración
  Salir
```

- **Explorar**: navega solo dentro de las rutas autorizadas; selecciona un
  video y la app lo analiza con FFprobe (pistas internas + subtítulos
  externos). Permite **extraer** o **traducir al español** cada pista.
- **Escanear**: escaneo manual de una biblioteca o todas.
- **Pendientes**: archivos registrados que aún no se han analizado.
- **Historial**: tareas de procesamiento (extracción/traducción) con errores.

## Configuración

**Carpetas multimedia** → `.env` (o `config/app.php` → `media_paths`):

```env
MEDIA_PATH_MOVIES=/media/Movies
MEDIA_PATH_TV=/media/TV
```

**Modelo de IA de traducción** → `TRANSLATION_PROVIDER` en `.env`,
o desde la TUI: *Configuración → Cambiar proveedor/modelo*:

| Proveedor | Modelo | Requisitos | Coste |
|---|---|---|---|
| `deepseek` | `deepseek-chat` | API key de DeepSeek | ~$0.045/película |
| `meta-muse` | `muse-spark-1.2` | API key de Meta Model API | **~$0.01/película** (Contributor) |
| `deep-translator` | Google Translate (automático) | pip install deep-translator | Gratis |
| `ollama` | `gemma2:2b`, `qwen2.5`… | [Ollama](https://ollama.com) + modelo descargado | Gratis, offline |
| `openai` | `gpt-4o-mini`, o cualquier API compatible (Groq, OpenRouter…) | API key | De pago / freemium |

```env
TRANSLATION_PROVIDER=meta-muse
META_MUSE_API_KEY=LLM_...
META_MUSE_BASE_URL=https://api.ai.meta.com/v1
META_MUSE_MODEL=muse-spark-1.2

# o bien:
TRANSLATION_PROVIDER=deepseek
DEEPSEEK_API_KEY=sk-...
DEEPSEEK_MODEL=deepseek-chat

# o bien:
TRANSLATION_PROVIDER=ollama
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=gemma2:2b
```

Para instalar un modelo local con Ollama:
```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama pull qwen2.5:7b
```

Resto de variables:
```env
FFMPEG_BIN=/usr/bin/ffmpeg
FFPROBE_BIN=/usr/bin/ffprobe
SCAN_INTERVAL_MINUTES=15
```

## Arquitectura

```
bin/subtitles              Punto de entrada TUI
app/Tui/Application.php    Menús y flujos (solo interacción)
app/Services/              Lógica de negocio (reutilizable en web)
├── Library/               Rutas, descubrimiento, escaneo, diff
├── Media/                 Extracción de subtítulos (FFmpeg)
├── Subtitle/              Análisis, parser, idioma, validación, nombres
├── Jellyfin/              Cliente API REST, mapeo rutas contenedor→host,
│                          sincronización del catálogo
└── Translation/           Proveedor (interfaz), traducción por bloques
app/Infrastructure/        FFprobe, FFmpeg, ProcessRunner
app/Models/                MediaFile, SubtitleTrack, ProcessingTask
app/Storage/Database.php   SQLite (PDO) + migraciones
```

### Decisiones clave

- **Nunca se modifica el video original.** Solo se crean archivos nuevos
  junto al video: `Pelicula.es.srt`.
- **Los timestamps se preservan**: la traducción trabaja por bloques y solo
  cambia el texto.
- **Traducción desacoplada**: `TranslationProviderInterface`; el proveedor
  inicial es `deep-translator` (gratuito). Se pueden añadir OpenAI/DeepL
  implementando la interfaz.
- **Detección de idioma en 3 niveles**: metadata → título → contenido.
- **Protección de archivos en copia**: el diff ignora variaciones de tamaño/mtime
  (< 2 s) y el escaneo automático es incremental.
- **Preparado para Jobs/Queue**: los servicios son independientes de la TUI;
  `bin/scan` ya es no interactivo para cron.

## Integración con Jellyfin (Docker) ⭐

**No se necesita ningún plugin en Jellyfin.** La app genera `Pelicula.es.srt`
junto al video; Jellyfin detecta automáticamente los subtítulos externos
con esa convención y los ofrece en el reproductor.

La app corre en el **host** y lee el catálogo de Jellyfin (contenedor) vía
API REST. Las rutas del contenedor (`/data/movies/...`) se traducen a rutas
del host (`/mnt/disk2tb/data/media/movies/...`) con un mapa configurable.

### Configuración

```env
JELLYFIN_URL=http://localhost:8096
# API key: Dashboard de Jellyfin → Advanced → API Keys
JELLYFIN_API_KEY=tu-api-key
JELLYFIN_CONTAINER_PREFIX=/data
# Mapa contenedor→host (si vacío se deduce por basename de media_paths)
JELLYFIN_PATH_MAP=/data/movies=/mnt/disk2tb/data/media/movies,/data/tvshows=/mnt/disk2tb/data/media/tv
```

### Uso

```bash
./bin/jellyfin-sync --check          # verifica conexión y mapa de rutas
./bin/jellyfin-sync --dry-run        # muestra qué se traduciría (no traduce)
./bin/jellyfin-sync --limit=10       # solo 10 ítems (pruebas)
./bin/jellyfin-sync --type=Movie     # solo películas
./bin/jellyfin-sync --type=Episode   # solo episodios
./bin/jellyfin-sync                  # traduce todo lo pendiente
```

Cron sugerido (una vez al día):

```bash
0 3 * * * /home/usuario/subtitles/bin/jellyfin-sync >> /home/usuario/subtitles/storage/logs/jellyfin-sync.log 2>&1
```

El sync por ítem: registra el archivo, lo analiza con FFprobe si falta,
comprueba si ya tiene español (lo omite), elige la mejor pista fuente
(prefiere inglés en texto, interna o externa) y la extrae y traduce con
el proveedor configurado (`TRANSLATION_PROVIDER`).

## Scheduler (escaneo automático)

```bash
crontab -e
# cada 15 minutos (ajustar ruta)
*/15 * * * * /home/usuario/subtitles/bin/scan --analyze >> /home/usuario/subtitles/storage/logs/scan.log 2>&1
```

## Pruebas

```bash
php scripts/smoke-test.php   # escaneo, análisis, idioma, parser, validador
php scripts/tui-test.php     # TUI con teclas simuladas
```

## Hoja de ruta (fuera de la PoC)

- Interfaz web (Livewire) reutilizando los servicios.
- OCR para subtítulos de imagen (PGS/VobSub).
- Proveedores de traducción adicionales (OpenAI, DeepL, LLM local).
- Procesamiento masivo con confirmación y colas.
- Plugin nativo de Jellyfin en C# (requiere .NET SDK; hoy la integración
  por API + subtítulos externos cubre el caso de uso sin compilar nada).
