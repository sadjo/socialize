#!/bin/sh
# Socialize installer — https://github.com/sadjo/socialize
#
#   curl -fsSL https://raw.githubusercontent.com/sadjo/socialize/main/install.sh | sh
#
# Non-interactive:
#   curl -fsSL https://raw.githubusercontent.com/sadjo/socialize/main/install.sh | sh -s -- \
#     --domain photos.example.org --email admin@example.org --username admin --yes
#
# The script only needs Docker (with the Compose plugin). It writes a compose.yaml and a docker.env file in the
# installation directory, starts the containers and creates the administrator account. Running it again on an
# existing installation keeps the existing secrets and data and upgrades the containers.

set -eu

IMAGE="${SOCIALIZE_IMAGE:-ghcr.io/sadjo/socialize}"
VERSION="${SOCIALIZE_VERSION:-latest}"
DIR="${SOCIALIZE_DIR:-$HOME/socialize}"
DOMAIN=""
DB="sqlite"
PROXY=0
HTTP_PORT=""
ADMIN_EMAIL=""
ADMIN_USERNAME=""
ADMIN_PASSWORD=""
ASSUME_YES=0

usage() {
	cat <<'EOF'
Socialize installer

Usage: install.sh [options]

  --domain NAME        Public domain name of the instance (e.g. photos.example.org)
  --dir PATH           Installation directory (default: ~/socialize)
  --db sqlite|mariadb  Database (default: sqlite)
  --behind-proxy       A reverse proxy already handles HTTPS: serve plain HTTP on --http-port
  --http-port PORT     HTTP port published on the host (default: 80, or 8080 with --behind-proxy)
  --version TAG        Image tag to install (default: latest)
  --email ADDRESS      Administrator e-mail address
  --username NAME      Administrator username
  --password VALUE     Administrator password (default: prompted, or generated with --yes)
  --yes                Never ask questions (use defaults and generated values)
  -h, --help           Show this help

Environment: SOCIALIZE_IMAGE, SOCIALIZE_VERSION, SOCIALIZE_DIR.
EOF
}

say() { printf '\033[1;35m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarning:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
	case "$1" in
		--domain) DOMAIN="${2:?}"; shift 2 ;;
		--dir) DIR="${2:?}"; shift 2 ;;
		--db) DB="${2:?}"; shift 2 ;;
		--behind-proxy) PROXY=1; shift ;;
		--http-port) HTTP_PORT="${2:?}"; shift 2 ;;
		--version) VERSION="${2:?}"; shift 2 ;;
		--email) ADMIN_EMAIL="${2:?}"; shift 2 ;;
		--username) ADMIN_USERNAME="${2:?}"; shift 2 ;;
		--password) ADMIN_PASSWORD="${2:?}"; shift 2 ;;
		--yes|-y) ASSUME_YES=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; die "unknown option: $1" ;;
	esac
done

# Questions are read from the terminal, even when the script itself comes from a pipe (curl … | sh).
TTY=""
if [ "$ASSUME_YES" -eq 0 ] && [ -r /dev/tty ] && (exec </dev/tty) 2>/dev/null; then
	TTY=/dev/tty
fi

ask() { # ask VARIABLE "Question" "default"
	_current=$(eval "printf '%s' \"\${$1}\"")
	[ -n "$_current" ] && return 0
	if [ -z "$TTY" ]; then
		eval "$1=\$3"
		return 0
	fi
	if [ -n "$3" ]; then printf '%s [%s]: ' "$2" "$3" >/dev/tty; else printf '%s: ' "$2" >/dev/tty; fi
	IFS= read -r _answer </dev/tty || _answer=""
	[ -z "$_answer" ] && _answer="$3"
	eval "$1=\$_answer"
}

ask_secret() { # ask_secret VARIABLE "Question"
	_current=$(eval "printf '%s' \"\${$1}\"")
	[ -n "$_current" ] && return 0
	[ -z "$TTY" ] && return 0
	printf '%s (leave empty to generate one): ' "$2" >/dev/tty
	stty -echo </dev/tty 2>/dev/null || true
	IFS= read -r _answer </dev/tty || _answer=""
	stty echo </dev/tty 2>/dev/null || true
	printf '\n' >/dev/tty
	eval "$1=\$_answer"
}

random_hex() { # random_hex BYTES
	if command -v openssl >/dev/null 2>&1; then
		openssl rand -hex "$1"
	else
		od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'
	fi
}

env_value() { # env_value FILE KEY
	[ -f "$1" ] && sed -n "s/^$2=//p" "$1" | tail -n 1 || true
}

# --------------------------------------------------------------------------------------------------------------------
say "Checking requirements"
command -v docker >/dev/null 2>&1 || die "Docker is required: https://docs.docker.com/engine/install/"
docker compose version >/dev/null 2>&1 || die "the Docker Compose plugin is required (docker compose)"
docker info >/dev/null 2>&1 || die "cannot reach the Docker daemon (add your user to the docker group, or use sudo)"

mkdir -p "$DIR"
cd "$DIR"
ENV_FILE="$DIR/docker.env"

# Existing installation: keep its settings.
if [ -f "$ENV_FILE" ]; then
	say "Existing installation found in $DIR: settings are kept"
	[ -z "$DOMAIN" ] && DOMAIN=$(env_value "$ENV_FILE" DEFAULT_URI | sed 's#^https\{0,1\}://##; s#[:/].*##')
	[ "$(env_value "$ENV_FILE" SERVER_NAME)" = ":80" ] && [ "$DOMAIN" != "localhost" ] && PROXY=1
	[ -f "$DIR/mariadb.env" ] && DB=mariadb
fi

ask DOMAIN "Public domain name of your instance (e.g. photos.example.org)" "localhost"
case "$DB" in sqlite|mariadb) ;; *) die "--db must be sqlite or mariadb" ;; esac
if [ "$DOMAIN" = "localhost" ]; then
	SCHEME=http
else
	SCHEME=https
fi
if [ -z "$HTTP_PORT" ]; then
	if [ "$PROXY" -eq 1 ]; then HTTP_PORT=8080; else HTTP_PORT=80; fi
fi
PUBLIC_URL="$SCHEME://$DOMAIN"
[ "$DOMAIN" = "localhost" ] && [ "$HTTP_PORT" != "80" ] && PUBLIC_URL="$PUBLIC_URL:$HTTP_PORT"

# --------------------------------------------------------------------------------------------------------------------
say "Writing configuration to $DIR"
if [ ! -f "$ENV_FILE" ]; then
	if [ "$PROXY" -eq 1 ] || [ "$DOMAIN" = "localhost" ]; then SERVER_NAME=":80"; else SERVER_NAME="$DOMAIN"; fi
	if [ "$PROXY" -eq 1 ]; then TRUSTED="TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR"; else TRUSTED="# TRUSTED_PROXIES="; fi
	umask 077
	cat >"$ENV_FILE" <<EOF
# Socialize configuration — see https://github.com/sadjo/socialize/wiki/Configuration
SERVER_NAME=$SERVER_NAME
DEFAULT_URI=$PUBLIC_URL
APP_SECRET=$(random_hex 32)
REGISTRATION=invite
HOME_LOGIN_ONLY=0
MASTODON_LOGIN=closed
MASTODON_SIGNUP=
WORKERS=1
$TRUSTED
# MAILER_DSN=smtp://user:password@smtp.example.org:587
# MAILER_FROM="Socialize <no-reply@$DOMAIN>"
EOF
	umask 022
fi

if [ "$DB" = "mariadb" ] && [ ! -f "$DIR/mariadb.env" ]; then
	umask 077
	printf 'MARIADB_PASSWORD=%s\n' "$(random_hex 16)" >"$DIR/mariadb.env"
	umask 022
fi

if [ "$PROXY" -eq 1 ] || [ "$DOMAIN" = "localhost" ]; then
	PORTS="      - \"$HTTP_PORT:80\""
else
	PORTS="      - \"$HTTP_PORT:80\"
      - \"443:443\"
      - \"443:443/udp\""
fi

if [ "$DB" = "mariadb" ]; then
	MARIADB_PASSWORD=$(env_value "$DIR/mariadb.env" MARIADB_PASSWORD)
	# The file holds the database password: readable by its owner only.
	touch "$DIR/compose.yaml" && chmod 600 "$DIR/compose.yaml"
	cat >"$DIR/compose.yaml" <<EOF
# Generated by the Socialize installer. Settings live in docker.env.
name: socialize

services:
  app:
    image: $IMAGE:$VERSION
    restart: unless-stopped
    init: true
    env_file: [docker.env]
    environment:
      DATABASE_URL: "mysql://socialize:$MARIADB_PASSWORD@mariadb:3306/socialize?serverVersion=11.4.0-MariaDB&charset=utf8mb4"
    depends_on:
      mariadb:
        condition: service_healthy
    ports:
$PORTS
    volumes:
      - data:/data
      - media:/media

  mariadb:
    image: mariadb:11.4
    restart: unless-stopped
    env_file: [mariadb.env]
    environment:
      MARIADB_DATABASE: socialize
      MARIADB_USER: socialize
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 20s
    volumes:
      - mariadb:/var/lib/mysql

volumes:
  data:
  media:
  mariadb:
EOF
else
	cat >"$DIR/compose.yaml" <<EOF
# Generated by the Socialize installer. Settings live in docker.env.
name: socialize

services:
  app:
    image: $IMAGE:$VERSION
    restart: unless-stopped
    init: true
    env_file: [docker.env]
    ports:
$PORTS
    volumes:
      - data:/data
      - media:/media

volumes:
  data:
  media:
EOF
fi

# --------------------------------------------------------------------------------------------------------------------
say "Pulling $IMAGE:$VERSION"
docker compose pull --quiet 2>/dev/null || docker image inspect "$IMAGE:$VERSION" >/dev/null 2>&1 \
	|| die "cannot pull $IMAGE:$VERSION"
say "Starting Socialize"
docker compose up -d --wait --wait-timeout 300 || {
	docker compose logs --tail 50 app >&2 || true
	die "the containers did not become healthy (see the logs above)"
}

# --------------------------------------------------------------------------------------------------------------------
ACCOUNTS=$(docker compose exec -T app php bin/console dbal:run-sql "SELECT COUNT(*) AS n FROM users" 2>/dev/null \
	| tr -cd '0-9' || true)
if [ -n "$ACCOUNTS" ] && [ "$ACCOUNTS" != "0" ]; then
	say "Accounts already exist: no administrator created"
else
	ask ADMIN_EMAIL "Administrator e-mail address" "admin@$DOMAIN"
	ask ADMIN_USERNAME "Administrator username" "admin"
	ask_secret ADMIN_PASSWORD "Administrator password"
	GENERATED=0
	if [ -z "$ADMIN_PASSWORD" ]; then
		ADMIN_PASSWORD=$(random_hex 12)
		GENERATED=1
	fi
	say "Creating the administrator account @$ADMIN_USERNAME"
	printf '%s' "$ADMIN_PASSWORD" | docker compose exec -T app \
		php bin/console app:user:create "$ADMIN_EMAIL" "$ADMIN_USERNAME" --admin --password-stdin \
		|| warn "the administrator could not be created; run: docker compose exec app php bin/console app:user:create <email> <username> --admin"
	[ "$GENERATED" -eq 1 ] && printf '\n  Generated password for %s: \033[1m%s\033[0m\n  Change it after your first login.\n' \
		"$ADMIN_EMAIL" "$ADMIN_PASSWORD"
fi

cat <<EOF

  Socialize is running: $PUBLIC_URL$( [ "$PROXY" -eq 1 ] && printf ' (via your reverse proxy → port %s)' "$HTTP_PORT" )

  Installation directory : $DIR
  Settings               : $ENV_FILE
  Upgrade                : cd $DIR && docker compose pull && docker compose up -d
  Logs                   : cd $DIR && docker compose logs -f app
  Documentation          : https://github.com/sadjo/socialize/wiki

EOF
