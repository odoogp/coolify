<?php

function installLocalBuildSection(string $script): string
{
    $start = strpos($script, '# local-build-step-9-start');
    $end = strpos($script, '# local-build-step-9-end');

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse()
        ->and($end)->toBeGreaterThan($start);

    return substr($script, $start, $end - $start);
}

it('keeps the official cdn install and adds a local build mode', function () {
    $script = file_get_contents(base_path('scripts/install.sh'));
    $localBuild = installLocalBuildSection($script);

    expect($script)
        ->toContain('COOLIFY_LOCAL_BUILD=true ./scripts/install.sh')
        ->toContain('if [ "${COOLIFY_LOCAL_BUILD:-false}" = "true" ] || [ "${COOLIFY_BUILD_LOCAL:-false}" = "true" ]; then')
        ->toContain('curl -fsSL -L $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml')
        ->toContain('curl -fsSL -L $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml')
        ->toContain('cp "$REPO_ROOT/docker-compose.yml" /data/coolify/source/docker-compose.yml')
        ->toContain('cp "$REPO_ROOT/docker-compose.prod.yml" /data/coolify/source/docker-compose.prod.yml')
        ->toContain('cp "$REPO_ROOT/.env.production" /data/coolify/source/.env.production')
        ->toContain('update_env_var "DB_USERNAME" "coolify"')
        ->toContain('update_env_var "DB_DATABASE" "coolify"')
        ->toContain('update_env_var "DB_PASSWORD"')
        ->toContain('update_env_var "REDIS_PASSWORD"')
        ->toContain('update_env_var "PUSHER_APP_ID"')
        ->toContain('update_env_var "PUSHER_APP_KEY"')
        ->toContain('update_env_var "PUSHER_APP_SECRET"')
        ->toContain('set_env_var "COOLIFY_LOCAL_BUILD" "true"')
        ->toContain('set_env_var "COOLIFY_IMAGE" "coolify-custom:local"')
        ->toContain('set_env_var "COOLIFY_PULL_POLICY" "never"')
        ->toContain('docker network create --attachable coolify')
        ->not->toContain('down -v')
        ->not->toContain('docker compose down');

    expect($localBuild)
        ->toContain('build coolify')
        ->toContain('coolify-custom:local')
        ->toContain('pull_policy never')
        ->not->toContain('upgrade.sh')
        ->not->toContain('docker pull')
        ->not->toContain('down -v');

    expect(file_get_contents(base_path('scripts/install-custom.sh')))
        ->toContain('export COOLIFY_LOCAL_BUILD=true')
        ->toContain('exec "$SCRIPT_DIR/install.sh"');
});

it('builds the fork image and keeps the official postgres redis and realtime images', function () {
    $prod = file_get_contents(base_path('docker-compose.prod.yml'));
    $base = file_get_contents(base_path('docker-compose.yml'));

    expect($prod)
        ->toContain('image: "${COOLIFY_IMAGE:-coolify-custom:local}"')
        ->toContain('pull_policy: "${COOLIFY_PULL_POLICY:-never}"')
        ->toContain('context: ${COOLIFY_BUILD_CONTEXT:-.}')
        ->toContain('dockerfile: docker/production/Dockerfile')
        ->toContain('coollabsio/coolify-realtime:1.0.17')
        ->toContain('external: true')
        ->toContain('name: coolify-db')
        ->toContain('name: coolify-redis');

    expect($base)
        ->toContain('image: postgres:15-alpine')
        ->toContain('image: redis:7-alpine');
});

it('updates a local checkout without pulling the official image', function () {
    $script = file_get_contents(base_path('scripts/upgrade-local.sh'));

    expect($script)
        ->toContain('git fetch origin')
        ->toContain('write_status "2" "Pulling ${BRANCH}"')
        ->toContain('git pull --ff-only origin "$BRANCH"')
        ->toContain('git status --porcelain')
        ->toContain('assert_or_stash_local_changes')
        ->toContain('restore_stashed_local_changes')
        ->toContain('drop_untracked_preserve_already_on_origin')
        ->toContain('docker/production/etc/nginx')
        ->toContain('docker/production/etc/s6-overlay')
        ->toContain('git stash push --include-untracked')
        ->toContain('keeping pulled nginx/s6-overlay from origin')
        ->toContain('git merge-base --is-ancestor HEAD "origin/${BRANCH}"')
        ->toContain('docker build -f "${CONTEXT}/docker/production/Dockerfile" -t "$IMAGE" "$CONTEXT"')
        ->toContain('coolify-custom:local')
        ->toContain('COOLIFY_PULL_POLICY="never"')
        ->toContain('up -d --no-deps --force-recreate --wait --wait-timeout 180 coolify')
        ->toContain('http://127.0.0.1:${APP_PORT}/api/health')
        ->toContain('docker tag "$PREVIOUS_ID" "$IMAGE"')
        ->not->toContain('coollabsio/coolify')
        ->not->toContain('docker pull')
        ->not->toContain('down -v')
        ->not->toContain('git reset --hard')
        ->not->toContain('git clean')
        ->not->toContain('.env.production');
});

it('stashes only nginx and s6-overlay local changes around a fast-forward', function () {
    $root = sys_get_temp_dir().'/coolify-upgrade-stash-'.bin2hex(random_bytes(4));
    $origin = $root.'/origin.git';
    $work = $root.'/work';

    try {
        expect(mkdir($root, 0777, true))->toBeTrue();
        exec('git init --bare '.escapeshellarg($origin).' 2>&1', $out, $code);
        expect($code)->toBe(0);

        exec('git clone '.escapeshellarg($origin).' '.escapeshellarg($work).' 2>&1', $out, $code);
        expect($code)->toBe(0);

        $nginx = $work.'/docker/production/etc/nginx';
        $s6 = $work.'/docker/production/etc/s6-overlay/s6-rc.d/init-script';
        expect(mkdir($nginx, 0777, true))->toBeTrue()
            ->and(mkdir($s6, 0777, true))->toBeTrue();
        file_put_contents($nginx.'/custom.conf', "# base\n");
        file_put_contents($s6.'/up', "#!/bin/execlineb -P\necho base\n");
        file_put_contents($work.'/README', "v1\n");

        $git = 'git -C '.escapeshellarg($work);
        exec($git.' config user.email test@example.com && '.$git.' config user.name test && '.$git.' add -A && '.$git.' commit -m base && '.$git.' branch -M main && '.$git.' push -u origin main 2>&1', $out, $code);
        expect($code)->toBe(0);

        // Upstream advances
        $other = $root.'/other';
        exec('git clone '.escapeshellarg($origin).' '.escapeshellarg($other).' 2>&1', $out, $code);
        expect($code)->toBe(0);
        file_put_contents($other.'/README', "v2\n");
        $gitOther = 'git -C '.escapeshellarg($other);
        exec($gitOther.' config user.email test@example.com && '.$gitOther.' config user.name test && '.$gitOther.' add README && '.$gitOther.' commit -m upstream && '.$gitOther.' push origin main 2>&1', $out, $code);
        expect($code)->toBe(0);

        // Local nginx/s6 customizations (the production blocker)
        file_put_contents($nginx.'/custom.conf', "# local nginx fix\n");
        chmod($s6.'/up', 0755);
        file_put_contents($s6.'/up', "#!/bin/execlineb -P\necho local-s6\n");

        $helpers = <<<'BASH'
STASHED=0
PRESERVE_PATHS=(docker/production/etc/nginx docker/production/etc/s6-overlay)
fail() { echo "FAIL: $*" >&2; exit 1; }
log() { echo "$*"; }
porcelain_path() {
    local line="$1" rest="${line:3}"
    if [[ "$rest" == *' -> '* ]]; then rest="${rest##* -> }"; fi
    printf '%s' "$rest" | sed -e 's/^"//' -e 's/"$//'
}
path_is_preserved() {
    local path="$1" allowed
    for allowed in "${PRESERVE_PATHS[@]}"; do
        if [ "$path" = "$allowed" ] || [[ "$path" == "$allowed"/* ]]; then return 0; fi
    done
    return 1
}
assert_or_stash_local_changes() {
    local line path dirty_other=""
    if [ -z "$(git status --porcelain)" ]; then return 0; fi
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        path=$(porcelain_path "$line")
        if ! path_is_preserved "$path"; then dirty_other="${dirty_other}${path}"$'\n'; fi
    done < <(git status --porcelain)
    if [ -n "$dirty_other" ]; then fail "outside preserve paths:"$'\n'"$dirty_other"; fi
    git stash push --include-untracked -m "coolify-upgrade-preserve-test" -- "${PRESERVE_PATHS[@]}" || fail stash
    STASHED=1
}
restore_stashed_local_changes() {
    if [ "${STASHED}" != "1" ]; then return 0; fi
    git stash pop || fail "stash pop conflicted"
    STASHED=0
}
BASH;

        $script = "set -euo pipefail\ncd ".escapeshellarg($work)."\n{$helpers}\nassert_or_stash_local_changes\ngit pull --ff-only origin main\nrestore_stashed_local_changes\n";
        $tmp = $root.'/run.sh';
        file_put_contents($tmp, $script);
        exec('bash '.escapeshellarg($tmp).' 2>&1', $out, $code);
        expect($code)->toBe(0, implode("\n", $out))
            ->and(file_get_contents($work.'/README'))->toBe("v2\n")
            ->and(file_get_contents($nginx.'/custom.conf'))->toBe("# local nginx fix\n")
            ->and(file_get_contents($s6.'/up'))->toContain('local-s6')
            ->and(trim(shell_exec($git.' status --porcelain') ?? ''))->not->toBe('');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('drops untracked nginx/s6 files already on origin instead of stashing them', function () {
    $root = sys_get_temp_dir().'/coolify-upgrade-drop-'.bin2hex(random_bytes(4));
    $origin = $root.'/origin.git';
    $work = $root.'/work';

    try {
        expect(mkdir($root, 0777, true))->toBeTrue();
        exec('git init --bare '.escapeshellarg($origin).' 2>&1', $out, $code);
        expect($code)->toBe(0);

        exec('git clone '.escapeshellarg($origin).' '.escapeshellarg($work).' 2>&1', $out, $code);
        expect($code)->toBe(0);

        // Track the preserve roots so untracked markers are not reported as ?? docker/.
        $s6Root = $work.'/docker/production/etc/s6-overlay';
        $nginxRoot = $work.'/docker/production/etc/nginx';
        expect(mkdir($s6Root, 0777, true))->toBeTrue()
            ->and(mkdir($nginxRoot, 0777, true))->toBeTrue();
        file_put_contents($s6Root.'/.gitkeep', '');
        file_put_contents($nginxRoot.'/.gitkeep', '');
        file_put_contents($work.'/README', "v1\n");
        $git = 'git -C '.escapeshellarg($work);
        exec($git.' config user.email test@example.com && '.$git.' config user.name test && '.$git.' add -A && '.$git.' commit -m base && '.$git.' branch -M main && '.$git.' push -u origin main 2>&1', $out, $code);
        expect($code)->toBe(0);

        // Upstream commits the s6 markers (what the host had only as untracked emergency files).
        $other = $root.'/other';
        exec('git clone '.escapeshellarg($origin).' '.escapeshellarg($other).' 2>&1', $out, $code);
        expect($code)->toBe(0);
        $otherMarkers = $other.'/docker/production/etc/s6-overlay/s6-rc.d/user/contents.d';
        expect(mkdir($otherMarkers, 0777, true))->toBeTrue();
        file_put_contents($otherMarkers.'/nginx', '');
        file_put_contents($otherMarkers.'/php-fpm', '');
        file_put_contents($other.'/README', "v2\n");
        $gitOther = 'git -C '.escapeshellarg($other);
        exec($gitOther.' config user.email test@example.com && '.$gitOther.' config user.name test && '.$gitOther.' add -A && '.$gitOther.' commit -m upstream-markers && '.$gitOther.' push origin main 2>&1', $out, $code);
        expect($code)->toBe(0);

        // Host (still on v1) has the same markers only as untracked copies.
        $markers = $work.'/docker/production/etc/s6-overlay/s6-rc.d/user/contents.d';
        expect(mkdir($markers, 0777, true))->toBeTrue();
        file_put_contents($markers.'/nginx', '');
        file_put_contents($markers.'/php-fpm', '');

        $helpers = <<<'BASH'
STASHED=0
BRANCH=main
PRESERVE_PATHS=(docker/production/etc/nginx docker/production/etc/s6-overlay)
fail() { echo "FAIL: $*" >&2; exit 1; }
log() { echo "$*"; }
porcelain_path() {
    local line="$1" rest="${line:3}"
    if [[ "$rest" == *' -> '* ]]; then rest="${rest##* -> }"; fi
    printf '%s' "$rest" | sed -e 's/^"//' -e 's/"$//'
}
path_is_preserved() {
    local path="$1" allowed
    for allowed in "${PRESERVE_PATHS[@]}"; do
        if [ "$path" = "$allowed" ] || [[ "$path" == "$allowed"/* ]]; then return 0; fi
    done
    return 1
}
upstream_has_path() { git cat-file -e "origin/${BRANCH}:${1}" 2>/dev/null; }
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
        if path_is_preserved "$path"; then return 0; fi
    done < <(git status --porcelain)
    return 1
}
assert_or_stash_local_changes() {
    local line path dirty_other=""
    if [ -z "$(git status --porcelain)" ]; then return 0; fi
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        path=$(porcelain_path "$line")
        if ! path_is_preserved "$path"; then dirty_other="${dirty_other}${path}"$'\n'; fi
    done < <(git status --porcelain)
    if [ -n "$dirty_other" ]; then fail "outside preserve paths:"$'\n'"$dirty_other"; fi
    drop_untracked_preserve_already_on_origin
    if ! preserve_paths_are_dirty; then log "nginx/s6-overlay matches origin; no stash needed"; return 0; fi
    git stash push --include-untracked -m "coolify-upgrade-preserve-test" -- "${PRESERVE_PATHS[@]}" || fail stash
    STASHED=1
}
restore_stashed_local_changes() {
    if [ "${STASHED}" != "1" ]; then return 0; fi
    git stash pop || fail "stash pop conflicted"
    STASHED=0
}
BASH;

        $script = "set -euo pipefail\ncd ".escapeshellarg($work)."\ngit fetch origin\n{$helpers}\nassert_or_stash_local_changes\ngit pull --ff-only origin main\nrestore_stashed_local_changes\n";
        $tmp = $root.'/run.sh';
        file_put_contents($tmp, $script);
        exec('bash '.escapeshellarg($tmp).' 2>&1', $out, $code);
        expect($code)->toBe(0, implode("\n", $out))
            ->and(implode("\n", $out))->toContain('no stash needed')
            ->and(file_get_contents($work.'/README'))->toBe("v2\n")
            ->and(is_file($markers.'/nginx'))->toBeTrue()
            ->and(is_file($markers.'/php-fpm'))->toBeTrue()
            ->and(trim(shell_exec($git.' status --porcelain') ?? ''))->toBe('')
            ->and(trim(shell_exec($git.' stash list') ?? ''))->toBe('');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('rejects local changes outside nginx and s6-overlay before updating', function () {
    $root = sys_get_temp_dir().'/coolify-upgrade-reject-'.bin2hex(random_bytes(4));
    $work = $root.'/work';

    try {
        expect(mkdir($work, 0777, true))->toBeTrue();
        exec('git -C '.escapeshellarg($work).' init 2>&1', $out, $code);
        expect($code)->toBe(0);
        file_put_contents($work.'/README', "v1\n");
        $git = 'git -C '.escapeshellarg($work);
        exec($git.' config user.email test@example.com && '.$git.' config user.name test && '.$git.' add README && '.$git.' commit -m base 2>&1', $out, $code);
        expect($code)->toBe(0);
        file_put_contents($work.'/README', "dirty\n");

        $helpers = <<<'BASH'
STASHED=0
PRESERVE_PATHS=(docker/production/etc/nginx docker/production/etc/s6-overlay)
fail() { echo "FAIL: $*" >&2; exit 1; }
log() { :; }
porcelain_path() {
    local line="$1" rest="${line:3}"
    if [[ "$rest" == *' -> '* ]]; then rest="${rest##* -> }"; fi
    printf '%s' "$rest" | sed -e 's/^"//' -e 's/"$//'
}
path_is_preserved() {
    local path="$1" allowed
    for allowed in "${PRESERVE_PATHS[@]}"; do
        if [ "$path" = "$allowed" ] || [[ "$path" == "$allowed"/* ]]; then return 0; fi
    done
    return 1
}
assert_or_stash_local_changes() {
    local line path dirty_other=""
    if [ -z "$(git status --porcelain)" ]; then return 0; fi
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        path=$(porcelain_path "$line")
        if ! path_is_preserved "$path"; then dirty_other="${dirty_other}${path}"$'\n'; fi
    done < <(git status --porcelain)
    if [ -n "$dirty_other" ]; then fail "Local changes outside nginx/s6-overlay:"$'\n'"$dirty_other"; fi
}
BASH;
        $script = "set -euo pipefail\ncd ".escapeshellarg($work)."\n{$helpers}\nassert_or_stash_local_changes\n";
        $tmp = $root.'/run.sh';
        file_put_contents($tmp, $script);
        exec('bash '.escapeshellarg($tmp).' 2>&1', $out, $code);
        expect($code)->not->toBe(0)
            ->and(implode("\n", $out))->toContain('outside nginx/s6-overlay')
            ->and(file_get_contents($work.'/README'))->toBe("dirty\n");
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('copies the local upgrade script into the production image', function () {
    $dockerfile = file_get_contents(base_path('docker/production/Dockerfile'));

    expect($dockerfile)
        ->toContain('COPY --chown=www-data:www-data scripts ./scripts')
        ->toContain('chmod 755 scripts/upgrade-local.sh');

    expect(is_file(base_path('scripts/upgrade-local.sh')))->toBeTrue()
        ->and(is_executable(base_path('scripts/upgrade-local.sh')))->toBeTrue();
});

it('keeps production s6 init as root and ships user/type for the bundle', function () {
    $dockerfile = file_get_contents(base_path('docker/production/Dockerfile'));
    $userType = base_path('docker/production/etc/s6-overlay/s6-rc.d/user/type');
    $contents = base_path('docker/production/etc/s6-overlay/s6-rc.d/user/contents.d');

    expect($dockerfile)
        ->toContain('USER root')
        ->toContain("can't create /etc/s6-overlay/s6-rc.d/user/type");

    expect(preg_match('/^USER www-data\s*$/m', $dockerfile))->toBe(0);

    expect(is_file($userType))->toBeTrue()
        ->and(trim((string) file_get_contents($userType)))->toBe('bundle')
        ->and(is_file($contents.'/nginx'))->toBeTrue()
        ->and(is_file($contents.'/php-fpm'))->toBeTrue();
});

it('parses the install scripts as bash', function () {
    foreach (['scripts/install.sh', 'scripts/install-custom.sh', 'scripts/upgrade-local.sh'] as $path) {
        $output = [];
        $exit = 0;
        exec('bash -n '.escapeshellarg(base_path($path)).' 2>&1', $output, $exit);

        expect($output)->toBe([])
            ->and($exit)->toBe(0);
    }
});
