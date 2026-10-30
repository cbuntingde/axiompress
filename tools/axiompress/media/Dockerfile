FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e
RUN apk add --no-cache libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd \
    && apk del .build-deps
COPY tools/axiompress/media/processor.php /processor.php
LABEL dev.axiompress.media.processor="1"
USER 10001:10001
ENTRYPOINT ["php", "-n", "-d", "extension=gd", "-d", "display_errors=0", "-d", "memory_limit=96M", "/processor.php"]
