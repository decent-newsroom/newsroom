#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# Install the project the first time PHP is started
	# After the installation, the following block can be deleted
	if [ ! -f composer.json ]; then
		rm -Rf tmp/
		composer create-project "symfony/skeleton $SYMFONY_VERSION" tmp --stability="$STABILITY" --prefer-dist --no-progress --no-interaction --no-install

		cd tmp
		cp -Rp . ..
		cd -
		rm -Rf tmp/

		composer require "php:>=$PHP_VERSION" runtime/frankenphp-symfony
		composer config --json extra.symfony.docker 'true'

		if grep -q ^DATABASE_URL= .env; then
			echo 'To finish the installation please press Ctrl+C to stop Docker Compose and run: docker compose up --build -d --wait'
			sleep infinity
		fi
	fi

	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction --no-scripts
		# Run auto-scripts separately after filesystem has settled.
		# This avoids transient I/O errors on bind mounts (Windows/Mac)
		# when cache:clear runs while composer is still writing files.
		sync 2>/dev/null || true
		# Re-dump the autoloader WITH plugin events so symfony/runtime
		# generates vendor/autoload_runtime.php (--no-scripts above
		# suppresses the POST_AUTOLOAD_DUMP event that creates it).
		composer dump-autoload --no-interaction
		composer run-script auto-scripts --no-interaction || true
	fi

	# Compile AssetMapper assets — production only.
	# In dev mode the Symfony kernel serves assets dynamically, which is
	# required for the stimulus-bundle compiler to generate the full
	# controller map.  Pre-compiled assets in public/assets/ override that
	# and can easily become stale / truncated (the ui/ and utility/
	# controllers were silently dropped once).
	if [ "$APP_ENV" = 'prod' ] && [ -f "bin/console" ]; then
		echo '================================'
		echo 'Compiling AssetMapper assets...'
		echo '================================'
		mkdir -p public/assets
		if php bin/console asset-map:compile --no-interaction; then
			echo '✅ Assets compiled successfully!'
			ls -la public/assets/manifest.json 2>/dev/null || echo '⚠️  Warning: manifest.json not found after compilation'
		else
			echo '❌ Asset compilation failed!'
			echo 'Checking if AssetMapper is installed...'
			php bin/console list | grep asset-map || echo 'AssetMapper commands not available'
		fi
		echo '================================'
	elif [ "$APP_ENV" != 'prod' ] && [ -d "public/assets" ]; then
		echo 'Dev mode: removing pre-compiled assets so Symfony serves them dynamically...'
		rm -rf public/assets
	fi


	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		# Use a lightweight raw PDO check instead of booting the full
		# Symfony kernel via bin/console on every attempt.  On Windows/Mac
		# bind mounts a single kernel boot can take 10+ seconds; the raw
		# PHP script finishes in < 1 s.
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php docker/wait-for-db.php 2>&1); do
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		if [ "$( find ./migrations -iname '*.php' -print -quit )" ]; then
			# Quick raw-SQL count check (< 1 s) to avoid a full Symfony
			# kernel boot when all migrations are already applied.
			if php docker/check-migrations.php 2>/dev/null; then
				echo 'Migrations up to date.'
			else
				echo 'Running database migrations...'
				php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
			fi
		fi
	fi

	# Ensure var/ directories exist with correct ACL defaults.
	# Only set ACLs on the directory entries themselves — default ACLs
	# (-d) make new files/dirs inherit the permissions automatically.
	# The previous recursive walk (setfacl -R) traversed every file in
	# var/cache/ on each startup, which is extremely slow on bind mounts.
	mkdir -p var/cache var/log
	setfacl -m u:www-data:rwX -m u:"$(whoami)":rwX var var/cache var/log
	setfacl -d -m u:www-data:rwX -m u:"$(whoami)":rwX var var/cache var/log
fi

exec docker-php-entrypoint "$@"
