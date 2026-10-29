FROM php@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e
RUN docker-php-ext-install pdo_mysql
COPY src/axiompress /app
COPY tools/axiompress/native/render.php /app/render-component.php
USER 10001:10001
WORKDIR /app/public
EXPOSE 8080
# Development HTTP adapter only. Production will use FPM behind a dedicated trusted HTTP server.
ENTRYPOINT ["php", "-n", "-d", "extension=pdo_mysql", "-d", "display_errors=0", "-d", "log_errors=1", "-d", "memory_limit=64M", "-S", "0.0.0.0:8080", "/app/public/index.php"]
