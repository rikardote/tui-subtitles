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

echo "=== Servidor Web Listo en http://0.0.0.0:8080 ==="
php -S 0.0.0.0:8080 -t /app/public /app/server.php
