<?php

use App\Support\SanitizeTermsHtml;

it('keeps safe formatting tags and strips scripts', function () {
    $html = SanitizeTermsHtml::clean(<<<'HTML'
        <h1>Terms</h1>
        <p>Hello <strong>world</strong> <script>alert(1)</script></p>
        <a href="javascript:alert(1)">bad</a>
        <a href="https://example.com" target="_blank">ok</a>
        <span style="color: #ff0000; background: red">red</span>
    HTML);

    expect($html)
        ->toContain('<h1>Terms</h1>')
        ->toContain('<strong>world</strong>')
        ->not->toContain('<script>')
        ->not->toContain('javascript:')
        ->toContain('https://example.com')
        ->toContain('color: #ff0000')
        ->not->toContain('background');
});

it('detects empty terms after stripping tags', function () {
    expect(SanitizeTermsHtml::isPresent('<p><br></p>'))->toBeFalse()
        ->and(SanitizeTermsHtml::isPresent('<h2>Policy</h2>'))->toBeTrue();
});
