#!/bin/sh
# Supervision minimale d'un consommateur Symfony Messenger dans le conteneur :
# le relance s'il s'arrête et le recycle toutes les heures pour libérer la mémoire.
WORKER_ID="${1:-1}"
# Seul le premier consommateur traite le planificateur, pour ne pas lancer deux fois les tâches périodiques.
if [ "$WORKER_ID" = '1' ]; then
	TRANSPORTS="${MESSENGER_TRANSPORTS:-async scheduler_default}"
else
	TRANSPORTS="${MESSENGER_TRANSPORTS:-async}"
fi

# Mémoire du worker : l'analyse d'une archive Instagram complète décode des fichiers JSON de plusieurs
# dizaines de Mo (60 Mo en mémoire pour un posts.json de 11 Mo), au-delà des 128 Mo par défaut de PHP.
# --memory-limit recycle le worker après un message s'il dépasse ce seuil, sous la limite de PHP.
MEMORY_LIMIT="${MESSENGER_MEMORY_LIMIT:-512M}"

while true; do
	# shellcheck disable=SC2086
	php -d memory_limit="$MEMORY_LIMIT" bin/console messenger:consume $TRANSPORTS \
		--time-limit=3600 \
		--memory-limit=384M \
		--no-interaction \
		--quiet || {
		echo "[messenger-$WORKER_ID] arrêt inattendu, relance dans 5 s" >&2
		sleep 5
	}
done
