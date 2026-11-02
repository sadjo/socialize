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

while true; do
	# shellcheck disable=SC2086
	php bin/console messenger:consume $TRANSPORTS \
		--time-limit=3600 \
		--memory-limit=256M \
		--no-interaction \
		--quiet || {
		echo "[messenger-$WORKER_ID] arrêt inattendu, relance dans 5 s" >&2
		sleep 5
	}
done
