<?php

namespace App\Services\Pdf;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Resolve `{{ caminho.campo | formatador }}` contra o DocumentContext.
 *
 * Regras de segurança: TODO valor é escapado por padrão; só variáveis
 * declaradas com tipo `html` na allowlist do tipo documental são injetadas
 * sem re-escape (e essas são pré-sanitizadas na factory de contexto).
 * Nenhuma avaliação de código: é lookup em array + formatação + escape.
 */
class PdfVariableResolver
{
    public const TOKEN_PATTERN = '/\{\{\s*([a-z0-9_.]+)\s*(?:\|\s*([a-z_]+)\s*)?\}\}/i';

    public const FORMATTERS = ['moeda', 'data', 'data_hora', 'telefone', 'documento', 'inteiro', 'maiusculas'];

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $variableTypes caminho => tipo (da allowlist do registry)
     */
    public function resolveText(string $text, array $context, array $variableTypes = []): string
    {
        return $this->replaceTokens($this->escape($text), $context, $variableTypes);
    }

    /**
     * Resolve variáveis mantendo o markup literal para posterior sanitização.
     * Uso exclusivo do bloco `texto_rico`; nunca envie o retorno diretamente.
     *
     * @param array<string, mixed> $context
     * @param array<string, string> $variableTypes
     */
    public function resolveRichText(string $html, array $context, array $variableTypes = []): string
    {
        return $this->replaceTokens($html, $context, $variableTypes);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $variableTypes
     */
    private function replaceTokens(string $text, array $context, array $variableTypes): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $matches) use ($context, $variableTypes): string {
                $path = strtolower(trim($matches[1]));
                $formatter = strtolower(trim($matches[2] ?? ''));
                $value = $this->lookup($path, $context);

                if (($variableTypes[$path] ?? '') === 'html') {
                    // Pré-sanitizado na factory — injeta sem re-escape.
                    return (string) $value;
                }

                $formatted = $this->format($value, $formatter !== '' ? $formatter : ($variableTypes[$path] ?? ''));

                return $this->preserveLineBreaks($this->escape($formatted));
            },
            $text
        );
    }

    private function preserveLineBreaks(string $escapedText): string
    {
        return str_replace(
            ["<br>\r\n", "<br>\n", "<br>\r"],
            '<br>',
            nl2br($escapedText, false)
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function lookup(string $path, array $context): mixed
    {
        $segments = explode('.', trim($path));
        $value = $context;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function format(mixed $value, string $formatter): string
    {
        if ($value === null) {
            return '';
        }

        return match ($formatter) {
            'moeda' => 'R$ ' . number_format((float) $value, 2, ',', '.'),
            'data' => $this->formatDate($value, 'd/m/Y'),
            'data_hora' => $this->formatDate($value, 'd/m/Y H:i'),
            'telefone' => $this->formatPhone((string) $value),
            'documento' => $this->formatTaxDocument((string) $value),
            'inteiro' => trim((string) $value) === '' ? '' : (string) (int) $value,
            'maiusculas' => mb_strtoupper(trim((string) $value)),
            default => trim((string) $value),
        };
    }

    /**
     * Extrai todos os tokens `{{ caminho | formatador }}` de um texto.
     *
     * @return array<int, array{path: string, formatter: string}>
     */
    public function extractTokens(string $text): array
    {
        if (! preg_match_all(self::TOKEN_PATTERN, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(static fn (array $match): array => [
            'path' => strtolower(trim($match[1])),
            'formatter' => strtolower(trim($match[2] ?? '')),
        ], $matches);
    }

    private function formatDate(mixed $value, string $format): string
    {
        if ($value instanceof Carbon) {
            return $value->format($format);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->format($format);
        } catch (Throwable) {
            return $raw;
        }
    }

    private function formatPhone(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        return match (strlen($digits)) {
            10 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6)),
            11 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7)),
            default => trim($value),
        };
    }

    private function formatTaxDocument(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        return match (strlen($digits)) {
            11 => sprintf(
                '%s.%s.%s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 3),
                substr($digits, 9)
            ),
            14 => sprintf(
                '%s.%s.%s/%s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 4),
                substr($digits, 12)
            ),
            default => trim($value),
        };
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
