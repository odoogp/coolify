<?php

it('translates status labels and buttons when the locale is spanish', function () {
    $previous = app()->getLocale();
    app()->setLocale('es');

    expect(translateUiText('Running (unknown)'))->toBe('En ejecución (desconocido)')
        ->and(translateUiText('Running (unhealthy, excluded)'))->toBe('En ejecución (no saludable, excluido)')
        ->and(translateUiText('Restart'))->toBe('Reiniciar')
        ->and(translateUiText('Copied to clipboard.'))->toBe('Copiado al portapapeles.')
        ->and(translateUiText('This operation is permanent and cannot be undone. Please think again before proceeding!'))->toBe('Esta operación es permanente y no se puede deshacer. Piénsalo otra vez antes de continuar.')
        ->and(translateUiText('Service application restarted successfully.'))->toBe('Aplicación del servicio reiniciada correctamente.');

    app()->setLocale('en');

    expect(translateUiText('Running (unknown)'))->toBe('Running (unknown)');

    app()->setLocale($previous);
});
