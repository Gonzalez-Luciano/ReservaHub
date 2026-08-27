#!/bin/sh
set -e

# Espera a que la base acepte conexiones. Sin esto, en un `up` en frío los
# cuatro contenedores de aplicación reinician en bucle durante el arranque de
# PostgreSQL y ensucian los logs.
if [ -n "${DB_HOST:-}" ]; then
    tries=0
    until php -r "new PDO('pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 5432).';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "La base de datos no respondió tras 30 intentos." >&2
            exit 1
        fi
        sleep 2
    done
fi

# Las cachés se construyen en runtime, no en la imagen: config:cache congela
# los valores de entorno del momento en que corre, y en la imagen todavía no
# existen los del operador.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
