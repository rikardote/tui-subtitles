<!DOCTYPE html>
<html lang="es" class="dark h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subtitle Manager — Queue & Worker</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                        },
                        dark: {
                            950: '#090d16',
                            900: '#0f172a',
                            850: '#151f32',
                            800: '#1e293b',
                            750: '#273549',
                            700: '#334155',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                        mono: ['JetBrains Mono', 'Fira Code', 'Menlo', 'monospace']
                    }
                }
            }
        }
    </script>
    <!-- Alpine.js & Plugins -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [x-cloak] { display: none !important; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body class="bg-dark-950 text-slate-200 min-h-full font-sans antialiased flex flex-col selection:bg-indigo-500 selection:text-white"
      x-data="subtitleApp()" x-init="initApp()">

    <!-- CLEAN NAVBAR -->
    <header class="bg-dark-900/90 backdrop-blur-md border-b border-dark-800/80 sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between gap-4">
            
            <!-- Logo & Brand -->
            <div class="flex items-center space-x-3 shrink-0">
                <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center shadow-sm">
                    <i data-lucide="subtitles" class="w-4 h-4 text-white"></i>
                </div>
                <div class="flex items-baseline space-x-2">
                    <span class="font-bold text-sm text-white tracking-tight">Subtitles</span>
                    <span class="text-[11px] text-slate-400 font-mono hidden sm:inline" x-text="dashboard.provider?.name || 'Sin proveedor'"></span>
                </div>
            </div>

            <!-- View mode tabs -->
            <nav class="flex items-center space-x-1 bg-dark-950 p-1 rounded-lg border border-dark-800 text-xs">
                <button @click="viewMode = 'tree'; currentTab = 'media'"
                        :class="viewMode === 'tree' && currentTab === 'media' ? 'bg-dark-800 text-white font-medium shadow-sm' : 'text-slate-400 hover:text-slate-200'"
                        class="px-3 py-1 rounded-md transition flex items-center space-x-1.5">
                    <i data-lucide="folder-tree" class="w-3.5 h-3.5"></i>
                    <span>Árbol</span>
                </button>
                <button @click="viewMode = 'table'; currentTab = 'media'"
                        :class="viewMode === 'table' && currentTab === 'media' ? 'bg-dark-800 text-white font-medium shadow-sm' : 'text-slate-400 hover:text-slate-200'"
                        class="px-3 py-1 rounded-md transition flex items-center space-x-1.5">
                    <i data-lucide="list" class="w-3.5 h-3.5"></i>
                    <span>Tabla</span>
                </button>
                <span class="w-px h-3.5 bg-dark-800 mx-0.5"></span>
                <button @click="currentTab = 'jellyfin'"
                        :class="currentTab === 'jellyfin' ? 'bg-dark-800 text-white font-medium shadow-sm' : 'text-slate-400 hover:text-slate-200'"
                        class="px-3 py-1 rounded-md transition flex items-center space-x-1.5">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                    <span>Jellyfin</span>
                </button>
                <button @click="currentTab = 'tasks'"
                        :class="currentTab === 'tasks' ? 'bg-dark-800 text-white font-medium shadow-sm' : 'text-slate-400 hover:text-slate-200'"
                        class="px-3 py-1 rounded-md transition flex items-center space-x-1.5">
                    <i data-lucide="history" class="w-3.5 h-3.5"></i>
                    <span>Cola y Tareas</span>
                    <span x-show="queue.pending_count > 0" class="ml-1 px-1.5 py-0.2 rounded-full bg-amber-500/20 text-amber-300 font-mono text-[10px]" x-text="queue.pending_count"></span>
                </button>
            </nav>

            <!-- Actions & Queue Indicator -->
            <div class="flex items-center space-x-2 shrink-0">
                <!-- Live Queue Badge -->
                <div x-show="queue.active || queue.pending_count > 0" @click="currentTab = 'tasks'"
                     class="cursor-pointer px-2.5 py-1 bg-indigo-950/80 hover:bg-indigo-900/80 border border-indigo-500/40 rounded-lg text-xs font-medium text-indigo-300 flex items-center space-x-2 transition">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                    </span>
                    <span class="font-mono text-[11px]" x-text="queue.active ? ((queue.running_task?.progress || 0) + '%') : (queue.pending_count + ' en cola')"></span>
                </div>

                <button @click="triggerScan()" :disabled="scanning"
                        class="px-3 py-1.5 bg-dark-850 hover:bg-dark-800 border border-dark-750 text-slate-300 hover:text-white rounded-lg text-xs font-medium transition flex items-center space-x-1.5">
                    <i data-lucide="scan" :class="{'animate-spin': scanning}" class="w-3.5 h-3.5"></i>
                    <span x-text="scanning ? 'Escaneando...' : 'Escanear'"></span>
                </button>

                <button @click="openSettingsModal()"
                        class="p-1.5 text-slate-400 hover:text-white hover:bg-dark-850 rounded-lg transition"
                        title="Ajustes">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- LIVE ACTIVE QUEUE BAR -->
    <div x-show="queue.active" x-cloak
         class="bg-gradient-to-r from-indigo-950 via-dark-900 to-indigo-950 border-b border-indigo-500/30 px-4 sm:px-6 py-2.5 transition-all shadow-md">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
            <div class="flex items-center space-x-2.5 overflow-hidden">
                <span class="relative flex h-2.5 w-2.5 shrink-0">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-indigo-500"></span>
                </span>
                <span class="font-semibold text-indigo-300">Worker en proceso:</span>
                <span class="text-slate-300 font-medium truncate max-w-md" :title="queue.running_task?.filename" x-text="queue.running_task?.filename"></span>
                <span x-show="queue.pending_count > 0" class="text-slate-500 font-mono text-[11px]" x-text="'(+' + queue.pending_count + ' en espera)'"></span>
            </div>

            <!-- Progress Bar & Cancel Button -->
            <div class="flex items-center space-x-3 w-full sm:w-80">
                <div class="flex-1 bg-dark-950 rounded-full h-2 overflow-hidden border border-dark-750">
                    <div class="bg-gradient-to-r from-indigo-500 to-violet-500 h-full transition-all duration-300 rounded-full"
                         :style="'width: ' + (queue.running_task?.progress || 0) + '%'"></div>
                </div>
                <span class="font-mono font-bold text-xs text-indigo-300 w-10 text-right" x-text="(queue.running_task?.progress || 0) + '%'"></span>
                <button @click="cancelTask(queue.running_task?.task_id)" title="Cancelar traducción"
                        class="p-1 hover:bg-rose-900/40 text-slate-400 hover:text-rose-300 rounded transition">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- FILTER & SEARCH BAR -->
    <section class="bg-dark-900/50 border-b border-dark-800/60 px-4 sm:px-6 py-3">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 text-xs">
            
            <!-- Quick filters -->
            <div class="flex items-center space-x-2 overflow-x-auto pb-1 sm:pb-0">
                <button @click="setFilter('all')"
                        :class="filterSpanish === 'all' ? 'bg-indigo-600 text-white font-medium' : 'bg-dark-850 text-slate-400 hover:text-slate-200 border border-dark-800'"
                        class="px-3 py-1.5 rounded-lg transition shrink-0 flex items-center space-x-1.5">
                    <span>Todos</span>
                    <span class="font-mono text-[11px] opacity-75" x-text="'(' + (dashboard.total_files || 0) + ')'"></span>
                </button>

                <button @click="setFilter('0')"
                        :class="filterSpanish === '0' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 font-medium' : 'bg-dark-850 text-slate-400 hover:text-slate-200 border border-dark-800'"
                        class="px-3 py-1.5 rounded-lg transition shrink-0 flex items-center space-x-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    <span>Sin Español</span>
                    <span class="font-mono text-[11px] opacity-75" x-text="'(' + (dashboard.missing_spanish || 0) + ')'"></span>
                </button>

                <button @click="setFilter('1')"
                        :class="filterSpanish === '1' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 font-medium' : 'bg-dark-850 text-slate-400 hover:text-slate-200 border border-dark-800'"
                        class="px-3 py-1.5 rounded-lg transition shrink-0 flex items-center space-x-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    <span>Con Español</span>
                    <span class="font-mono text-[11px] opacity-75" x-text="'(' + (dashboard.has_spanish || 0) + ')'"></span>
                </button>
            </div>

            <!-- Controls: Expand/Collapse + Search -->
            <div class="flex items-center space-x-2">
                <!-- Tree Fold Controls -->
                <div x-show="viewMode === 'tree' && currentTab === 'media'" class="flex items-center space-x-1">
                    <button @click="expandAllFolders()" title="Expandir todas las carpetas"
                            class="px-2 py-1 bg-dark-850 hover:bg-dark-800 text-slate-400 hover:text-slate-200 border border-dark-800 rounded-md text-[11px] transition">
                        + Expandir
                    </button>
                    <button @click="collapseAllFolders()" title="Cerrar todas las carpetas"
                            class="px-2 py-1 bg-dark-850 hover:bg-dark-800 text-slate-400 hover:text-slate-200 border border-dark-800 rounded-md text-[11px] transition">
                        − Colapsar
                    </button>
                </div>

                <!-- Search -->
                <div class="relative flex-1 sm:w-64">
                    <i data-lucide="search" class="w-3.5 h-3.5 text-slate-500 absolute left-3 top-2.5"></i>
                    <input type="text" x-model="searchQuery" @input.debounce.300ms="fetchTree(); fetchMedia()"
                           placeholder="Buscar archivo o serie..."
                           class="w-full pl-8 pr-3 py-1.5 bg-dark-950 border border-dark-800 rounded-lg text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
                </div>
            </div>
        </div>
    </section>

    <!-- CONTENT BODY -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:px-6 space-y-4">

        <!-- ========================================== -->
        <!-- VIEW 1: CLEAN MINIMAL FOLDER TREE          -->
        <!-- ========================================== -->
        <section x-show="currentTab === 'media' && viewMode === 'tree'" class="space-y-3">
            
            <div x-show="loadingTree" class="py-12 text-center text-slate-500">
                <i data-lucide="loader-2" class="w-5 h-5 animate-spin mx-auto mb-2 text-indigo-500"></i>
                <p class="text-xs">Cargando árbol de archivos...</p>
            </div>

            <div x-show="!loadingTree && treeData.length === 0" class="py-12 text-center text-slate-500">
                <i data-lucide="folder" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
                <p class="text-xs">No hay elementos que coincidan con la búsqueda o filtro.</p>
            </div>

            <div x-show="!loadingTree && treeData.length > 0" class="space-y-3">
                <template x-for="lib in treeData" :key="lib.name">
                    <div class="bg-dark-900 border border-dark-800/80 rounded-xl overflow-hidden shadow-sm">
                        
                        <!-- Library Header Bar -->
                        <div class="px-4 py-2.5 bg-dark-850/60 flex items-center justify-between border-b border-dark-800/60 cursor-pointer select-none hover:bg-dark-850 transition"
                             @click="lib.collapsed = !lib.collapsed">
                            <div class="flex items-center space-x-2.5">
                                <i :data-lucide="lib.collapsed ? 'chevron-right' : 'chevron-down'" class="w-4 h-4 text-slate-400"></i>
                                <i data-lucide="folder" class="w-4 h-4 text-indigo-400"></i>
                                <span class="font-semibold text-xs text-white" x-text="lib.name"></span>
                                <span class="text-[11px] text-slate-500 font-mono" x-text="'(' + lib.total_files + ' videos)'"></span>
                            </div>
                            <div class="text-[11px] font-mono">
                                <span :class="lib.has_spanish === lib.total_files ? 'text-emerald-400' : 'text-amber-400'"
                                      x-text="lib.has_spanish + '/' + lib.total_files + ' con español'"></span>
                            </div>
                        </div>

                        <!-- Folders List -->
                        <div x-show="!lib.collapsed" class="divide-y divide-dark-800/30">
                            <template x-for="folder in lib.folders" :key="folder.rel_path">
                                <div class="bg-dark-900/40">
                                    
                                    <!-- Folder Row (Clean & Lightweight) -->
                                    <div class="px-4 py-2 flex items-center justify-between hover:bg-dark-850/50 cursor-pointer select-none pl-6 text-xs transition"
                                         @click="folder.collapsed = !folder.collapsed">
                                        <div class="flex items-center space-x-2.5 overflow-hidden">
                                            <i :data-lucide="folder.collapsed ? 'chevron-right' : 'chevron-down'" class="w-3.5 h-3.5 text-slate-500"></i>
                                            <i data-lucide="folder" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>
                                            <span class="font-medium text-slate-200 truncate" x-text="folder.name"></span>
                                            <span class="text-[11px] text-slate-500 font-mono" x-text="'(' + folder.total_files + ')'"></span>
                                        </div>
                                        
                                        <!-- Folder state indicator + Batch translate action -->
                                        <div class="flex items-center space-x-2 shrink-0">
                                            <template x-if="folder.has_spanish === folder.total_files">
                                                <span class="text-[11px] text-emerald-400 font-mono flex items-center gap-1">
                                                    <i data-lucide="check" class="w-3 h-3"></i> Listo
                                                </span>
                                            </template>
                                            <template x-if="folder.has_spanish < folder.total_files">
                                                <div class="flex items-center space-x-1.5">
                                                    <span class="text-[11px] text-amber-400 font-mono bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/20">
                                                        <span x-text="folder.total_files - folder.has_spanish"></span> pendientes
                                                    </span>
                                                    <button @click.stop="translateFolderBatch(folder)"
                                                            class="px-2 py-0.5 bg-indigo-600/80 hover:bg-indigo-600 text-white rounded text-[10px] font-medium transition flex items-center space-x-1"
                                                            title="Encolar todos los episodios pendientes de esta carpeta">
                                                        <i data-lucide="play" class="w-2.5 h-2.5"></i>
                                                        <span>Encolar todos</span>
                                                    </button>
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Files inside Folder -->
                                    <div x-show="!folder.collapsed" class="bg-dark-950/80 pl-10 pr-4 py-1 border-t border-dark-800/30">
                                        <div class="divide-y divide-dark-800/20 text-xs">
                                            <template x-for="file in folder.files" :key="file.id">
                                                <div class="py-2 flex items-center justify-between gap-3 hover:bg-dark-900/60 px-2 rounded-lg transition group">
                                                    
                                                    <!-- File name & basic specs -->
                                                    <div class="flex items-center space-x-2 overflow-hidden">
                                                        <i data-lucide="film" class="w-3.5 h-3.5 text-slate-500 shrink-0"></i>
                                                        <span class="font-medium text-slate-200 truncate" :title="file.filename" x-text="file.filename"></span>
                                                        <span class="text-[11px] text-slate-500 font-mono shrink-0 hidden sm:inline" x-text="file.file_size"></span>
                                                    </div>

                                                    <!-- Subtitles info & Queue Action -->
                                                    <div class="flex items-center space-x-2 shrink-0">
                                                        <!-- Status Pill -->
                                                        <template x-if="file.has_spanish">
                                                            <span class="text-[11px] text-emerald-400 font-medium flex items-center gap-1">
                                                                <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
                                                                <span class="hidden sm:inline">Español</span>
                                                            </span>
                                                        </template>
                                                        
                                                        <!-- Is in active translation -->
                                                        <template x-if="!file.has_spanish && queue.running_task?.media_id === file.id">
                                                            <span class="px-2.5 py-1 bg-indigo-900/60 border border-indigo-500/40 text-indigo-200 rounded text-xs font-mono font-bold flex items-center space-x-1.5">
                                                                <i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i>
                                                                <span x-text="(queue.running_task?.progress || 0) + '%'"></span>
                                                            </span>
                                                        </template>

                                                        <!-- Is in pending queue -->
                                                        <template x-if="!file.has_spanish && isPending(file.id) && queue.running_task?.media_id !== file.id">
                                                            <span class="px-2.5 py-1 bg-amber-500/10 border border-amber-500/30 text-amber-300 rounded text-[11px] font-mono flex items-center space-x-1">
                                                                <i data-lucide="clock" class="w-3 h-3"></i>
                                                                <span>En cola</span>
                                                            </span>
                                                        </template>

                                                        <!-- Ready to enqueue -->
                                                        <template x-if="!file.has_spanish && file.english_tracks_count > 0 && !isPending(file.id) && queue.running_task?.media_id !== file.id">
                                                            <button @click.stop="quickTranslate(file.id)"
                                                                    class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded text-xs font-semibold transition flex items-center space-x-1.5 shadow-sm">
                                                                <i data-lucide="languages" class="w-3 h-3"></i>
                                                                <span>Traducir</span>
                                                            </button>
                                                        </template>

                                                        <template x-if="!file.has_spanish && file.english_tracks_count === 0">
                                                            <span class="text-[11px] text-slate-500">Sin fuente EN</span>
                                                        </template>

                                                        <!-- Track inspect button -->
                                                        <button @click.stop="openMediaModal(file.id)" class="p-1 text-slate-500 hover:text-slate-200 rounded" title="Ver pistas">
                                                            <i data-lucide="more-horizontal" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 2: CLEAN VERTICAL TABLE               -->
        <!-- ========================================== -->
        <section x-show="currentTab === 'media' && viewMode === 'table'" class="space-y-3">
            <div class="bg-dark-900 border border-dark-800/80 rounded-xl overflow-hidden shadow-sm">
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-300">
                        <thead class="bg-dark-950 text-slate-400 border-b border-dark-800/80 text-[11px] font-semibold">
                            <tr>
                                <th class="py-2.5 px-4">Archivo</th>
                                <th class="py-2.5 px-3">Tamaño</th>
                                <th class="py-2.5 px-3">Subtítulos</th>
                                <th class="py-2.5 px-3">Estado</th>
                                <th class="py-2.5 px-4 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-dark-800/40">
                            <template x-for="item in mediaList" :key="item.id">
                                <tr class="hover:bg-dark-850/40 transition">
                                    
                                    <!-- Title & Path -->
                                    <td class="py-2.5 px-4 max-w-md truncate" :title="item.filename">
                                        <div class="font-medium text-slate-100 truncate" x-text="item.filename"></div>
                                        <div class="text-[10px] text-slate-500 truncate font-mono mt-0.5" x-text="item.path"></div>
                                    </td>

                                    <!-- Size -->
                                    <td class="py-2.5 px-3 font-mono text-[11px] text-slate-400 whitespace-nowrap" x-text="item.file_size"></td>

                                    <!-- Tracks Summary -->
                                    <td class="py-2.5 px-3">
                                        <div class="flex items-center flex-wrap gap-1">
                                            <template x-for="t in item.tracks_summary" :key="t.id">
                                                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono border"
                                                      :class="{
                                                          'bg-emerald-500/10 text-emerald-300 border-emerald-500/30': t.lang_code === 'spa' || t.lang_code === 'es',
                                                          'bg-indigo-500/10 text-indigo-300 border-indigo-500/30': t.lang_code === 'eng' || t.lang_code === 'en',
                                                          'bg-dark-800 text-slate-400 border-dark-700': t.lang_code !== 'spa' && t.lang_code !== 'eng'
                                                      }">
                                                    <span x-text="t.language.substring(0, 3).toUpperCase()"></span>
                                                    <span x-show="t.is_sdh">(SDH)</span>
                                                </span>
                                            </template>
                                            <span x-show="item.tracks_count === 0" class="text-slate-600 text-[10px] italic">Sin pistas</span>
                                        </div>
                                    </td>

                                    <!-- Status -->
                                    <td class="py-2.5 px-3 whitespace-nowrap">
                                        <template x-if="item.has_spanish">
                                            <span class="text-emerald-400 text-xs font-medium flex items-center gap-1">
                                                <i data-lucide="check" class="w-3.5 h-3.5"></i> Con Español
                                            </span>
                                        </template>
                                        <template x-if="!item.has_spanish && queue.running_task?.media_id === item.id">
                                            <span class="text-indigo-400 font-mono text-xs font-semibold flex items-center gap-1">
                                                <i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i>
                                                <span x-text="(queue.running_task?.progress || 0) + '%'"></span>
                                            </span>
                                        </template>
                                        <template x-if="!item.has_spanish && isPending(item.id) && queue.running_task?.media_id !== item.id">
                                            <span class="text-amber-400 text-xs font-medium">En cola</span>
                                        </template>
                                        <template x-if="!item.has_spanish && !isPending(item.id) && queue.running_task?.media_id !== item.id && item.english_tracks_count > 0">
                                            <span class="text-amber-400 text-xs font-medium">Pendiente</span>
                                        </template>
                                        <template x-if="!item.has_spanish && item.english_tracks_count === 0">
                                            <span class="text-slate-500 text-xs">Sin fuente</span>
                                        </template>
                                    </td>

                                    <!-- Action -->
                                    <td class="py-2.5 px-4 text-right whitespace-nowrap space-x-1">
                                        <template x-if="!item.has_spanish && !isPending(item.id) && queue.running_task?.media_id !== item.id && item.english_tracks_count > 0">
                                            <button @click="quickTranslate(item.id)"
                                                    class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 text-white rounded text-xs font-medium transition">
                                                Traducir
                                            </button>
                                        </template>
                                        <button @click="openMediaModal(item.id)" class="p-1 text-slate-400 hover:text-white rounded" title="Detalles">
                                            <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div x-show="pagination.last_page > 1" class="px-4 py-2.5 bg-dark-950 border-t border-dark-800 flex items-center justify-between text-xs text-slate-400">
                    <span>Página <strong class="text-white" x-text="pagination.page"></strong> de <strong class="text-white" x-text="pagination.last_page"></strong></span>
                    <div class="space-x-1">
                        <button @click="changePage(pagination.page - 1)" :disabled="pagination.page <= 1" class="px-2.5 py-1 bg-dark-850 hover:bg-dark-800 disabled:opacity-30 rounded text-slate-300">Anterior</button>
                        <button @click="changePage(pagination.page + 1)" :disabled="pagination.page >= pagination.last_page" class="px-2.5 py-1 bg-dark-850 hover:bg-dark-800 disabled:opacity-30 rounded text-slate-300">Siguiente</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 3: JELLYFIN TAB                       -->
        <!-- ========================================== -->
        <section x-show="currentTab === 'jellyfin'" class="space-y-4">
            <div class="bg-dark-900 border border-dark-800 rounded-xl p-5 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-dark-800">
                    <h2 class="text-sm font-semibold text-white">Sincronización con Jellyfin</h2>
                    <span class="text-xs px-2.5 py-0.5 rounded-full"
                          :class="jellyfin.connected ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/10 text-rose-400'">
                        <span x-text="jellyfin.connected ? 'Conectado' : 'Desconectado'"></span>
                    </span>
                </div>

                <div class="flex items-center space-x-3 text-xs">
                    <select x-model="jellyfinSyncOptions.item_types" class="bg-dark-950 border border-dark-750 text-slate-200 rounded p-1.5">
                        <option value="Movie,Episode">Películas y Series</option>
                        <option value="Movie">Solo Películas</option>
                        <option value="Episode">Solo Episodios</option>
                    </select>

                    <button @click="startJellyfinSync()" :disabled="jellyfinSyncing"
                            class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded font-medium transition">
                        <span x-text="jellyfinSyncing ? 'Sincronizando...' : 'Iniciar Sincronización'"></span>
                    </button>
                </div>

                <div x-show="jellyfinResult" class="bg-dark-950 p-3 rounded-lg border border-dark-800 font-mono text-xs text-slate-300">
                    <p class="text-slate-400">Resultado: <span class="text-white" x-text="jellyfinResult?.message"></span></p>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 4: COLA Y TAREAS                      -->
        <!-- ========================================== -->
        <section x-show="currentTab === 'tasks'" class="space-y-4">
            
            <!-- Pending Queue Section -->
            <div class="bg-dark-900 border border-dark-800 rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-dark-800">
                    <div class="flex items-center space-x-2">
                        <i data-lucide="layers" class="w-4 h-4 text-indigo-400"></i>
                        <h2 class="text-sm font-semibold text-white">Cola de Traducción en Segundo Plano</h2>
                    </div>
                    <span class="text-xs font-mono text-slate-400" x-text="queue.pending_count + ' pendientes'"></span>
                </div>

                <!-- Active worker row -->
                <template x-if="queue.active">
                    <div class="p-3 bg-indigo-950/40 border border-indigo-500/30 rounded-xl space-y-2 text-xs">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <span class="px-2 py-0.5 rounded bg-indigo-600 text-white text-[10px] font-bold uppercase">En Proceso</span>
                                <span class="font-medium text-slate-100" x-text="queue.running_task?.filename"></span>
                            </div>
                            <button @click="cancelTask(queue.running_task?.task_id)" class="px-2 py-0.5 bg-rose-600 hover:bg-rose-500 text-white rounded text-[11px] transition">Cancelar</button>
                        </div>
                        <div class="flex items-center space-x-2">
                            <div class="flex-1 bg-dark-950 rounded-full h-2 overflow-hidden border border-dark-750">
                                <div class="bg-indigo-500 h-full rounded-full transition-all duration-300" :style="'width: ' + (queue.running_task?.progress || 0) + '%'"></div>
                            </div>
                            <span class="font-mono font-bold text-indigo-300 text-xs" x-text="(queue.running_task?.progress || 0) + '%'"></span>
                        </div>
                    </div>
                </template>

                <!-- Pending Queue List -->
                <div x-show="queue.pending_tasks.length > 0" class="space-y-1.5">
                    <p class="text-[11px] text-slate-400 font-semibold uppercase tracking-wider pt-1">Próximos en cola:</p>
                    <template x-for="(pt, idx) in queue.pending_tasks" :key="pt.task_id">
                        <div class="p-2 bg-dark-950/80 border border-dark-800 rounded-lg flex items-center justify-between text-xs">
                            <div class="flex items-center space-x-2">
                                <span class="text-slate-500 font-mono text-[11px]" x-text="'#' + (idx + 1)"></span>
                                <span class="text-slate-300 font-medium" x-text="pt.filename"></span>
                            </div>
                            <button @click="cancelTask(pt.task_id)" class="text-slate-500 hover:text-rose-400 text-xs">Quitar</button>
                        </div>
                    </template>
                </div>

                <div x-show="!queue.active && queue.pending_tasks.length === 0" class="py-6 text-center text-slate-500 text-xs">
                    <i data-lucide="check-circle" class="w-6 h-6 mx-auto mb-1 text-slate-600"></i>
                    <p>La cola de traducción está vacía. Selecciona videos para traducir.</p>
                </div>
            </div>

            <!-- Historical Tasks -->
            <div class="bg-dark-900 border border-dark-800 rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-dark-800">
                    <h2 class="text-sm font-semibold text-white">Historial de Tareas Completadas</h2>
                    <button @click="fetchTasks()" class="text-xs text-slate-400 hover:text-white">Refrescar</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-300">
                        <thead class="bg-dark-950 text-slate-500 border-b border-dark-800 text-[11px]">
                            <tr>
                                <th class="py-2 px-3">Fecha</th>
                                <th class="py-2 px-3">Archivo</th>
                                <th class="py-2 px-3">Acción</th>
                                <th class="py-2 px-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-dark-800/40">
                            <template x-for="task in tasksList" :key="task.id">
                                <tr>
                                    <td class="py-2 px-3 font-mono text-slate-400" x-text="task.created_at"></td>
                                    <td class="py-2 px-3 truncate max-w-xs" :title="task.filename" x-text="task.filename"></td>
                                    <td class="py-2 px-3" x-text="task.action_label"></td>
                                    <td class="py-2 px-3" :class="task.status === 'completed' ? 'text-emerald-400' : 'text-rose-400'" x-text="task.status_label"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>

    <!-- TRACK DETAIL MODAL -->
    <div x-show="mediaModalOpen" x-cloak class="fixed inset-0 z-50 bg-dark-950/80 backdrop-blur flex items-center justify-center p-4">
        <div @click.outside="mediaModalOpen = false" class="bg-dark-900 border border-dark-800 rounded-xl max-w-lg w-full p-5 space-y-4 shadow-2xl">
            <div class="flex items-start justify-between pb-2 border-b border-dark-800">
                <div class="overflow-hidden pr-3">
                    <h3 class="text-sm font-semibold text-white truncate" x-text="activeMedia.filename"></h3>
                    <p class="text-[10px] text-slate-500 font-mono truncate" x-text="activeMedia.path"></p>
                </div>
                <button @click="mediaModalOpen = false" class="text-slate-400 hover:text-white">&times;</button>
            </div>

            <div class="space-y-2 text-xs">
                <h4 class="text-slate-400 font-medium">Pistas de Subtítulos:</h4>
                <div class="border border-dark-800 rounded-lg overflow-hidden divide-y divide-dark-800">
                    <template x-for="t in activeMedia.tracks" :key="t.id">
                        <div class="p-2.5 flex items-center justify-between bg-dark-950/60">
                            <div>
                                <span class="font-semibold text-slate-200" x-text="t.language"></span>
                                <span x-show="t.is_sdh" class="text-amber-400 text-[10px] ml-1">(SDH)</span>
                                <span class="text-[10px] text-slate-500 ml-1" x-text="'[' + t.codec + ']'"></span>
                            </div>
                            <div class="space-x-1">
                                <button x-show="t.can_translate" @click="translateTrack(activeMedia.id, t.id)"
                                        class="px-2 py-0.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded text-[10px] font-medium transition">
                                    Traducir
                                </button>
                                <button x-show="t.review_pending > 0" @click="reviewTrack(t.id)"
                                        class="px-2 py-0.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded text-[10px] font-medium transition">
                                    Revisar con DeepSeek (x-text="t.review_pending")
                                </button>
                                <button x-show="t.can_delete" @click="deleteTrack(t.id)"
                                        class="px-2 py-0.5 bg-rose-600 hover:bg-rose-500 text-white rounded text-[10px] transition">
                                    Borrar
                                </button>
                            </div>
                        </div>
                        <div x-show="t.review_pending > 0" class="px-2.5 pb-2 bg-dark-950/60 text-amber-400 text-[10px] flex items-center gap-1">
                            <span>⚠</span>
                            <span x-text="t.review_pending + ' bloque(s) detectado(s) como deficientes (sin traducir o error). La revisión usa DeepSeek (forzado).'"></span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="flex justify-end pt-2 border-t border-dark-800">
                <button @click="mediaModalOpen = false" class="px-3 py-1 bg-dark-850 hover:bg-dark-800 text-slate-200 rounded text-xs">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- SETTINGS MODAL -->
    <div x-show="settingsModalOpen" x-cloak class="fixed inset-0 z-50 bg-dark-950/80 backdrop-blur flex items-center justify-center p-4">
        <div @click.outside="settingsModalOpen = false" class="bg-dark-900 border border-dark-800 rounded-xl max-w-md w-full p-5 space-y-4 shadow-2xl">
            <div class="flex items-center justify-between pb-2 border-b border-dark-800">
                <h3 class="text-sm font-semibold text-white">Ajustes de Traducción</h3>
                <button @click="settingsModalOpen = false" class="text-slate-400 hover:text-white">&times;</button>
            </div>

            <div class="space-y-3 text-xs">
                <div>
                    <label class="text-slate-400">Proveedor</label>
                    <select x-model="settingsForm.provider" class="w-full mt-1 bg-dark-950 border border-dark-750 text-slate-200 rounded p-1.5">
                        <template x-for="p in settingsData.providers" :key="p.id">
                            <option :value="p.id" x-text="p.name"></option>
                        </template>
                    </select>
                </div>

                <div x-show="settingsForm.provider === 'ollama'" class="p-3 bg-dark-950 rounded border border-dark-800 space-y-2">
                    <div>
                        <label class="text-slate-400">Ollama URL</label>
                        <input type="text" x-model="settingsForm.ollama_url" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                    <div>
                        <label class="text-slate-400">Modelo</label>
                        <input type="text" x-model="settingsForm.ollama_model" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                </div>

                <div x-show="settingsForm.provider === 'deepseek'" class="p-3 bg-dark-950 rounded border border-dark-800 space-y-2">
                    <div>
                        <label class="text-slate-400">Modelo DeepSeek</label>
                        <input type="text" x-model="settingsForm.deepseek_model" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                    <div>
                        <label class="text-slate-400">API Key</label>
                        <input type="password" x-model="settingsForm.deepseek_api_key" placeholder="••••••••" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                </div>

                <div x-show="settingsForm.provider === 'meta-muse'" class="p-3 bg-dark-950 rounded border border-dark-800 space-y-2">
                    <div>
                        <label class="text-slate-400">Modelo Muse Spark</label>
                        <input type="text" x-model="settingsForm.meta_muse_model" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                    <div>
                        <label class="text-slate-400">API Key</label>
                        <input type="password" x-model="settingsForm.meta_muse_api_key" placeholder="••••••••" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                </div>

                <div x-show="settingsForm.provider === 'openai'" class="p-3 bg-dark-950 rounded border border-dark-800 space-y-2">
                    <div>
                        <label class="text-slate-400">Modelo</label>
                        <input type="text" x-model="settingsForm.openai_model" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                    <div>
                        <label class="text-slate-400">Base URL</label>
                        <input type="text" x-model="settingsForm.openai_base_url" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                    <div>
                        <label class="text-slate-400">API Key</label>
                        <input type="password" x-model="settingsForm.openai_api_key" placeholder="••••••••" class="w-full mt-1 bg-dark-900 border border-dark-750 text-slate-200 rounded p-1.5 font-mono text-[11px]">
                    </div>
                </div>

                <div class="flex justify-between items-center p-2.5 bg-dark-950 border border-dark-800 rounded">
                    <span class="text-slate-300">Probar conexión</span>
                    <button @click="testProvider()" :disabled="testingProvider" class="px-2.5 py-1 bg-indigo-600 text-white rounded text-[11px]">
                        <span x-text="testingProvider ? '...' : 'Probar'"></span>
                    </button>
                </div>
                <div x-show="testResult" class="p-2 bg-dark-900 rounded font-mono text-[10px] text-emerald-400 border border-dark-800" x-text="testResult?.translation"></div>
            </div>

            <div class="flex justify-end space-x-2 pt-2 border-t border-dark-800">
                <button @click="settingsModalOpen = false" class="px-3 py-1 text-slate-400 text-xs">Cancelar</button>
                <button @click="saveSettings()" class="px-3 py-1 bg-indigo-600 text-white rounded text-xs font-semibold">Guardar</button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div x-show="toast.visible" x-cloak class="fixed bottom-4 right-4 z-50 bg-dark-900 border border-dark-700 text-white px-3.5 py-2 rounded-lg shadow-xl text-xs flex items-center space-x-2">
        <i data-lucide="info" class="w-3.5 h-3.5 text-indigo-400"></i>
        <span x-text="toast.message"></span>
    </div>

    <script>
        function subtitleApp() {
            return {
                currentTab: 'media',
                viewMode: 'tree',
                dashboard: {},
                treeData: [],
                loadingTree: false,
                mediaList: [],
                loadingMedia: false,
                searchQuery: '',
                filterSpanish: 'all',
                pagination: { page: 1, last_page: 1, total: 0, per_page: 50 },
                scanning: false,

                queue: { active: false, running_task: null, pending_count: 0, pending_tasks: [] },
                pollTimer: null,

                mediaModalOpen: false,
                activeMedia: {},

                jellyfin: {},
                jellyfinSyncing: false,
                jellyfinSyncOptions: { item_types: 'Movie,Episode', limit: 0, dry_run: false },
                jellyfinResult: null,

                tasksList: [],
                settingsModalOpen: false,
                settingsData: {},
                settingsForm: {},
                testingProvider: false,
                testResult: null,
                toast: { visible: false, message: '' },

                initApp() {
                    this.fetchDashboard();
                    this.fetchTree();
                    this.fetchMedia();
                    this.fetchJellyfin();
                    this.fetchTasks();
                    this.fetchQueueStatus();
                    this.startQueuePolling();
                    this.$nextTick(() => lucide.createIcons());
                },

                showToast(msg) {
                    this.toast.message = msg;
                    this.toast.visible = true;
                    setTimeout(() => { this.toast.visible = false; }, 3000);
                },

                setFilter(val) {
                    this.filterSpanish = val;
                    this.fetchTree();
                    this.fetchMedia();
                },

                isPending(mediaId) {
                    return this.queue.pending_tasks.some(t => t.media_id === mediaId);
                },

                expandAllFolders() {
                    this.treeData.forEach(lib => {
                        lib.collapsed = false;
                        (lib.folders || []).forEach(f => { f.collapsed = false; });
                    });
                    this.$nextTick(() => lucide.createIcons());
                },

                collapseAllFolders() {
                    this.treeData.forEach(lib => {
                        (lib.folders || []).forEach(f => { f.collapsed = true; });
                    });
                    this.$nextTick(() => lucide.createIcons());
                },

                startQueuePolling() {
                    if (this.pollTimer) return;
                    this.pollTimer = setInterval(async () => {
                        await this.fetchQueueStatus();
                    }, 1200);
                },

                async fetchQueueStatus() {
                    try {
                        const prevActive = this.queue.active;
                        const res = await fetch('/api/queue/status');
                        const data = await res.json();
                        this.queue = data;

                        // Si una tarea terminó de ejecutarse, refrescar vistas
                        if (prevActive && !data.active) {
                            this.fetchTree();
                            this.fetchMedia();
                            this.fetchDashboard();
                            this.fetchTasks();
                            this.showToast('✓ Tarea de traducción completada');
                        }
                        this.$nextTick(() => lucide.createIcons());
                    } catch (e) {}
                },

                async fetchDashboard() {
                    try {
                        const res = await fetch('/api/dashboard');
                        this.dashboard = await res.json();
                        this.$nextTick(() => lucide.createIcons());
                    } catch (e) { console.error(e); }
                },

                async fetchTree() {
                    this.loadingTree = true;
                    try {
                        const params = new URLSearchParams({
                            q: this.searchQuery,
                            has_spanish: this.filterSpanish
                        });
                        const res = await fetch('/api/tree?' + params.toString());
                        const data = await res.json();
                        
                        this.treeData = (data.tree || []).map((lib) => ({
                            ...lib,
                            collapsed: false,
                            folders: (lib.folders || []).map(f => ({
                                ...f,
                                collapsed: true,
                                files: (f.files || []).map(file => ({ ...file, expanded: false }))
                            }))
                        }));
                        this.$nextTick(() => lucide.createIcons());
                    } catch (e) {
                        console.error(e);
                    } finally {
                        this.loadingTree = false;
                    }
                },

                async fetchMedia() {
                    this.loadingMedia = true;
                    try {
                        const params = new URLSearchParams({
                            q: this.searchQuery,
                            has_spanish: this.filterSpanish,
                            page: this.pagination.page,
                            per_page: this.pagination.per_page
                        });
                        const res = await fetch('/api/media?' + params.toString());
                        const data = await res.json();
                        this.mediaList = data.data || [];
                        this.pagination = data.meta || { page: 1, last_page: 1, total: 0, per_page: 50 };
                        this.$nextTick(() => lucide.createIcons());
                    } catch (e) {
                        console.error(e);
                    } finally {
                        this.loadingMedia = false;
                    }
                },

                changePage(p) {
                    this.pagination.page = p;
                    this.fetchMedia();
                },

                async openMediaModal(id) {
                    try {
                        const res = await fetch('/api/media/' + id);
                        this.activeMedia = await res.json();
                        this.mediaModalOpen = true;
                        this.$nextTick(() => lucide.createIcons());
                    } catch (e) { this.showToast('Error al cargar pistas'); }
                },

                async quickTranslate(id) {
                    this.showToast('Agregando video a la cola...');
                    try {
                        const res = await fetch(`/api/media/${id}/translate`, { method: 'POST' });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('✓ Video agregado a la cola');
                            this.fetchQueueStatus();
                            this.fetchTasks();
                        } else {
                            this.showToast('Error: ' + (data.error || 'Fallo'));
                        }
                    } catch (e) {
                        this.showToast('Error al encolar traducción');
                    }
                },

                async translateFolderBatch(folder) {
                    const pendingFiles = (folder.files || []).filter(f => !f.has_spanish);
                    const ids = pendingFiles.map(f => f.id);
                    if (ids.length === 0) return;

                    this.showToast(`Encolando ${ids.length} videos...`);
                    try {
                        const res = await fetch('/api/media/batch-translate', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ media_ids: ids })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast(`✓ ${data.queued_count} videos agregados a la cola`);
                            this.fetchQueueStatus();
                            this.fetchTasks();
                        } else {
                            this.showToast('Error al encolar lote');
                        }
                    } catch (e) {
                        this.showToast('Error al encolar lote');
                    }
                },

                async translateTrack(mediaId, trackId) {
                    this.showToast('Encolando pista...');
                    try {
                        const res = await fetch(`/api/media/${mediaId}/translate`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ track_id: trackId })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('✓ Pista agregada a la cola');
                            this.mediaModalOpen = false;
                            this.fetchQueueStatus();
                        } else {
                            this.showToast('Error: ' + data.error);
                        }
                    } catch (e) { this.showToast('Error al encolar'); }
                },

                async cancelTask(taskId) {
                    if (!taskId) return;
                    try {
                        const res = await fetch(`/api/tasks/${taskId}/cancel`, { method: 'POST' });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('✓ Tarea cancelada');
                            this.fetchQueueStatus();
                            this.fetchTree();
                            this.fetchMedia();
                        }
                    } catch (e) { this.showToast('Error al cancelar'); }
                },

                async deleteTrack(trackId) {
                    if (!confirm('¿Eliminar subtítulo?')) return;
                    try {
                        await fetch(`/api/tracks/${trackId}`, { method: 'DELETE' });
                        this.showToast('✓ Subtítulo eliminado');
                        this.fetchTree();
                        this.fetchMedia();
                    } catch (e) { this.showToast('Error'); }
                },

                async reviewTrack(trackId) {
                    if (!confirm('¿Revisar los bloques deficientes con DeepSeek?\nEsto usará la API de DeepSeek (coste puntual).')) return;
                    this.showToast('Revisando con DeepSeek...');
                    try {
                        const res = await fetch(`/api/tracks/${trackId}/review`, { method: 'POST' });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast(`✓ ${data.reviewed}/${data.total} bloques revisados`);
                            // Recargar el modal para refrescar review_pending
                            if (this.mediaModalOpen && this.activeMedia?.id) {
                                this.openMediaModal(this.activeMedia.id);
                            }
                        } else {
                            this.showToast('Error en la revisión');
                        }
                    } catch (e) { this.showToast('Error en la revisión'); }
                },

                async triggerScan() {
                    this.scanning = true;
                    this.showToast('Escaneando...');
                    try {
                        await fetch('/api/scan', { method: 'POST' });
                        this.showToast('✓ Escaneo completado');
                        this.fetchDashboard();
                        this.fetchTree();
                        this.fetchMedia();
                    } catch (e) { this.showToast('Error al escanear'); }
                    finally { this.scanning = false; }
                },

                async fetchJellyfin() {
                    try {
                        const res = await fetch('/api/jellyfin/status');
                        this.jellyfin = await res.json();
                    } catch (e) { console.error(e); }
                },

                async startJellyfinSync() {
                    this.jellyfinSyncing = true;
                    this.showToast('Sincronizando Jellyfin...');
                    try {
                        const res = await fetch('/api/jellyfin/sync', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(this.jellyfinSyncOptions)
                        });
                        this.jellyfinResult = await res.json();
                        this.showToast('✓ Sincronización lista');
                        this.fetchDashboard();
                        this.fetchTree();
                        this.fetchMedia();
                    } catch (e) { this.showToast('Error en sincronización'); }
                    finally { this.jellyfinSyncing = false; }
                },

                async fetchTasks() {
                    try {
                        const res = await fetch('/api/tasks');
                        const data = await res.json();
                        this.tasksList = data.tasks || [];
                    } catch (e) { console.error(e); }
                },

                async openSettingsModal() {
                    try {
                        const res = await fetch('/api/settings');
                        this.settingsData = await res.json();
                        this.settingsForm = {
                            provider: this.settingsData.translation.provider,
                            batch_size: this.settingsData.translation.batch_size,
                            timeout_seconds: this.settingsData.translation.timeout_seconds,
                            ollama_url: this.settingsData.translation.ollama_url,
                            ollama_model: this.settingsData.translation.ollama_model,
                            deepseek_model: this.settingsData.translation.deepseek_model,
                            deepseek_api_key: '',
                            meta_muse_model: this.settingsData.translation.meta_muse_model,
                            meta_muse_api_key: '',
                            openai_model: this.settingsData.translation.openai_model,
                            openai_base_url: this.settingsData.translation.openai_base_url,
                            openai_api_key: '',
                        };
                        this.settingsModalOpen = true;
                    } catch (e) { this.showToast('Error de ajustes'); }
                },

                async saveSettings() {
                    try {
                        await fetch('/api/settings', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(this.settingsForm)
                        });
                        this.showToast('✓ Ajustes guardados');
                        this.settingsModalOpen = false;
                        this.fetchDashboard();
                    } catch (e) { this.showToast('Error'); }
                },

                async testProvider() {
                    this.testingProvider = true;
                    try {
                        const res = await fetch('/api/settings/test-provider', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ text: 'Hello, welcome!' })
                        });
                        this.testResult = await res.json();
                    } catch (e) { this.showToast('Fallo'); }
                    finally { this.testingProvider = false; }
                }
            }
        }
    </script>
</body>
</html>
