<?php

test('the panel can be installed without caching signed-in pages', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, 512, JSON_THROW_ON_ERROR);
    $worker = file_get_contents(public_path('sw.js'));
    $offline = file_get_contents(public_path('offline.html'));
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect($manifest['name'])->toBe(product_name())
        ->and($manifest['short_name'])->toBe(product_name())
        ->and($manifest['start_url'])->toBe('/')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['theme_color'])->toBe('#1C2430')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and($worker)->toContain("addEventListener('fetch'")
        ->and($worker)->toContain("request.mode !== 'navigate'")
        ->and($worker)->toContain('caches.match(offlineUrl)')
        ->and($worker)->not->toContain('cache.put')
        ->and($worker)->not->toContain('/livewire')
        ->and($offline)->toContain('No hay conexión')
        ->and($offline)->not->toContain('csrf-token')
        ->and($layout)->toContain('rel="manifest"')
        ->and($layout)->toContain("serviceWorker.register('/sw.js'")
        ->and($layout)->toContain('apple-touch-icon')
        ->and(file_get_contents(public_path('pwa/icon-192.png')))->toStartWith("\x89PNG")
        ->and(file_get_contents(public_path('pwa/icon-512.png')))->toStartWith("\x89PNG");
});
