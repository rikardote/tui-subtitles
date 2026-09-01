FROM php:8.3-cli-alpine

# Instalar dependencias del sistema (FFmpeg, FFprobe, SQLite, cURL)
RUN apk add --no-cache \
    ffmpeg \
    sqlite \
    sqlite-dev \
    curl \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev

# Instalar extensiones de PHP necesarias
RUN docker-php-ext-install \
    pdo_sqlite \
    mbstring \
    pcntl \
    opcache

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /app

# Copiar archivos de dependencias e instalar
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copiar el código fuente completo
COPY . .

# Permisos de ejecución para scripts
RUN chmod +x docker-entrypoint.sh bin/*

# Exponer puerto HTTP
EXPOSE 8080

# Punto de entrada
ENTRYPOINT ["/app/docker-entrypoint.sh"]
