# Subtitle Processor — PoC TUI

Aplicación TUI para **extracción y traducción de subtítulos** de archivos de video.
PoC de terminal; la lógica de negocio está desacoplada en `app/Services` para
reutilizarse después en una interfaz web.

```
Terminal
   ↓
bin/subtitles  (TUI interactiva)
   ↓
Services  (Scanner, Analyzer, Extractor, Translator)
   ↓
FFprobe / FFmpeg / deep-translator  +  SQLite
```

## Requisitos

| Herramienta | Versión | Uso |
|---|---|---|
| PHP | 8.3+ (cli, pdo_sqlite, mbstring) | Aplicación |
| Composer | 2.x | Dependencias |
| FFmpeg + FFprobe | 4.x+ | Análisis y extracción |
| deep-translator | (pip, opcional) | Traducción gratuita vía Google |

Instalación automática (Debian/Ubuntu):

```bash
./scripts/setup.sh
```

## Instalación

```bash
composer install
cp .env.example .env   # ajustar rutas multimedia y binarios
./scripts/make-test-library.sh   # (opcional) genera videos de prueba
```

## Uso

```bash
./bin/subtitles        # TUI interactiva
./bin/scan             # escaneo automático (cron) — registra archivos
./bin/scan --analyze   # escaneo + análisis FFprobe de nuevos/modificados
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

`config/app.php` y `.env`:

```env
MEDIA_PATH_MOVIES=/media/Movies
MEDIA_PATH_TV=/media/TV
FFMPEG_BIN=/usr/bin/ffmpeg
FFPROBE_BIN=/usr/bin/ffprobe
DEEP_TRANSLATOR_BIN=/home/usuario/.local/bin/deep-translator
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
