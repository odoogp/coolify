<?php

it('renders a self-contained clipboard button for backend-provided values', function () {
    $html = $this->blade('<x-copy-button value="backup/path.sql" label="Copy backup path" />');

    $html->assertSee('Copy backup path')
        ->assertSee('backup\/path.sql', false)
        ->assertSee('x-data="copyButton"', false)
        ->assertDontSee('window.copyToClipboard', false);
});

it('disables the button when no backend value is available', function () {
    $html = $this->blade('<x-copy-button :value="null" />');

    expect($view)->toContain('<x-copy-button :value="data_get($execution, \'filename\', \'\')" label="{{ __(\'Copy backup path\') }}" />');
});
