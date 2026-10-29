FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e AS migration
RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps
COPY tools/axiompress/database /app
USER 10001:10001
ENTRYPOINT ["php", "/app/migrate.php"]

FROM migration AS verification
COPY tests/axiompress/database-test.php /checks/database-test.php
ENTRYPOINT ["php", "/checks/database-test.php"]
