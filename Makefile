# Raccourcis de développement. Tout s'exécute dans le conteneur : seul Docker est nécessaire sur l'hôte.
DC   = docker compose -f compose.yaml -f compose.dev.yaml
EXEC = $(DC) exec app

.DEFAULT_GOAL := help
.PHONY: help build up down logs sh console composer test quality fix prod immich-up immich-seed immich-down

help: ## Afficher cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

build: ## Construire l'image de développement
	$(DC) build

up: ## Démarrer l'environnement de développement (http://localhost:8080, HTTP_PORT pour changer)
	$(DC) up -d --wait

down: ## Arrêter l'environnement de développement
	$(DC) down --remove-orphans

logs: ## Suivre les journaux
	$(DC) logs -f app

sh: ## Ouvrir un shell dans le conteneur
	$(EXEC) sh

console: ## Lancer une commande Symfony : make console c="debug:router"
	$(EXEC) php bin/console $(c)

composer: ## Lancer Composer : make composer c="require symfony/uid"
	$(EXEC) composer $(c)

test: ## Lancer les tests
	$(EXEC) php bin/phpunit

quality: ## Style, Rector, PHPStan et Deptrac (comme la CI)
	$(EXEC) php bin/console cache:warmup --env=dev -q
	$(EXEC) composer quality

fix: ## Appliquer Rector puis le style
	$(EXEC) vendor/bin/rector process --no-progress-bar
	$(EXEC) vendor/bin/php-cs-fixer fix

prod: ## Construire l'image de production
	docker compose build

immich-up: ## Démarrer l'Immich de test (http://localhost:2283)
	docker compose -f compose.immich.yaml up -d --wait

immich-seed: ## Remplir l'Immich de test : compte, clé API, photos, vidéos et albums
	$(EXEC) php docker/immich/seed.php

immich-down: ## Arrêter l'Immich de test (ses données sont gardées)
	docker compose -f compose.immich.yaml down
