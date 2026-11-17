<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class SanitizeTermsHtml
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'h1', 'h2', 'h3', 'p', 'br', 'strong', 'b', 'em', 'i', 'u',
        'ul', 'ol', 'li', 'a', 'span', 'blockquote', 'div',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div id="terms-root">'.$html.'</div>';
        $document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('terms-root');
        if (! $root instanceof DOMElement) {
            return '';
        }

        self::scrubNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    public static function isPresent(?string $html): bool
    {
        return trim(strip_tags(self::clean($html))) !== '';
    }

    private static function scrubNode(DOMNode $node): void
    {
        if (! $node->hasChildNodes()) {
            return;
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                self::scrubAttributes($child);
                self::scrubNode($child);

                continue;
            }

            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function scrubAttributes(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);
        $allowed = match ($tag) {
            'a' => ['href', 'target', 'rel'],
            'span' => ['style'],
            default => [],
        };

        $remove = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->name);
            if (! in_array($name, $allowed, true)) {
                $remove[] = $attribute->name;
            }
        }
        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }

        if ($tag === 'a') {
            $href = trim((string) $element->getAttribute('href'));
            if ($href === '' || preg_match('/^\s*javascript:/i', $href) === 1) {
                $element->removeAttribute('href');
            }
            $element->setAttribute('rel', 'noopener noreferrer');
            if ($element->getAttribute('target') === '_blank') {
                $element->setAttribute('target', '_blank');
            } else {
                $element->removeAttribute('target');
            }
        }

        if ($tag === 'span' && $element->hasAttribute('style')) {
            $style = (string) $element->getAttribute('style');
            if (preg_match('/color\s*:\s*([^;]+)/i', $style, $matches) !== 1) {
                $element->removeAttribute('style');
            } else {
                $color = trim($matches[1]);
                if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)|[a-zA-Z]{3,20})$/', $color) !== 1) {
                    $element->removeAttribute('style');
                } else {
                    $element->setAttribute('style', 'color: '.$color);
                }
            }
        }
    }
}
