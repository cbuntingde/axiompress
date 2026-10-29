FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e AS worker-sdk
COPY protocol /app/protocol
USER 10001:10001
ENTRYPOINT ["php", "-n", "-d", "memory_limit=96M", "/app/worker.php"]
CMD []

FROM worker-sdk AS worker
COPY supervisor/worker.php /app/worker.php

FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e AS supervisor
RUN apk add --no-cache docker-cli \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pcntl \
    && apk del .build-deps
COPY protocol /app/protocol
COPY broker /app/broker
COPY supervisor /app/supervisor
COPY policy /app/policy
COPY packages /app/packages
ENTRYPOINT ["php", "/app/supervisor/runtime-test.php"]
CMD []
