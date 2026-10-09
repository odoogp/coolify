#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)/.tmp-upgrade-test"
rm -rf "$ROOT"
ORIGIN="$ROOT/origin.git"
WORK="$ROOT/work"
mkdir -p "$ROOT"
git init --bare "$ORIGIN" >/dev/null
git clone "$ORIGIN" "$WORK" >/dev/null 2>&1
mkdir -p "$WORK/docker/production/etc/nginx" "$WORK/docker/production/etc/s6-overlay/s6-rc.d/init-script"
echo '# base' > "$WORK/docker/production/etc/nginx/custom.conf"
printf '%s\n' '#!/bin/execlineb -P' 'echo base' > "$WORK/docker/production/etc/s6-overlay/s6-rc.d/init-script/up"
echo v1 > "$WORK/README"
git -C "$WORK" config user.email t@e.com
git -C "$WORK" config user.name t
git -C "$WORK" add -A && git -C "$WORK" commit -m base >/dev/null
git -C "$WORK" branch -M main && git -C "$WORK" push -u origin main >/dev/null 2>&1

OTHER="$ROOT/other"
git clone "$ORIGIN" "$OTHER" >/dev/null 2>&1
echo v2 > "$OTHER/README"
git -C "$OTHER" config user.email t@e.com
git -C "$OTHER" config user.name t
git -C "$OTHER" add README && git -C "$OTHER" commit -m upstream >/dev/null
git -C "$OTHER" push origin main >/dev/null 2>&1

echo '# local nginx fix' > "$WORK/docker/production/etc/nginx/custom.conf"
printf '%s\n' '#!/bin/execlineb -P' 'echo local-s6' > "$WORK/docker/production/etc/s6-overlay/s6-rc.d/init-script/up"
chmod 755 "$WORK/docker/production/etc/s6-overlay/s6-rc.d/init-script/up"

HELPERS="$ROOT/helpers.sh"
cat > "$HELPERS" <<'EOF'
STASHED=0
PRESERVE_PATHS=(docker/production/etc/nginx docker/production/etc/s6-overlay)
fail() { echo "FAIL: $*" >&2; exit 1; }
log() { echo "LOG: $*"; }
porcelain_path() {
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
        fail "Local changes outside nginx/s6-overlay:"$'\n'"${dirty_other}"
    fi
    log "Stashing local nginx/s6-overlay customizations before pull"
    git stash push --include-untracked -m "coolify-upgrade-preserve-test" -- "${PRESERVE_PATHS[@]}" \
        || fail "Could not stash nginx/s6-overlay local changes."
    STASHED=1
}
restore_stashed_local_changes() {
    if [ "${STASHED}" != "1" ]; then
        return 0
    fi
    log "Restoring local nginx/s6-overlay customizations"
    if ! git stash pop; then
        STASHED=0
        fail "stash pop conflicted"
    fi
    STASHED=0
}
EOF

(
    set -euo pipefail
    cd "$WORK"
    # shellcheck disable=SC1090
    source "$HELPERS"
    assert_or_stash_local_changes
    git pull --ff-only origin main >/dev/null
    restore_stashed_local_changes
    grep -q 'local nginx fix' docker/production/etc/nginx/custom.conf
    grep -q 'local-s6' docker/production/etc/s6-overlay/s6-rc.d/init-script/up
    grep -q v2 README
    echo STASH_FF_RESTORE_OK
)

echo dirty > "$WORK/README"
if (
    set -euo pipefail
    cd "$WORK"
    # shellcheck disable=SC1090
    source "$HELPERS"
    assert_or_stash_local_changes
) 2>&1 | grep -q 'outside nginx/s6-overlay'; then
    echo REJECT_OUTSIDE_OK
else
    echo REJECT_OUTSIDE_FAIL
    exit 1
fi

grep -q dirty "$WORK/README"
echo DATA_PRESERVED_OK
rm -rf "$ROOT"
echo ALL_OK
