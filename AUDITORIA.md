# Auditoría de código — tui-subtitles

> **Fecha:** 2026-09-15  
> **Scope:** Backend PHP (SQLite, sin framework)  
> **Archivos revisados:** `ApiController`, `MediaFile`, `SubtitleTrack`, `ProcessingTask`, `QueueService`, `TaskWorker`, `Router`, `Database`, `Container`, `JellyfinSyncService`, `TranslationBatchService`, `MediaChangeDetectorService`, `MediaScannerService`

---

## Hallazgos

### 🔴 Críticos

---

#### 1. Inyección SQL en `dashboard()` — `ApiController.php`

Se usaba un `prepare/execute` cuyo resultado era ignorado, y luego se construía una segunda query con interpolación directa de `$path`:

```php
// VULNERABLE
$count = (int) Database::pdo()->prepare("SELECT COUNT(*) FROM media_files WHERE path LIKE ?")
    ->execute([$path . '%'])   // ← devuelve bool, no el resultado
    ? Database::pdo()->query("... WHERE path LIKE '{$path}%'")  // ← interpolación directa
    ->fetchColumn() : 0;
```

Doble bug: el resultado del `prepare/execute` se descarta, y la segunda query interpola `$path` crudo.

---

#### 2. N+1 masivo en `mediaList()` — `ApiController.php`

Por cada fila ya cargada del `SELECT *` inicial se relanzaba un `findById` para rehidratar el mismo objeto, y luego tres llamadas implícitas a `tracks()` desde `hasSpanish()`, `englishTracks()` y `reviewPendingCount()`.

Con 100 resultados por página: **~500 queries por request**.

```php
// POR CADA FILA:
$media = MediaFile::findById((int) $row['id']); // ← query redundante
$tracks = $media->tracks();                      // ← query a subtitle_tracks
$hasSpanish = $media->hasSpanish();              // ← tracks() internamente → otra query
count($media->englishTracks());                  // ← tracks() → otra query
$this->reviewPendingCount($media);               // ← tracks() → otra query
```

---

#### 3. N+1 masivo en `tree()` — `ApiController.php`

Igual que `mediaList()` pero sin paginación: sobre **todos los archivos de la biblioteca**. Además `reviewPendingCount()` se llamaba **dos veces por archivo** (línea 983 y línea 1016).

Con 1000 archivos: **~5000+ queries + 2000 lecturas de disco innecesarias**.

---

#### 4. N+1 en `tasksList()` — `ApiController.php`

```php
$tasks = ProcessingTask::recent(50);
array_map(function (ProcessingTask $t) {
    $media = MediaFile::findById($t->mediaFileId); // ← 50 queries extra
}, $tasks);
```

---

#### 5. N+1 en `queueStatus()` — `ApiController.php`

```php
array_map(function (ProcessingTask $t) {
    $media = MediaFile::findById($t->mediaFileId); // ← N queries
}, $queue->pendingList()),
```

---

#### 6. N+1 en `dashboard()` tareas recientes — `ApiController.php`

```php
array_map(function (ProcessingTask $t) {
    $media = MediaFile::findById($t->mediaFileId); // ← 5 queries extra
}, ProcessingTask::recent(5));
```

---

### 🟡 Moderados

---

#### 7. Double read de `.review.json` en `mediaDetail()` — `ApiController.php`

El mismo archivo se leía dos veces con `@file_get_contents` por cada pista generada:

```php
'review_pending'  => count(@json_decode(@file_get_contents($t->path . '.review.json'), true))
'review_problems' => @json_decode(@file_get_contents($t->path . '.review.json'), true)
```

---

#### 8. Dead code en `QueueService::enqueueBatch()` — `QueueService.php`

`$englishTracks` se asignaba pero nunca se usaba, y además disparaba una query extra porque `bestEnglishTextTrack()` ya llama `englishTracks()` internamente:

```php
$englishTracks = $media->englishTracks(); // ← resultado nunca usado + query extra
$track         = $media->bestEnglishTextTrack();
```

---

#### 9. Query extra innecesaria en `ProcessingTask::save()` — `ProcessingTask.php`

Antes de cada `UPDATE`, se verificaba la existencia de la pista con un `SELECT`:

```php
if ($this->subtitleTrackId !== null && SubtitleTrack::findById($this->subtitleTrackId) === null) {
    $this->subtitleTrackId = null;
}
```

La FK `ON DELETE SET NULL` ya garantiza esta integridad a nivel de base de datos.

---

#### 10. Conteos redundantes en `dashboard()` — `ApiController.php`

Dos `COUNT(*)` separados para obtener `pending` y `analyzed` cuando un único `GROUP BY status` devuelve ambos:

```php
$pendingFiles  = Database::pdo()->query("SELECT COUNT(*) ... WHERE status = 'pending'")->fetchColumn();
$analyzedFiles = Database::pdo()->query("SELECT COUNT(*) ... WHERE status != 'pending'")->fetchColumn();
```

---

#### 11. Detección de cancelación por texto — `TaskWorker.php` *(pendiente)*

```php
$isCancelled = str_contains($e->getMessage(), 'cancelada');
```

Cualquier excepción con esa palabra en el mensaje se marcará como cancelación en vez de error. Requiere excepción tipada `TaskCancelledException`.

---

#### 12. `writeEnv()` no escapa valores con espacios — `ApiController.php` *(pendiente)*

```php
$line = $key . '=' . $value; // ← valores con espacios corrompen el .env
```

---

### 🟢 Code Smells

| # | Descripción | Ubicación |
|---|---|---|
| 13 | UUID generado duplicado en 3 clases con el mismo código | `MediaFile`, `ProcessingTask`, `MediaChangeDetectorService` |
| 14 | `Database::migrate()` se ejecuta en cada boot (overhead en cada request) | `Database.php` |
| 15 | `MediaFile::all()` carga todos los registros en RAM sin límite | `MediaChangeDetectorService.php` |
| 16 | `@` silencer en `file_get_contents`, `json_decode`, `scandir`, `unlink` | Varios |
| 17 | Timestamps mezclados: `gmdate` vs `date()` — falta normalizar a UTC | `MediaFile`, `TaskWorker`, `Database` |
| 18 | CORS `Access-Control-Allow-Origin: *` sin autenticación | `Router.php` |
| 19 | Router instancia controladores con `new $class()` sin DI — dificulta testing | `Router.php` |
| 20 | `scanLibraries()` analiza archivos con FFprobe de forma síncrona dentro del request HTTP | `ApiController.php` |

---

## Fixes aplicados

### Fix 1 — Inyección SQL en `dashboard()` ✅

**Archivo:** `app/Http/Controllers/ApiController.php`

Se eliminó la doble query y se usa un único prepared statement correcto:

```php
// ANTES — vulnerable y con bug lógico
$count = (int) Database::pdo()->prepare("SELECT COUNT(*) FROM media_files WHERE path LIKE ?")
    ->execute([$path . '%'])
    ? Database::pdo()->query("SELECT COUNT(*) FROM media_files WHERE path LIKE '{$path}%'")
    ->fetchColumn() : 0;

// DESPUÉS — seguro
$stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM media_files WHERE path LIKE ?");
$stmt->execute([$path . '%']);
$count = (int) $stmt->fetchColumn();
```

---

### Fix 2 — Conteos de dashboard consolidados ✅

**Archivo:** `app/Http/Controllers/ApiController.php`

```php
// ANTES — 2 queries
$pendingFiles  = Database::pdo()->query("SELECT COUNT(*) ... WHERE status = 'pending'")->fetchColumn();
$analyzedFiles = Database::pdo()->query("SELECT COUNT(*) ... WHERE status != 'pending'")->fetchColumn();

// DESPUÉS — 1 query con GROUP BY
$statusStmt = Database::pdo()->query("SELECT status, COUNT(*) as cnt FROM media_files GROUP BY status");
$statusCounts = [];
foreach ($statusStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
}
$pendingFiles  = $statusCounts[MediaFile::STATUS_PENDING] ?? 0;
$analyzedFiles = $totalFiles - $pendingFiles;
```

---

### Fix 3 — N+1 en tareas recientes del `dashboard()` ✅

**Archivo:** `app/Http/Controllers/ApiController.php`

```php
// ANTES — 5 queries findById
$recentTasks = array_map(function (ProcessingTask $t) {
    $media = MediaFile::findById($t->mediaFileId);
    ...
}, ProcessingTask::recent(5));

// DESPUÉS — 1 sola query con findByIds()
$recentTasksList = ProcessingTask::recent(5);
$recentMediaMap  = MediaFile::findByIds(
    array_unique(array_map(fn (ProcessingTask $t) => $t->mediaFileId, $recentTasksList))
);
$recentTasks = array_map(function (ProcessingTask $t) use ($recentMediaMap) {
    $media = $recentMediaMap[$t->mediaFileId] ?? null;
    ...
}, $recentTasksList);
```

---

### Fix 4 — N+1 masivo en `mediaList()` ✅

**Archivos:** `ApiController.php`, `MediaFile.php`, `SubtitleTrack.php`

**4a.** `MediaFile::fromRow()` pasó de `private` a `public`:

```php
// MediaFile.php
public static function fromRow(array $row): self { ... }
```

**4b.** Cache lazy de tracks en `MediaFile` con el operador `??=`:

```php
// MediaFile.php
private ?array $tracksCache = null;

public function tracks(): array
{
    // Primera llamada → va a BD. Siguientes → array en memoria.
    return $this->tracksCache ??= SubtitleTrack::forMediaFile($this->id);
}

public function setTracksCache(array $tracks): void
{
    $this->tracksCache = $tracks;
}
```

Efecto: `hasSpanish()`, `englishTracks()`, `bestEnglishTextTrack()`, `internalTextTracks()`, `externalTracks()` y `reviewPendingCount()` comparten el **mismo array en memoria** dentro de un request, sin volver a la BD.

**4c.** Nuevo `SubtitleTrack::forMediaFiles()` — carga tracks de N archivos en **1 sola query**:

```php
// SubtitleTrack.php
public static function forMediaFiles(array $mediaIds): array
{
    $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
    $stmt = Database::pdo()->prepare(
        "SELECT * FROM subtitle_tracks WHERE media_file_id IN ({$placeholders})
         ORDER BY CASE source_type WHEN 'external' THEN 0 ELSE 1 END, stream_index ASC, id ASC"
    );
    $stmt->execute(array_values($mediaIds));

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['media_file_id']][] = self::fromRow($row);
    }
    return $grouped;
}
```

**4d.** Nuevo `MediaFile::findByIds()` — carga N media files en **1 sola query**:

```php
// MediaFile.php
public static function findByIds(array $ids): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = Database::pdo()->prepare(
        "SELECT * FROM media_files WHERE id IN ({$placeholders})"
    );
    $stmt->execute(array_values($ids));

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $media = self::fromRow($row);
        $map[$media->id] = $media;
    }
    return $map;
}
```

**4e.** `mediaList()` refactorizado — construye objetos desde el row ya cargado y pre-carga todas las tracks:

```php
// ANTES — ~500 queries para 100 items
foreach ($dataStmt->fetchAll() as $row) {
    $media = MediaFile::findById((int) $row['id']); // ← query redundante
    $tracks = $media->tracks();                      // ← query
    $media->hasSpanish();                            // ← tracks() → query
    count($media->englishTracks());                  // ← tracks() → query
    $this->reviewPendingCount($media);               // ← tracks() → query
}

// DESPUÉS — 3 queries totales (count + data + tracks)
$mediaObjects = array_map(fn ($row) => MediaFile::fromRow($row), $dataStmt->fetchAll());

$mediaIds      = array_map(fn ($m) => $m->id, $mediaObjects);
$tracksByMedia = SubtitleTrack::forMediaFiles($mediaIds);
foreach ($mediaObjects as $m) {
    $m->setTracksCache($tracksByMedia[$m->id] ?? []);
}

foreach ($mediaObjects as $media) {
    $tracks     = $media->tracks();       // desde caché — sin query
    $hasSpanish = $media->hasSpanish();   // usa caché internamente — sin query
    ...
}
```

---

### Fix 5 — N+1 masivo en `tree()` ✅

**Archivo:** `app/Http/Controllers/ApiController.php`

Mismo patrón que `mediaList()`. Adicionalmente, `reviewPendingCount()` se calculaba dos veces por archivo: una para el contador de la carpeta y otra para el array del archivo. Se corrigió usando la variable ya calculada:

```php
// ANTES — double call
$reviewCount = $this->reviewPendingCount($media);   // L983
...
'review_pending' => $this->reviewPendingCount($media), // L1016 ← segunda llamada innecesaria

// DESPUÉS — una sola llamada, variable reutilizada
$reviewCount = $this->reviewPendingCount($media);
...
'review_pending' => $reviewCount,
```

---

### Fix 6 — N+1 en `tasksList()` ✅

**Archivo:** `app/Http/Controllers/ApiController.php`

```php
// ANTES — 50 queries extra
array_map(function (ProcessingTask $t) {
    $media = MediaFile::findById($t->mediaFileId);
}, $tasks);

// DESPUÉS — 1 query
$mediaMap = MediaFile::findByIds(
    array_unique(array_map(fn ($t) => $t->mediaFileId, $tasks))
);
array_map(function (ProcessingTask $t) use ($mediaMap) {
    $media = $mediaMap[$t->mediaFileId] ?? null;
}, $tasks);
```

---

### Fix 7 — N+1 en `queueStatus()` ✅

**Archivo:** `app/Http/Controllers/ApiController.php`

```php
// DESPUÉS
$pendingList     = $queue->pendingList();
$pendingMediaMap = MediaFile::findByIds(
    array_unique(array_map(fn ($t) => $t->mediaFileId, $pendingList))
);
array_map(function (ProcessingTask $t) use ($pendingMediaMap) {
    $media = $pendingMediaMap[$t->mediaFileId] ?? null;
}, $pendingList);
```

---

### Fix 8 — Double read de `.review.json` en `mediaDetail()` ✅

**Archivo:** `app/Http/Controllers/ApiController.php`

```php
// ANTES — lee el archivo 2 veces, usa @ silencer
'review_pending'  => count(@json_decode(@file_get_contents($t->path . '.review.json'), true))
'review_problems' => @json_decode(@file_get_contents($t->path . '.review.json'), true)

// DESPUÉS — 1 lectura, sin @, con comprobación explícita
$reviewData = [];
if ($t->sourceType === SubtitleTrack::SOURCE_GENERATED && $t->path !== null) {
    $reviewPath = $t->path . '.review.json';
    if (is_file($reviewPath)) {
        $decoded    = json_decode((string) file_get_contents($reviewPath), true);
        $reviewData = is_array($decoded) ? $decoded : [];
    }
}
return [
    ...
    'review_pending'  => count($reviewData),
    'review_problems' => $reviewData,
];
```

---

### Fix 9 — Dead code en `QueueService::enqueueBatch()` ✅

**Archivo:** `app/Services/Queue/QueueService.php`

```php
// ANTES — query extra sin propósito
$englishTracks = $media->englishTracks(); // resultado nunca usado
$track         = $media->bestEnglishTextTrack();

// DESPUÉS
$track   = $media->bestEnglishTextTrack();
$tasks[] = $this->enqueueTranslation($media, $track);
```

---

### Fix 10 — Query extra en `ProcessingTask::save()` ✅

**Archivo:** `app/Models/ProcessingTask.php`

```php
// ANTES — SELECT extra en cada save()
if ($this->subtitleTrackId !== null && SubtitleTrack::findById($this->subtitleTrackId) === null) {
    $this->subtitleTrackId = null;
}

// DESPUÉS — bloque eliminado
// La FK `subtitle_track_id REFERENCES subtitle_tracks(id) ON DELETE SET NULL`
// ya garantiza esta integridad automáticamente a nivel de SQLite.
```

---

## Tabla de impacto en queries

| Endpoint / Operación | Queries antes | Queries después | Reducción |
|---|---|---|---|
| `GET /media` (100 items) | ~502 | **3** | -99.4% |
| `GET /tree` (1000 items) | ~5000+ | **3** | -99.9% |
| `GET /tasks` (50 tareas) | 52 | **2** | -96% |
| `GET /queue/status` (20 pending) | 22 | **3** | -86% |
| `GET /dashboard` | 7 | **4** | -43% |
| `ProcessingTask::save()` | 2 | **1** | -50% |
| `QueueService::enqueueBatch()` por item | 3 | **2** | -33% |

---

## Pendientes (no aplicados)

| # | Problema | Complejidad estimada |
|---|---|---|
| A | Excepción tipada `TaskCancelledException` en `TaskWorker` | Media |
| B | `writeEnv()`: escapar valores con espacios en el `.env` | Baja |
| C | Helper `Uuid::generate()` compartido (3 implementaciones duplicadas) | Baja |
| D | `Database::migrate()` solo en instalación, no en cada boot | Media |
| E | `MediaFile::all()` sin límite puede cargar toda la BD en RAM | Media |
| F | Reemplazar `@` silencers por comprobaciones explícitas (`is_file`, `is_readable`) | Baja |
| G | Normalizar timestamps a UTC (`gmdate` consistente en todos lados) | Baja |
| H | CORS `*` → restringir al origen configurado | Baja |

---

## Revisión posterior — bug de consistencia del cache

### 🔴 El cache de `tracks()` introdujo un bug (detectado y corregido)

El Fix 4b añadió un cache en memoria a `MediaFile::tracks()`. El problema: **nada invalidaba ese cache** cuando las pistas cambiaban en la BD.

**Escenario real que rompía el worker** (`TaskWorker::processTask`):

```php
$track = $media->bestEnglishTextTrack();  // 1) no hay pistas → cachea []
if (! $track) {
    $this->analyzer->analyze($media);        // 2) crea las pistas en la BD
    $track = $media->bestEnglishTextTrack(); // 3) usa el cache viejo ([]) → null
}
// → "No se encontró ninguna pista de subtítulos en inglés para traducir"
```

**Reproducción comprobada:**

```
1. Antes de analyze:              null (cache vacío)
2. Pistas en BD tras analyze:     13
3. Después con MISMA instancia:   null   ← bug
4. Con objeto NUEVO:              encontrada  ← la BD sí tenía los datos
```

### Fix aplicado

Se añadió `MediaFile::clearTracksCache()` y se invoca en **todos** los puntos que mutan pistas:

| Punto | Archivo |
|---|---|
| Tras analizar (recrea pistas) | `SubtitleAnalyzerService::analyze()` |
| Tras crear un subtítulo generado | `SubtitleExtractorService::saveTranslated()` |
| Tras borrar un subtítulo | `SubtitleRemovalService::deleteTrack()` |

Verificado: el escenario anterior ahora devuelve la pista correctamente en la misma instancia.

> **Lección**: un cache de solo lectura es seguro, pero **todo punto de mutación debe invalidarlo explícitamente**. Como no hay eventos/ORM, la invalidación es manual: si añades código que cree o borre pistas, llama a `clearTracksCache()`.

---

## Rendimiento medido (tras los fixes)

| Endpoint | Tamaño | Tiempo |
|---|---|---|
| `GET /api/tree` (656 archivos) | 6.4 MB | **0.15 s** |
| `GET /api/media?per_page=24` | 204 KB | **0.007 s** |
| `GET /api/dashboard` | 2.7 KB | **0.032 s** |
| `GET /api/tasks` | 34 KB | **0.003 s** |
