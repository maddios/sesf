# Dev PHP image for sesf-new: PHP 8.5 CLI with the extensions the app needs.
FROM php:8.5-cli

# Composer, copied from the official composer:2 image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# git + unzip for composer, curl to fetch the extension installer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# mlocati/docker-php-extension-installer: installs PHP extensions and their system libs.
RUN curl -sSLf -o /usr/local/bin/install-php-extensions \
        https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions \
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
