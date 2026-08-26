# syntax=docker/dockerfile:1

ARG APP_IMAGE=reservahub-app:local

FROM ${APP_IMAGE} AS app

FROM nginx:alpine AS runtime

RUN rm /etc/nginx/conf.d/default.conf

COPY docker/production/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/nginx/default.conf /etc/nginx/conf.d/default.conf

# Solo el document root: el resto del código de la aplicación no tiene por
# qué existir dentro del contenedor web.
COPY --from=app /var/www/html/public /var/www/html/public

ARG APP_VERSION="dev"
ARG VCS_REF="unknown"
LABEL org.opencontainers.image.title="reservahub-web" \
      org.opencontainers.image.version="$APP_VERSION" \
      org.opencontainers.image.revision="$VCS_REF" \
      org.opencontainers.image.source="https://github.com/Gonzalez-Luciano/reservahub" \
      org.opencontainers.image.licenses="MIT"

EXPOSE 8080

CMD ["nginx", "-g", "daemon off;"]
