FROM serversideup/php:8.4-fpm-nginx-alpine AS app

ENV PHP_OPCACHE_ENABLE=1

USER root

# Set `www-data` as the user to start FPM
RUN echo "" >> /usr/local/etc/php-fpm.d/docker-php-serversideup-pool.conf && \
    echo "user = www-data" >> /usr/local/etc/php-fpm.d/docker-php-serversideup-pool.conf && \
    echo "group = www-data" >> /usr/local/etc/php-fpm.d/docker-php-serversideup-pool.conf

RUN install-php-extensions intl imagick

COPY --chown=www-data:www-data . .

RUN chmod -R 755 storage  \
    && mkdir -p bootstrap/cache \
    && chmod -R 775 bootstrap/cache \
    && mkdir -p /var/lib/nginx/tmp \
    && chown -R www-data:www-data /var/lib/nginx \
    && chmod -R 755 /var/lib/nginx

RUN composer install \
    --ignore-platform-reqs \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist

RUN mkdir -p /var/www/html/vendor/ezyang/htmlpurifier/library/HTMLPurifier/DefinitionCache/Serializer \
    && chmod -R 775 /var/www/html/vendor/ezyang/htmlpurifier/library/HTMLPurifier/DefinitionCache/Serializer \
    && chown -R www-data:www-data /var/www/html/vendor/ezyang/htmlpurifier/library/HTMLPurifier/DefinitionCache/Serializer

EXPOSE 8080

# ── Worker stage (queue:work, sin nginx) ──────────────────────────────────
FROM app AS worker
# Estos contenedores no tienen web server, así que el healthcheck HTTP heredado
# de la imagen base falla siempre. Coolify exige un estado de salud para su rollout
# (HEALTHCHECK NONE lo rompe), por eso damos uno que siempre pasa: la liveness real
# la cubre el proceso, que es el PID 1 del contenedor (si muere, el contenedor muere).
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 CMD true
ENTRYPOINT ["/bin/sh"]
CMD ["scripts/deploy/run-worker.sh"]

# ── Scheduler stage (schedule:run loop, sin nginx) ────────────────────────
FROM app AS scheduler
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 CMD true
ENTRYPOINT ["/bin/sh"]
CMD ["scripts/deploy/run-scheduler.sh"]
