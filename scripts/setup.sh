#!/usr/bin/env bash
# Instala las dependencias del sistema para Subtitle Processor (Debian/Ubuntu).
# Requiere sudo para los paquetes del sistema.
set -euo pipefail

echo "== Instalando paquetes del sistema (PHP, FFmpeg) =="
sudo apt-get update
sudo apt-get install -y php8.3-cli php8.3-mbstring php8.3-sqlite3 php8.3-curl \
    php8.3-xml php8.3-zip unzip ffmpeg curl

echo "== Instalando Composer =="
if ! command -v composer >/dev/null 2>&1; then
    php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    php /tmp/composer-setup.php --install-dir="$HOME/.local/bin" --filename=composer
fi

echo "== Instalando deep-translator (traducción gratuita) =="
if ! command -v deep-translator >/dev/null 2>&1; then
    pip3 install --user deep-translator 2>/dev/null \
        || python3 -m pip install --user deep-translator
fi

echo "== Verificando =="
php -v | head -1
ffmpeg -version | head -1
ffprobe -version | head -1
command -v deep-translator && echo "deep-translator OK"

echo
echo "Instalación completada. Ahora ejecute:"
echo "  composer install"
echo "  cp .env.example .env   # y edite las rutas multimedia"
