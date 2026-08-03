FROM php:8.4-cli

# php-tui renders through ANSI escape sequences, so no terminal extension is
# needed. ext-intl is its only hard requirement (grapheme-aware width).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev unzip \
    && docker-php-ext-install -j"$(nproc)" intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV LC_ALL=C.UTF-8
ENV LANG=C.UTF-8
