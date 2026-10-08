FROM php:8.3-cli

WORKDIR /var/www

# zip: Composer la necesita para descomprimir paquetes (--prefer-dist)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

# Las variables reales llegan por env_file; el .env vacío evita avisos de Laravel
RUN touch .env

RUN composer install --no-interaction --prefer-dist

EXPOSE 8000

# Aplica migraciones pendientes y levanta el servidor.
# --no-reload: serve usa las variables de Docker (env_file) en vez de releer el .env vacío
CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000 --no-reload"]
