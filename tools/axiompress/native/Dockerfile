FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e
RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && apk del .build-deps
COPY src/axiompress /app
COPY tools/axiompress/native/render.php /app/render-component.php
USER 10001:10001
WORKDIR /app/public
EXPOSE 8080
# Development HTTP adapter only. Production will use FPM behind a dedicated trusted HTTP server.
ENTRYPOINT ["php", "-n", "-d", "extension=pdo_mysql", "-d", "extension=pdo_pgsql", "-d", "display_errors=0", "-d", "log_errors=1", "-d", "memory_limit=64M", "-S", "0.0.0.0:8080", "/app/public/index.php"]
