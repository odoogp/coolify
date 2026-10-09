#!/usr/bin/env bash
# Local/fork update. Invoked by UpdateCoolify instead of the CDN upgrade.sh.
# Does not pull coollabsio/coolify, does not rewrite /data/coolify/source/.env,
# and does not remove volumes.

set -euo pipefail

ENV_FILE="/data/coolify/source/.env"
STATUS_FILE="/data/coolify/source/.upgrade-status"
SOURCE_DIR="/data/coolify/source"
IMAGE="coolify-custom:local"

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    printf '%s\n' "$line"
    if [ -n "${LOGFILE:-}" ] && [ "${LOG_ALREADY_FILED:-}" != "1" ]; then
        printf '%s\n' "$line" >>"$LOGFILE"
    fi
}

write_status() {
    echo "$1|$2|$(date -Iseconds)" >"$STATUS_FILE"
}

fail() {
    if [ "${STASHED:-0}" = "1" ]; then
        log "Restoring stashed nginx/s6-overlay changes after failure"
        git stash pop >>"${LOGFILE:-/dev/null}" 2>&1 || log "WARNING: could not restore stash; check git stash list"
        STASHED=0
    fi

    if [ -n "${PREVIOUS_ID:-}" ]; then
        docker tag "$PREVIOUS_ID" "$IMAGE" >>"${LOGFILE:-/dev/null}" 2>&1 || true
    fi

    log "ERROR: $1"
    write_status "error" "$1"
    echo "ERROR: $1" >&2
    exit 1
}

env_value() {
    local key="$1"
    local line=""

    if [ -f "$ENV_FILE" ]; then
        line=$(grep -E "^${key}=" "$ENV_FILE" | tail -n1 || true)
    fi

    printf '%s' "${line#*=}"
}

load_paths() {
    CONTEXT=$(env_value COOLIFY_BUILD_CONTEXT)
    if [ -z "$CONTEXT" ]; then
        CONTEXT="/root/coolify"
    fi

    BRANCH=$(env_value COOLIFY_GIT_BRANCH)
    APP_PORT=$(env_value APP_PORT)
    if [ -z "$APP_PORT" ]; then
        APP_PORT="8000"
    fi

    COMPOSE_FILES="-f ${SOURCE_DIR}/docker-compose.yml -f ${SOURCE_DIR}/docker-compose.prod.yml"
    if [ -f "${SOURCE_DIR}/docker-compose.custom.yml" ]; then
        COMPOSE_FILES="$COMPOSE_FILES -f ${SOURCE_DIR}/docker-compose.custom.yml"
    fi
}

resolve_branch() {
    if [ -z "$BRANCH" ]; then
        BRANCH=$(git rev-parse --abbrev-ref HEAD)
    fi

    if [ "$BRANCH" = "HEAD" ]; then
        fail "Detached HEAD. Set COOLIFY_GIT_BRANCH to the branch this install tracks."
    fi
}

# Host checkouts often chmod nginx/s6 scripts so the image boots; that dirties
# git and used to abort the updater. Stash only these paths around ff-only pull.
# Markers already committed upstream (e.g. user/contents.d/nginx) must not be
# stashed as untracked — pull brings them and stash pop then conflicts.
PRESERVE_PATHS=(
    docker/production/etc/nginx
    docker/production/etc/s6-overlay
)

STASHED=0

porcelain_path() {
    # "XY path" | "XY orig -> path" | "XY \"path with spaces\""
    local line="$1"
    local rest="${line:3}"

    if [[ "$rest" == *' -> '* ]]; then
        rest="${rest##* -> }"
    fi

    printf '%s' "$rest" | sed -e 's/^"//' -e 's/"$//'
}

path_is_preserved() {
    local path="$1"
    local allowed

    for allowed in "${PRESERVE_PATHS[@]}"; do
        if [ "$path" = "$allowed" ] || [[ "$path" == "$allowed"/* ]]; then
            return 0
        fi
    done

    return 1
}

upstream_has_path() {
    local path="$1"

    git cat-file -e "origin/${BRANCH}:${path}" 2>/dev/null
}

# Untracked copies of files already on origin collide after pull+stash pop.
drop_untracked_preserve_already_on_origin() {
    local line path

    while IFS= read -r line; do
        [ -z "$line" ] && continue
        [[ "$line" == \?\?* ]] || continue
        path=$(porcelain_path "$line")
        path_is_preserved "$path" || continue
        upstream_has_path "$path" || continue
        log "Dropping untracked ${path}; already on origin/${BRANCH}"
        rm -f "$path"
    done < <(git status --porcelain)
}

preserve_paths_are_dirty() {
    local line path

    while IFS= read -r line; do
        [ -z "$line" ] && continue
        path=$(porcelain_path "$line")
        if path_is_preserved "$path"; then
            return 0
        fi
    done < <(git status --porcelain)

    return 1
}

assert_or_stash_local_changes() {
    local line path dirty_other=""

    if [ -z "$(git status --porcelain)" ]; then
        return 0
    fi

    while IFS= read -r line; do
        [ -z "$line" ] && continue
        path=$(porcelain_path "$line")
        if ! path_is_preserved "$path"; then
            dirty_other="${dirty_other}${path}"$'\n'
        fi
    done < <(git status --porcelain)

    if [ -n "$dirty_other" ]; then
        fail "Local changes in ${CONTEXT} outside nginx/s6-overlay. Commit or stash them before updating:"$'\n'"${dirty_other}"
    fi

    drop_untracked_preserve_already_on_origin

    if ! preserve_paths_are_dirty; then
        log "nginx/s6-overlay matches origin; no stash needed"
        return 0
    fi

    log "Stashing local nginx/s6-overlay customizations before pull"
    git stash push --include-untracked -m "coolify-upgrade-preserve-$(date +%s)" -- "${PRESERVE_PATHS[@]}" \
        || fail "Could not stash nginx/s6-overlay local changes."
    STASHED=1
}

restore_stashed_local_changes() {
    if [ "${STASHED}" != "1" ]; then
        return 0
    fi

    log "Restoring local nginx/s6-overlay customizations"
    if git stash pop >>"${LOGFILE:-/dev/null}" 2>&1; then
        STASHED=0
        return 0
    fi

    # Typical case: stash held untracked markers that pull already materialized.
    log "WARNING: stash pop conflicted; keeping pulled nginx/s6-overlay from origin"
    git checkout HEAD -- "${PRESERVE_PATHS[@]}" >>"${LOGFILE:-/dev/null}" 2>&1 || true
    git stash drop >>"${LOGFILE:-/dev/null}" 2>&1 || true
    STASHED=0
}

assert_local_image_config() {
    local file_image file_policy

    file_image=$(env_value COOLIFY_IMAGE)
    if [ -n "$file_image" ] && [ "$file_image" != "$IMAGE" ]; then
        fail "COOLIFY_IMAGE is ${file_image}. Refusing to switch to the official image."
    fi

    file_policy=$(env_value COOLIFY_PULL_POLICY)
    if [ -n "$file_policy" ] && [ "$file_policy" != "never" ]; then
        fail "COOLIFY_PULL_POLICY is ${file_policy}. Expected never."
    fi
}

compose() {
    COOLIFY_IMAGE="$IMAGE" \
        COOLIFY_PULL_POLICY="never" \
        COOLIFY_BUILD_CONTEXT="$CONTEXT" \
        docker compose --project-directory "$SOURCE_DIR" --env-file "$ENV_FILE" $COMPOSE_FILES "$@"
}

assert_compose_image() {
    local resolved policy config_json

    config_json=$(compose config --format json) || fail "Could not read the Coolify compose file."
    resolved=$(printf '%s' "$config_json" | jq -r '.services.coolify.image') || fail "Could not read the Coolify image name."
    policy=$(printf '%s' "$config_json" | jq -r '.services.coolify.pull_policy') || fail "Could not read the Coolify pull policy."

    case "$resolved" in
        coolify-custom:local|docker.io/coolify-custom:local|*/coolify-custom:local) ;;
        *)
            fail "Expected ${IMAGE}, got ${resolved}. Refusing to pull the official image."
            ;;
    esac

    if [ "$policy" != "never" ]; then
        fail "Expected pull_policy never, got ${policy}."
    fi
}

recreate_coolify() {
    compose up -d --no-deps --force-recreate --wait --wait-timeout 180 coolify
}

wait_for_health() {
    local attempt

    for ((attempt = 1; attempt <= 40; attempt++)); do
        if curl -fsS "http://127.0.0.1:${APP_PORT}/api/health" >/dev/null 2>&1; then
            return 0
        fi
        sleep 3
    done

    return 1
}

restore_previous_image() {
    local reason="$1"

    log "ERROR: ${reason}"
    if [ -z "${PREVIOUS_ID:-}" ]; then
        write_status "error" "${reason} No previous image was available to restore."
        return
    fi

    log "Restoring previous image ${PREVIOUS_ID}"
    if ! docker tag "$PREVIOUS_ID" "$IMAGE" >>"$LOGFILE" 2>&1; then
        write_status "error" "${reason} Could not retag the previous image."
        return
    fi

    if ! recreate_coolify >>"$LOGFILE" 2>&1; then
        write_status "error" "${reason} Could not start the previous image."
        return
    fi

    write_status "error" "${reason} The previous image was restored."
}

restart_new_image() {
    write_status "4" "Restarting coolify"
    log "Recreating the coolify service"

    if ! recreate_coolify >>"$LOGFILE" 2>&1; then
        restore_previous_image "The new container did not start."
        exit 1
    fi

    write_status "5" "Checking http://127.0.0.1:${APP_PORT}/api/health"
    log "Waiting for health"

    if ! wait_for_health; then
        restore_previous_image "Coolify did not become healthy."
        exit 1
    fi

    write_status "6" "Upgrade complete"
    log "Local upgrade completed"
    sleep 10
    rm -f "$STATUS_FILE"
}

if [ "${1:-}" = "--status" ]; then
    load_paths
    if [ ! -d "${CONTEXT}/.git" ]; then
        echo "Build context is not a git checkout: ${CONTEXT}" >&2
        exit 1
    fi

    git -C "$CONTEXT" fetch origin >&2
    if [ -z "$BRANCH" ]; then
        BRANCH=$(git -C "$CONTEXT" rev-parse --abbrev-ref HEAD)
    fi
    if [ "$BRANCH" = "HEAD" ]; then
        echo "Detached HEAD. Set COOLIFY_GIT_BRANCH." >&2
        exit 1
    fi

    HEAD_SHA=$(git -C "$CONTEXT" rev-parse HEAD)
    UPSTREAM_SHA=$(git -C "$CONTEXT" rev-parse "origin/${BRANCH}")
    printf '%s\t%s\t%s\n' "$BRANCH" "$HEAD_SHA" "$UPSTREAM_SHA"
    exit 0
fi

if [ "${1:-}" = "--restart" ]; then
    load_paths
    LOGFILE=${LOCAL_UPGRADE_LOGFILE:?}
    LOG_ALREADY_FILED=1
    restart_new_image
    exit 0
fi

DATE=$(date +%Y-%m-%d-%H-%M-%S)
LOGFILE="${SOURCE_DIR}/upgrade-${DATE}.log"
export LOCAL_UPGRADE_LOGFILE="$LOGFILE"
PREVIOUS_ID=""
log "Starting local upgrade"

load_paths
assert_local_image_config

if [ ! -f "$ENV_FILE" ]; then
    fail "Missing ${ENV_FILE}. Refusing to recreate it."
fi

if [ ! -d "${CONTEXT}/.git" ]; then
    fail "Build context is not a git checkout: ${CONTEXT}"
fi

cd "$CONTEXT"
resolve_branch

write_status "1" "Fetching ${BRANCH}"
log "Fetching origin ${BRANCH}"
git fetch origin || fail "git fetch origin failed."

# Need origin/${BRANCH} before deciding which untracked nginx/s6 files to drop.
assert_or_stash_local_changes

write_status "2" "Pulling ${BRANCH}"
log "Checking out ${BRANCH}"
git checkout "$BRANCH" 2>>"$LOGFILE" || git checkout -b "$BRANCH" "origin/${BRANCH}" || fail "Could not checkout ${BRANCH}."
if ! git merge-base --is-ancestor HEAD "origin/${BRANCH}"; then
    fail "Branch ${BRANCH} has diverged from origin. Fast-forward is not possible, so the running container was left unchanged."
fi
git pull --ff-only origin "$BRANCH" || fail "Fast-forward of ${BRANCH} failed. The branch has diverged."
restore_stashed_local_changes

PREVIOUS_ID=$(docker image inspect --format '{{.Id}}' "$IMAGE" 2>/dev/null || true)
export PREVIOUS_ID

write_status "3" "Building ${IMAGE}"
log "Building ${CONTEXT}/docker/production/Dockerfile"
if ! docker build -f "${CONTEXT}/docker/production/Dockerfile" -t "$IMAGE" "$CONTEXT" 2>&1 | tee -a "$LOGFILE"; then
    fail "Image build failed. The running container was not replaced."
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    fail "Image build did not produce ${IMAGE}. The running container was not replaced."
fi

assert_compose_image

log "Starting container recreate in the background"
nohup bash "$0" --restart >>"$LOGFILE" 2>&1 &
sleep 1
exit 0
