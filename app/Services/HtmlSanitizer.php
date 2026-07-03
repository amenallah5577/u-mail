<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

class HtmlSanitizer
{
    private array $allowedTags = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'a', 'h1', 'h2', 'h3',
        'div', 'span', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'img', 'hr',
    ];

    private array $allowedStyles = [
        'background', 'background-color', 'border', 'border-bottom', 'border-collapse', 'border-color', 'border-left',
        'border-radius', 'border-right', 'border-spacing', 'border-style', 'border-top', 'border-width', 'color',
        'display', 'font-family', 'font-size', 'font-style', 'font-weight', 'height', 'letter-spacing', 'line-height',
        'margin', 'margin-bottom', 'margin-left', 'margin-right', 'margin-top', 'max-width', 'min-width', 'padding',
        'padding-bottom', 'padding-left', 'padding-right', 'padding-top', 'text-align', 'text-decoration',
        'text-transform', 'vertical-align', 'white-space', 'width',
    ];

    public function sanitize(string $html): string
    {
        $html = strip_tags($html, '<'.implode('><', $this->allowedTags).'>');
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (! $this->attributeAllowed($element, $attribute->name)) {
                    $element->removeAttribute($attribute->name);
                }
            }

            if ($element->tagName === 'a') {
                $href = $element->getAttribute('href');
                if (! preg_match('/^(https?:\/\/|mailto:)/i', $href)) {
                    $element->removeAttribute('href');
                }
            }

            if ($element->tagName === 'img') {
                $src = $element->getAttribute('src');
                if (! preg_match('/^https?:\/\//i', $src) && ! preg_match('/^data:image\/(?:png|jpe?g|gif|webp);base64,/i', $src)) {
                    $element->removeAttribute('src');
                }
            }

            if ($element->hasAttribute('style')) {
                $style = $this->sanitizeStyle($element->getAttribute('style'));
                $style ? $element->setAttribute('style', $style) : $element->removeAttribute('style');
            }
        }

        $wrapper = $document->getElementsByTagName('div')->item(0);
        $clean = '';
        foreach ($wrapper?->childNodes ?? [] as $child) {
            $clean .= $document->saveHTML($child);
        }

        return trim($clean);
    }

    private function attributeAllowed(DOMElement $element, string $attribute): bool
    {
        if ($attribute === 'style') {
            return true;
        }

        return match ($element->tagName) {
            'a' => in_array($attribute, ['href', 'title'], true),
            'img' => in_array($attribute, ['src', 'alt', 'width', 'height'], true),
            'table' => in_array($attribute, ['width', 'height', 'cellpadding', 'cellspacing', 'border', 'align', 'bgcolor'], true),
            'td', 'th' => in_array($attribute, ['width', 'height', 'colspan', 'rowspan', 'align', 'valign', 'bgcolor'], true),
            default => false,
        };
    }

    private function sanitizeStyle(string $style): string
    {
        return collect(explode(';', $style))
            ->map(function ($declaration) {
                [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
                $property = strtolower(trim((string) $property));
                $value = trim((string) $value);

                if (! in_array($property, $this->allowedStyles, true) || $value === '') {
                    return null;
                }
                if (preg_match('/(?:url\s*\(|expression\s*\(|@import|javascript:|position\s*:|behavior\s*:)/i', $value)) {
                    return null;
                }

                return $property.': '.$value;
            })
            ->filter()
            ->implode('; ');
    }
}
