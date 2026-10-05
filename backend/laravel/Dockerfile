# =============================================================================
# tahdheer-api — Laravel 13 / PHP 8.5 (php-fpm, Alpine)
# =============================================================================
# BUILD CONTEXT MUST BE THE MONOREPO ROOT (same context as the Next.js images,
# so one `docker compose build` shares it):
#
#   docker build -f backend/laravel/Dockerfile -t tahdheer-api .
#
# Verified facts (checked by pulling/inspecting, not assumed):
#   * `php:8.5-fpm-alpine` EXISTS — PHP 8.5.9 on Alpine 3.24.1.
#   * OPcache is compiled in AND enabled by default in that image
#     (`opcache.enable=1`, no .so on disk), so `docker-php-ext-enable opcache`
#     would fail. Only the tuning ini below is applied.
#   * predis/predis is NOT installed and config/database.php defaults to
#     `REDIS_CLIENT=phpredis`, so ext-redis is a hard runtime requirement for
#     cache/queue/session — it is installed here, not optional.
#   * No GD/Intervention/ZipArchive usage anywhere in app/ or config/, so gd is
#     deliberately NOT installed (it would add weight for nothing).
#
# The image is env-free by design: no .env, no APP_KEY, no config:cache at
# build time. Caches are built at container start by the entrypoint, once the
# real environment exists.
# =============================================================================

# --- stage: composer binary --------------------------------------------------
FROM composer:2 AS composer_bin

# --- stage: base runtime -----------------------------------------------------
FROM php:8.5-fpm-alpine AS base

WORKDIR /var/www/html

# Runtime shared libs stay; the -dev headers are dropped after compiling.
# fcgi provides cgi-fcgi, used by the HEALTHCHECK to speak FastCGI to the pool.
# su-exec is used by the entrypoint to drop privileges for non-fpm commands.
# poppler-utils provides pdftotext/pdftoppm: PDF page text and page images for OCR.
# tesseract-ocr + Arabic/English data read text from images and scanned PDF pages
# (OcrImageExtractor, PdfTextExtractor's scanned-page path).
RUN set -eux; \
    apk add --no-cache icu-libs libzip libpq fcgi su-exec poppler-utils \
        tesseract-ocr tesseract-ocr-data-ara tesseract-ocr-data-eng; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        bcmath \
        intl \
        zip \
        pcntl; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    pecl clear-cache; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear /usr/local/lib/php/doc; \
    php -m

# --- PHP runtime configuration ----------------------------------------------
# validate_timestamps=0 is safe because application code is immutable inside the
# image. If you ever bind-mount source over /var/www/html for debugging, set
# PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 via a mounted ini or your edits are ignored.
RUN { \
      echo 'expose_php = Off'; \
      echo 'memory_limit = 256M'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /proc/self/fd/2'; \
      echo 'date.timezone = Asia/Riyadh'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.enable_cli = 0'; \
      echo 'opcache.memory_consumption = 192'; \
      echo 'opcache.interned_strings_buffer = 16'; \
      echo 'opcache.max_accelerated_files = 20000'; \
      echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/zz-tahdheer.ini

# Loaded after zz-docker.conf (alphabetical), so it only adds the ping route
# that the HEALTHCHECK probes; listen=9000 from the base image is untouched.
RUN { \
      echo '[www]'; \
      echo 'ping.path = /ping'; \
      echo 'ping.response = pong'; \
      echo 'clear_env = no'; \
      echo 'catch_workers_output = yes'; \
      echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/zz-tahdheer.conf

COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer

# --- stage: vendor -----------------------------------------------------------
# Two-phase install: dependencies resolve from the lockfile alone (cached until
# composer.lock changes), then the autoloader is dumped against real source.
FROM base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

COPY backend/laravel/composer.json backend/laravel/composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

COPY backend/laravel/ ./

# Fails the build loudly if .dockerignore ever stops filtering secrets.
RUN test ! -e .env || { echo 'FATAL: .env leaked into the build context'; exit 1; }

# `composer dump-autoload` runs `artisan package:discover`, which boots the app.
# The build has no runtime env, so APP_ENV would default to production and the
# production-only boot guards (e.g. TapCredentials::assertConsistentAtBoot,
# "production payment provider without a secret key") would abort the build.
# Package discovery is not a production boot — it serves nothing and charges
# nothing — so it runs under APP_ENV=build. Runtime env still comes from the
# deployment, where those guards keep their full force.
RUN mkdir -p bootstrap/cache \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/app/public \
             storage/app/private \
             storage/logs \
 && APP_ENV=build composer dump-autoload --no-dev --optimize --no-interaction \
 && composer clear-cache

# --- stage: runtime ----------------------------------------------------------
FROM base AS runtime

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

# The php base image ships /var/www/html as 1777 (world-writable). Nothing in
# this image needs that, so drop it to 0755 owned by www-data.
# Only storage/ and bootstrap/cache are written at runtime.
RUN chmod 0755 /var/www/html \
 && chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

# Entrypoint: build the caches once the real environment exists, then hand off.
# Kept inline so the image has exactly one source of truth to review.
RUN set -eux; \
    { \
      echo '#!/bin/sh'; \
      echo 'set -e'; \
      echo ''; \
      echo '# A mounted volume can hide the dirs baked into the image — recreate them.'; \
      echo 'mkdir -p storage/framework/cache/data storage/framework/sessions \'; \
      echo '         storage/framework/views storage/app/public storage/app/private \'; \
      echo '         storage/logs bootstrap/cache'; \
      echo ''; \
      echo 'if [ -z "${APP_KEY:-}" ]; then'; \
      echo '  echo "[entrypoint] FATAL: APP_KEY is empty. Generate one with"  >&2'; \
      echo '  echo "[entrypoint]   docker run --rm tahdheer-api php artisan key:generate --show" >&2'; \
      echo '  echo "[entrypoint] and put it in the compose .env — it is never baked into the image." >&2'; \
      echo '  exit 1'; \
      echo 'fi'; \
      echo ''; \
      echo '# Local development (APP_ENV=local, the dev compose) runs the bind-mounted'; \
      echo '# tree live: a cached config would freeze .env and config/ edits and make'; \
      echo '# the test suite resolve to the dev database (tests/TestCase.php refuses).'; \
      echo '# So local clears stale caches; every other environment caches as before.'; \
      echo 'if [ "${APP_ENV:-production}" = "local" ]; then'; \
      echo '  php artisan config:clear >/dev/null 2>&1 || true'; \
      echo '  php artisan route:clear  >/dev/null 2>&1 || true'; \
      echo '  php artisan event:clear  >/dev/null 2>&1 || true'; \
      echo 'else'; \
      echo '  # config:cache is mandatory: without it every request re-reads config/.'; \
      echo '  php artisan config:clear >/dev/null 2>&1 || true'; \
      echo '  php artisan config:cache'; \
      echo ''; \
      echo '  # route:cache and view:cache are best-effort: both succeed on the current'; \
      echo '  # tree (the "/" closure in routes/web.php is fine — Laravel serializes'; \
      echo '  # closure routes). They stay non-fatal so a future unserializable route or'; \
      echo '  # a Blade compile error degrades performance instead of refusing to boot.'; \
      echo '  php artisan route:cache || echo "[entrypoint] WARN: route:cache failed; routes resolve per request." >&2'; \
      echo '  php artisan view:cache  || echo "[entrypoint] WARN: view:cache failed; Blade compiles on demand." >&2'; \
      echo 'fi'; \
      echo ''; \
      echo '# Migrations are NEVER run here: N containers would race. Run them once,'; \
      echo '# deliberately:  docker compose run --rm api php artisan migrate --force'; \
      echo ''; \
      echo 'chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true'; \
      echo ''; \
      echo '# php-fpm keeps a root master (it drops workers to www-data itself via'; \
      echo '# www.conf); anything else — queue:work, schedule:work, outbox:process —'; \
      echo '# is dropped to www-data here so it cannot write root-owned files into'; \
      echo '# storage/ that the fpm workers would then fail to append to.'; \
      echo 'case "$1" in'; \
      echo '  php-fpm) exec "$@" ;;'; \
      echo '  *)       exec su-exec www-data:www-data "$@" ;;'; \
      echo 'esac'; \
    } > /usr/local/bin/tahdheer-entrypoint.sh; \
    chmod +x /usr/local/bin/tahdheer-entrypoint.sh; \
    sh -n /usr/local/bin/tahdheer-entrypoint.sh

EXPOSE 9000

# Speaks real FastCGI to the pool's ping path — proves the pool answers
# requests, which a bare TCP connect does not.
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
  CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
      cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong

ENTRYPOINT ["/usr/local/bin/tahdheer-entrypoint.sh"]
CMD ["php-fpm"]
