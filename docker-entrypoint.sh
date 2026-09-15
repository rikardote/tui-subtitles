#!/bin/sh
set -e
umask 0002
cd /app

echo "=== Iniciando Subtitle Processor Web ==="

# Crear directorios de almacenamiento necesarios
mkdir -p /app/storage/database /app/storage/logs /app/storage/cache

# Inicializar o migrar la base de datos SQLite si no existe
php /app/scripts/init-db.php || true

# Iniciar Worker de colas en segundo plano
echo "=== Iniciando Worker de Cola de Traducción ==="
nohup php /app/bin/worker > /app/storage/logs/worker.log 2>&1 &

# Escaneo automático periódico (registra + analiza archivos nuevos/modificados)
SCAN_INTERVAL=${SCAN_INTERVAL_MINUTES:-15}
echo "=== Iniciando Escaneo Automático (cada ${SCAN_INTERVAL} min) ==="
(
  while true; do
    sleep $((SCAN_INTERVAL * 60))
    php /app/bin/scan --analyze >> /app/storage/logs/scan.log 2>&1
  done
) &

echo "=== Servidor Web Listo en http://0.0.0.0:8080 ==="
php -S 0.0.0.0:8080 -t /app/public /app/server.php
