<?php

namespace App\Support;

final class TemplateHtmlSanitizer
{
    private const ALLOWED_TAGS = '<article><aside><b><blockquote><br><caption><div><em><footer><h1><h2><h3><h4><h5><h6><header><hr><i><li><main><ol><p><section><small><span><strong><sub><sup><table><tbody><td><tfoot><th><thead><tr><u><ul>';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_STYLE_PROPERTIES = [
        'background',
        'background-color',
        'border',
        'border-bottom',
        'border-collapse',
        'border-color',
        'border-left',
        'border-radius',
        'border-right',
        'border-spacing',
        'border-style',
        'border-top',
        'border-width',
        'color',
        'display',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'height',
        'letter-spacing',
        'line-height',
        'margin',
        'margin-bottom',
        'margin-left',
        'margin-right',
        'margin-top',
        'max-width',
        'min-height',
        'min-width',
        'padding',
        'padding-bottom',
        'padding-left',
        'padding-right',
        'padding-top',
        'text-align',
        'text-decoration',
        'text-transform',
        'vertical-align',
        'white-space',
        'width',
    ];

    public static function sanitize(string $html): string
    {
        $sanitized = trim($html);
        if ($sanitized === '') {
            return '';
        }

        $sanitized = (string) preg_replace('/<\?(?:php)?[\s\S]*?\?>/iu', '', $sanitized);
        $sanitized = (string) preg_replace('/<%(?:[\s\S]*?)%>/u', '', $sanitized);
        $sanitized = (string) preg_replace('/<(script|iframe|object|embed|form|input|button|textarea|select|option|link|meta|base)\b[^>]*>.*?<\/\1>/isu', '', $sanitized);
        $sanitized = (string) preg_replace('/<(script|iframe|object|embed|form|input|button|textarea|select|option|link|meta|base)\b[^>]*\/?>/isu', '', $sanitized);

        $sanitized = strip_tags($sanitized, self::ALLOWED_TAGS);
        $sanitized = (string) preg_replace('/\s+on[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/isu', '', $sanitized);
        $sanitized = (string) preg_replace('/\s+(src|href)\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/isu', '', $sanitized);
        $sanitized = (string) preg_replace_callback('/\s+style\s*=\s*(".*?"|\'.*?\')/isu', static function (array $matches): string {
            $raw = trim((string) ($matches[1] ?? ''), "\"'");
            $style = self::sanitizeInlineStyle($raw);

            return $style !== '' ? ' style="' . $style . '"' : '';
        }, $sanitized);

        return $sanitized;
    }

    private static function sanitizeInlineStyle(string $style): string
    {
        $style = trim($style);
        if ($style === '') {
            return '';
        }

        $rules = preg_split('/\s*;\s*/', $style) ?: [];
        $allowedRules = [];

        foreach ($rules as $rule) {
            if (! str_contains($rule, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $rule, 2));
            $property = mb_strtolower($property);
            $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $value) ?? '';
            $valueLower = mb_strtolower($value);

            if (! in_array($property, self::ALLOWED_STYLE_PROPERTIES, true)) {
                continue;
            }

            if (
                $value === ''
                || str_contains($valueLower, 'expression')
                || str_contains($valueLower, 'javascript:')
                || str_contains($valueLower, 'vbscript:')
                || str_contains($valueLower, 'data:')
                || str_contains($valueLower, 'url(')
                || str_contains($valueLower, '@import')
                || str_contains($valueLower, '<')
                || str_contains($valueLower, '>')
            ) {
                continue;
            }

            $allowedRules[] = $property . ': ' . $value;
        }

        return implode('; ', $allowedRules);
    }
}
