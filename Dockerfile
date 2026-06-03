# Versions
# Pin to a specific digest
FROM dunglas/frankenphp:1.12.3-php8 AS frankenphp_upstream

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/develop/develop-images/multistage-build/#stop-at-a-specific-build-stage
# https://docs.docker.com/compose/compose-file/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

# persistent / runtime deps
# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends \
	acl \
	ca-certificates \
	curl \
	gettext \
	&& rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		zip \
        gmp \
        gd \
        redis \
        pcntl \
	;

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN install-php-extensions pdo pdo_pgsql
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=180s --interval=10s --timeout=5s --retries=5 \
    CMD curl -f http://localhost/up || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
	install-php-extensions \
		xdebug \
	;

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
ENV DATABASE_URL="postgresql://app:app@database:5432/app?serverVersion=16&charset=utf8"
ARG SWC_VERSION=v1.3.92

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

RUN set -eux; \
	arch="$(uname -m)"; \
	case "$arch" in \
		x86_64|amd64) swc_target="swc-linux-x64-gnu" ;; \
		aarch64|arm64) swc_target="swc-linux-arm64-gnu" ;; \
		*) echo "Unsupported SWC architecture: $arch" >&2; exit 1 ;; \
	esac; \
	curl -fL \
		--retry 5 \
		--retry-delay 5 \
		--retry-all-errors \
		--connect-timeout 20 \
		--max-time 300 \
		-o /usr/local/bin/swc \
		"https://github.com/swc-project/swc/releases/download/${SWC_VERSION}/${swc_target}"; \
	chmod +x /usr/local/bin/swc; \
	/usr/local/bin/swc --version

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./

RUN --mount=type=cache,target=/tmp/composer-cache \
	set -eux; \
	COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
		--no-dev \
		--prefer-dist \
		--no-progress \
		--no-interaction \
		--no-scripts \
		--no-autoloader

# copy sources
COPY --link . ./
RUN rm -Rf frankenphp/

RUN set -eux; \
	mkdir -p var/cache var/log; \
	composer dump-env prod --empty; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer run-script --no-dev post-install-cmd; \
	chmod +x bin/console; \
	php bin/console cache:clear --env=prod --no-debug; \
	php bin/console asset-map:compile --env=prod --no-interaction
