# syntax=docker/dockerfile:1
<?= $upstream ?>

<?php if ($usesPie): ?>
FROM ghcr.io/php/pie:<?= PIE_VERSION ?>-bin AS pie

<?php endif ?>
FROM php-base AS extensions
ARG DEBIAN_FRONTEND=noninteractive
ARG BUILD_JOBS=8
<?php if ($usesPie): ?>
# PIE is mounted into each recipe step; it is not part of the image.
ARG PIE_INSTALL="pie install --no-interaction --no-build-tools-check --no-system-dependencies-check --make-parallel-jobs ${BUILD_JOBS}"
<?php endif ?>

RUN apt-get update && apt-get install --no-install-recommends -y git msmtp ccache unzip \
    && rm -rf /var/lib/apt/lists/*

<?php foreach ($recipes as $name => $recipe): ?>
# Extension: <?= $name . "\n" ?>
RUN --mount=type=cache,id=php-<?= $branch ?>-ccache,target=/root/.cache/ccache,sharing=locked \
<?php if ($usesPie): ?>
    --mount=type=bind,from=pie,source=/pie,target=/usr/local/bin/pie \
<?php endif ?>
    set -eux; \
    export CC="ccache gcc" CXX="ccache g++" MAKEFLAGS="-j${BUILD_JOBS}"; \
    apt-get update; \
    <?= $recipe ?> \
    && rm -rf /var/lib/apt/lists/* /tmp/pear /root/.pie /root/.config/pie /root/.cache/composer

<?php endforeach ?>
COPY mark-runtime-dependencies.sh /usr/local/bin/mark-runtime-dependencies
# Record the packages the final image needs: what the upstream recipe and the modules link against,
# plus the tools installed on purpose, minus the compiler toolchain, which stays in this stage only.
RUN sh /usr/local/bin/mark-runtime-dependencies \
    && printf '%s\n' $PHPIZE_DEPS libc6-dev ccache > /tmp/build-only-packages.txt \
    && apt-mark showmanual | grep -vxF -f /tmp/build-only-packages.txt | sort > /usr/local/share/php-runtime-packages.txt \
    && cat /usr/local/share/php-runtime-packages.txt

# Final image: the same Debian release as the recipe, runtime packages only, PHP copied from the build.
FROM <?= $baseImage ?> AS image
ARG DEBIAN_FRONTEND=noninteractive
ARG APT_REFRESH=cached
<?= $upstreamEnv . "\n" ?>

COPY --from=extensions /usr/local/share/php-runtime-packages.txt /usr/local/share/php-runtime-packages.txt
RUN echo "Package refresh: $APT_REFRESH" && apt-get update && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends $(cat /usr/local/share/php-runtime-packages.txt) \
    && rm -rf /var/lib/apt/lists/*
COPY --from=extensions /usr/local /usr/local
COPY --from=extensions /opt/xhprof /opt/xhprof
ENTRYPOINT ["docker-php-entrypoint"]
STOPSIGNAL SIGQUIT
EXPOSE 9000
CMD ["php-fpm"]

# The only configuration the image adds: mail() goes through msmtp with a command from the environment.
RUN printf '%s\n' 'sendmail_path = "eval $SENDMAIL_COMMAND"' > /usr/local/etc/php/conf.d/msmtp.ini

# Composer, resolved to the current stable release at generation time and verified by checksum.
# git and unzip stay for Composer. COMPOSER_HOME lives in the ephemeral /tmp so Composer works for
# any uid without the image providing a home; mount or override it for a persistent cache.
RUN set -eux; \
    curl -fsSL "https://getcomposer.org/download/<?= $composer['version'] ?>/composer.phar" -o /usr/local/bin/composer; \
    echo "<?= $composer['sha256'] ?>  /usr/local/bin/composer" | sha256sum -c -; \
    chmod +x /usr/local/bin/composer; \
    composer --version
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1

# `idle` keeps a container alive for exec, cron or consumers: `command: idle`.
COPY files/idle /usr/local/bin/idle

# Image manifest: loads every compiled module and records PHP, extension versions and provenance.
COPY files/image-manifest.php /usr/local/share/php/image-manifest.php
COPY <<'IMAGE_JSON' /usr/local/share/php/image.json
<?= $imageJson . "\n" ?>
IMAGE_JSON
RUN php /usr/local/share/php/image-manifest.php /usr/local/share/php/image.json > /usr/local/share/php-image-manifest.json \
    && rm /usr/local/share/php/image.json /usr/local/share/php/image-manifest.php \
    && cat /usr/local/share/php-image-manifest.json

ARG SOURCE_REVISION=unknown
ARG BUILD_DATE=unknown
LABEL org.opencontainers.image.title="ecommpro/php" \
      org.opencontainers.image.version="<?= $phpVersion ?>" \
      org.opencontainers.image.source="https://github.com/admibox/php-docker-images" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      pro.ecomm.php.branch="<?= $branch ?>" \
      pro.ecomm.php.patches="<?= $patches ? $patches['vendor'] . ' ' . $patches['package_version'] : 'none' ?>" \
      pro.ecomm.php.manifest="/usr/local/share/php-image-manifest.json"

RUN mkdir -p /app
WORKDIR /app
