# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — dependencias de Composer, sin las de desarrollo.
# --no-dev deja fuera Scramble (la doc OpenAPI), Sail, Pint, PHPUnit y
# Collision, ninguno de los cuales debe existir en producción.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# --no-scripts: los scripts post-autoload-dump corren `artisan package:discover`,
# que necesita el código de la aplicación, que todavía no está copiado.
# --ignore-platform-req=ext-bcmath: la imagen `composer:2` no trae bcmath
# compilado (solo se usa acá para resolver/descargar el lock, nunca para
# ejecutar la app); el runtime sí lo instala vía docker-php-ext-install.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --ignore-platform-req=ext-bcmath

# ---------------------------------------------------------------------------
# Stage 2 — bundle del frontend. Node y pnpm existen SOLO acá.
# ---------------------------------------------------------------------------
FROM node:24-alpine AS frontend

WORKDIR /app

# Públicas por definición: todo lo que empieza con VITE_ se compila dentro del
# bundle. Nunca poner un secreto acá. Los defaults son el dominio aprobado en
# 01-reservahub.md §12.1; cambiar de host exige reconstruir la imagen.
ARG VITE_APP_NAME="ReservaHub"
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST="reservahub.lucianogonzalez.dev"
ARG VITE_REVERB_PORT="443"
ARG VITE_REVERB_SCHEME="https"
ARG VITE_DEMO_MAIL_URL="https://reservahub-mail.lucianogonzalez.dev"

ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME \
    VITE_DEMO_MAIL_URL=$VITE_DEMO_MAIL_URL

RUN corepack enable

COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY vite.config.js ./
COPY resources ./resources

RUN pnpm build

# ---------------------------------------------------------------------------
# Stage 3 — runtime. PHP-FPM y nada más: ni Node, ni pnpm, ni node_modules,
# ni dependencias de desarrollo, ni .env.
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-alpine AS runtime

WORKDIR /var/www/html

# linux-headers lo pide pecl redis; los -dev se instalan como dependencia
# virtual y se borran en la misma capa para no engordar la imagen.
#
# opcache NO va en docker-php-ext-install: su config.m4 en esta versión de PHP
# registra la extensión con ext_shared="no" (PHP_NEW_EXTENSION([opcache], ...,
# [no], ...)) — no puede compilarse como módulo .so, a diferencia del resto de
# extensiones de esta lista. No hace falta: php:8.5-fpm-alpine ya trae Zend
# OPcache compilado de forma estática (`php -v` lo muestra sin ningún paso
# extra); zz-reservahub.ini solo lo configura, no lo habilita.
RUN apk add --no-cache postgresql-libs icu-libs libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev linux-headers \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        pcntl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/production/php/php.ini /usr/local/etc/php/conf.d/zz-reservahub.ini

# Reemplaza (no suma) el www.conf de la imagen base: php-fpm.d/*.conf se
# incluye completo, y el www.conf de base ya trae `user`/`group` seteados en
# el mismo pool [www]. Si el nuestro se agregara aparte (p. ej. zz-www.conf),
# ambos archivos fusionan directivas del mismo pool y `user`/`group` quedarían
# heredados de todos modos, con el warning que este archivo busca evitar.
COPY docker/production/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/production/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

# php:8.5-fpm-alpine no trae composer; se copia el binario del stage de vendor.
COPY --from=vendor /usr/bin/composer /usr/bin/composer

# package:discover no pudo correr en el stage de Composer porque faltaba el
# código; ahora sí está todo. Arranca la aplicación completa (para registrar
# providers y comandos), y AppServiceProvider::register() falla cerrado si
# PAYMENTS_SIMULATED_WEBHOOK_SECRET no está seteado (Fase 9: sin eso firmaría
# webhooks con una clave HMAC vacía). En build time no existe el secreto real
# del operador todavía, así que se le pasa un valor de relleno SOLO a este
# RUN — no es un ENV de imagen, no persiste en la capa final ni en runtime; el
# valor real del operador lo pisa por completo vía el entorno del contenedor.
RUN PAYMENTS_SIMULATED_WEBHOOK_SECRET=build-time-placeholder-not-a-real-secret \
    composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Identificación por release y por commit (§12.5).
ARG APP_VERSION="dev"
ARG VCS_REF="unknown"
ENV APP_VERSION=$APP_VERSION
LABEL org.opencontainers.image.title="reservahub-app" \
      org.opencontainers.image.version="$APP_VERSION" \
      org.opencontainers.image.revision="$VCS_REF" \
      org.opencontainers.image.source="https://github.com/Gonzalez-Luciano/reservahub" \
      org.opencontainers.image.licenses="MIT"

USER www-data

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
