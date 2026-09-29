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

it('parses the install scripts as bash', function () {
    foreach (['scripts/install.sh', 'scripts/install-custom.sh'] as $path) {
        $output = [];
        $exit = 0;
        exec('bash -n '.escapeshellarg(base_path($path)).' 2>&1', $output, $exit);

        expect($output)->toBe([])
            ->and($exit)->toBe(0);
    }
});
