# Carbure: household budget web portal and API, with woob bundled
# https://github.com/ltoinel/Carbure
#
# Alpine image: the build tools (compilers, headers) stay in the first stage and in
# temporary packages, the final image only keeps what runs Carbure.

# --- woob and its Python dependencies, built apart --------------------------------
FROM php:8.5-fpm-alpine AS woob

ARG WOOB_VERSION=3.7

# Compilers and headers in case a dependency has no wheel for musl (Alpine)
# hadolint ignore=DL3018
RUN apk add --no-cache python3 python3-dev build-base libffi-dev libxml2-dev libxslt-dev \
        jpeg-dev zlib-dev

# woob and curl_cffi (required by the BNP module) in a virtual environment
# (pip and setuptools of the venv are upgraded: older versions have known vulnerabilities)
RUN python3 -m venv /opt/woob \
    && /opt/woob/bin/pip install --no-cache-dir --upgrade "pip>=26.1.2" "setuptools>=78.1.1" \
    && /opt/woob/bin/pip install --no-cache-dir "woob==${WOOB_VERSION}" "curl_cffi>=0.7" \
    && /opt/woob/bin/python -c "import curl_cffi, lxml, woob" \
    && find /opt/woob -name "__pycache__" -type d -prune -exec rm -rf {} +

# --- Carbure ---------------------------------------------------------------------
FROM php:8.5-fpm-alpine

# Version of Carbure (the release tag), shown in the portal footer and by /api/health
ARG CARBURE_VERSION=dev

LABEL org.opencontainers.image.title="Carbure" \
      org.opencontainers.image.version="${CARBURE_VERSION}" \
      org.opencontainers.image.description="Household budget web portal and API, bank synchronization with woob" \
      org.opencontainers.image.source="https://github.com/ltoinel/Carbure" \
      org.opencontainers.image.licenses="MIT"

# nginx, Python for woob (with the libraries of its compiled dependencies), gpgv (woob
# checks the signature of the bank modules it downloads: without it, they are installed
# unchecked), and the PHP extensions mysqli and APCu (built with temporary packages,
# removed afterwards);
# the packages of the base image are upgraded to the security fixes released since
# hadolint ignore=DL3017,DL3018
RUN apk upgrade --no-cache \
    && apk add --no-cache nginx python3 curl ca-certificates gpgv libffi libxml2 libxslt libjpeg-turbo zlib libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install mysqli \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .build-deps \
    && rm -rf /tmp/pear \
    && mkdir -p /run/nginx \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

COPY --from=woob /opt/woob /opt/woob
# The compiled dependencies find their libraries in this image
RUN ln -s /opt/woob/bin/woob /usr/local/bin/woob \
    && /opt/woob/bin/python -c "import curl_cffi, lxml.etree, woob"

# nginx serves the portal and Swagger, PHP-FPM runs the API
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/carbure.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-carbure.conf

WORKDIR /var/www/carbure
COPY --chown=www-data:www-data . .

# Data of the instance in the volume /data, seen by Carbure as data/:
# conf/ (prod.ini written by the installation wizard), logs/, cache/, woob/
RUN echo "${CARBURE_VERSION#v}" > VERSION \
    && ln -s /data /var/www/carbure/data \
    && chmod +x docker/entrypoint.sh

# Command run by the synchronization to call woob (its settings live in /data)
ENV CARBURE_WOOB_PATH="env HOME=/data/woob woob"

VOLUME /data
EXPOSE 80

# Every 5 minutes; every 10 s while the container starts (Docker 25+, ignored before)
HEALTHCHECK --interval=5m --timeout=5s --start-period=2m --start-interval=10s --retries=3 \
    CMD ["curl", "-fsS", "-o", "/dev/null", "http://localhost/api/health"]

ENTRYPOINT ["/var/www/carbure/docker/entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
