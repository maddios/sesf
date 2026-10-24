# Dev PHP image for sesf-new: PHP 8.5 CLI with the extensions the app needs.
#
# All versions are pinned exactly. Bump them deliberately after checking
# advisories (php.net release notes, GitHub advisory DB for composer).
#
# docker-compose runs containers as uid 1000 (user: "1000:1000"), so this
# image must not rely on root-owned writable paths: COMPOSER_HOME is set to
# /tmp/composer in docker-compose.yml, and the app tree is bind-mounted.
FROM php:8.5.12-cli

# Composer 2.10.3 (fixes GHSA-96h3-5x6v-m776, GHSA-rvx4-ffvw-m9q3,
# GHSA-gjfg-22fp-rrxx, GHSA-g6xq-892h-64w3, GHSA-499r-g7pc-vmp9).
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

# git + unzip are needed by composer for dist/source downloads.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# mlocati/docker-php-extension-installer 2.12.3: installs PHP extensions and
# their system libraries. Copied from the image rather than fetched by URL.
COPY --from=mlocati/php-extension-installer:2.12.3 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        imagick \
        gd \
        intl \
        pdo_mysql \
        bcmath \
        zip \
        pcntl \
        exif \
        opcache \
        pcov \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
