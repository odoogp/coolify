#!/bin/bash
# Install this Coolify fork from the git checkout and build docker/production/Dockerfile.
# The official scripts/install.sh path is unchanged unless COOLIFY_BUILD_LOCAL=true.
#
# Update an existing install from the repository root:
#   git pull
#   docker compose --env-file /data/coolify/source/.env -f docker-compose.yml -f docker-compose.prod.yml build coolify
#   docker compose --env-file /data/coolify/source/.env -f docker-compose.yml -f docker-compose.prod.yml up -d

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)
ENV_FILE="/data/coolify/source/.env"

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run this script as root or with sudo"
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 is required. Install Docker, then run this script again."
    exit 1
fi

echo "Installing Coolify from ${REPO_ROOT}"

mkdir -p /data/coolify/{source,ssh,applications,databases,backups,services,proxy,sentinel,images}
mkdir -p /data/coolify/ssh/{keys,mux}
mkdir -p /data/coolify/proxy/dynamic
mkdir -p /data/coolify/images/{avatars,project-icons}

if ! docker network inspect coolify >/dev/null 2>&1; then
    if ! docker network create --attachable --ipv6 coolify >/dev/null 2>&1; then
        docker network create --attachable coolify >/dev/null
    fi
fi

if [ ! -f "$ENV_FILE" ]; then
    cp "$REPO_ROOT/.env.production" "$ENV_FILE"
fi

update_env_var() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=$" "$ENV_FILE"; then
        sed -i "s|^${key}=$|${key}=${value}|" "$ENV_FILE"
    elif ! grep -q "^${key}=" "$ENV_FILE"; then
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
    fi
}

set_env_var() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
    fi
}

update_env_var "APP_ID" "$(openssl rand -hex 16)"
update_env_var "APP_KEY" "base64:$(openssl rand -base64 32)"
update_env_var "DB_PASSWORD" "$(openssl rand -base64 32)"
update_env_var "REDIS_PASSWORD" "$(openssl rand -base64 32)"
update_env_var "PUSHER_APP_ID" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"

if [ -n "${ROOT_USERNAME:-}" ] && [ -n "${ROOT_USER_EMAIL:-}" ] && [ -n "${ROOT_USER_PASSWORD:-}" ]; then
    update_env_var "ROOT_USERNAME" "$ROOT_USERNAME"
    update_env_var "ROOT_USER_EMAIL" "$ROOT_USER_EMAIL"
    update_env_var "ROOT_USER_PASSWORD" "$ROOT_USER_PASSWORD"
fi

set_env_var "COOLIFY_IMAGE" "coolify-custom:local"
set_env_var "COOLIFY_PULL_POLICY" "never"
set_env_var "AUTOUPDATE" "false"

if [ -L "$REPO_ROOT/.env" ] || [ ! -e "$REPO_ROOT/.env" ]; then
    ln -sfn "$ENV_FILE" "$REPO_ROOT/.env"
fi

cd "$REPO_ROOT"
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.prod.yml build coolify
docker compose --env-file "$ENV_FILE" -f docker-compose.yml -f docker-compose.prod.yml up -d

echo "Coolify is running from image coolify-custom:local."
echo "Persistent data stays in /data/coolify and the coolify-db / coolify-redis volumes."
echo "To update:"
echo "  cd ${REPO_ROOT}"
echo "  git pull"
echo "  docker compose --env-file /data/coolify/source/.env -f docker-compose.yml -f docker-compose.prod.yml build coolify"
echo "  docker compose --env-file /data/coolify/source/.env -f docker-compose.yml -f docker-compose.prod.yml up -d"
