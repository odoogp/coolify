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

    $html->assertSee('disabled', false)
        ->assertSee('x-data="copyButton"', false);
});

it('accepts a resolve expression for async clipboard values', function () {
    $html = $this->blade('<x-copy-button resolve="$wire.copyValue()" label="Copy value" />');

    $html->assertSee('Copy value')
        ->assertSee('$wire.copyValue()', false)
        ->assertDontSee('disabled', false);
});
