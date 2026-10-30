FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e
RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps
COPY src/axiompress /app
COPY tools/axiompress/jobs/run.php /runner/run.php
COPY tools/axiompress/media/control.php /runner/media-control.php
USER 10001:10001
LABEL dev.axiompress.jobs.controller="1"
ENTRYPOINT ["php", "-d", "display_errors=0", "-d", "memory_limit=64M", "/runner/run.php"]
