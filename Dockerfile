FROM php:8.5-alpine

RUN apk update \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers

RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    pie install --no-cache xdebug/xdebug

RUN apk del .build-deps

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
