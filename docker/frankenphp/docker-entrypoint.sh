#!/bin/sh
# Point d'entrée du conteneur Socialize.
# Prépare l'application (dépendances en dev, migrations, transports Messenger),
# lance les consommateurs Messenger en arrière-plan, puis exécute la commande.
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# En développement, le code est monté depuis l'hôte : installer les dépendances si besoin.
	if [ "$APP_ENV" != 'prod' ]; then
		if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
			composer install --prefer-dist --no-progress --no-interaction
		fi
		if [ ! -d 'assets/vendor' ]; then
			php bin/console importmap:install --no-interaction
		fi
	fi

	if [ "$APP_ENV" = 'prod' ] && [ -z "$APP_SECRET" ]; then
		echo "Erreur : la variable APP_SECRET est obligatoire en production." >&2
		echo "Générez-en une avec : openssl rand -hex 32" >&2
		exit 1
	fi

	mkdir -p /data /media var/cache var/log

	# Attendre la base de données (utile avec MariaDB/MySQL lancée à côté).
	ATTEMPTS_LEFT=60
	until php bin/console dbal:run-sql -q "SELECT 1" > /dev/null 2>&1; do
		ATTEMPTS_LEFT=$((ATTEMPTS_LEFT - 1))
		if [ "$ATTEMPTS_LEFT" -le 0 ]; then
			echo "Erreur : la base de données ne répond pas." >&2
			php bin/console dbal:run-sql "SELECT 1"
			exit 1
		fi
		echo "En attente de la base de données... ($ATTEMPTS_LEFT essais restants)"
		sleep 1
	done

	# Copie de sécurité de la base SQLite avant d'appliquer de nouvelles migrations.
	case "$DATABASE_URL" in
		sqlite:*)
			DB_FILE="/${DATABASE_URL#sqlite:////}"
			if [ -f "$DB_FILE" ] && ! php bin/console doctrine:migrations:up-to-date -q > /dev/null 2>&1; then
				BACKUP="$DB_FILE.$(date +%Y%m%d-%H%M%S).bak"
				cp "$DB_FILE" "$BACKUP"
				echo "Copie de sécurité de la base : $BACKUP"
			fi
			;;
	esac

	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
	php bin/console messenger:setup-transports --no-interaction

	if [ "$1" = 'frankenphp' ] && [ "${WORKERS:-1}" -gt 0 ]; then
		i=1
		while [ "$i" -le "$WORKERS" ]; do
			messenger-supervisor "$i" &
			i=$((i + 1))
		done
	fi
fi

exec docker-php-entrypoint "$@"
