<?php

namespace App\Services\Pdf;

use Illuminate\Support\Carbon;

/**
 * Contexto simulado para pré-visualização de templates sem entidade real:
 * gera valores de exemplo coerentes com o tipo declarado de cada variável e
 * duas linhas de amostra por coleção.
 */
class PdfSampleContext
{
    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function for(array $descriptor): array
    {
        $context = [];

        foreach (($descriptor['variables'] ?? []) as $path => $tipo) {
            $segments = explode('.', (string) $path);
            if (count($segments) !== 2) {
                continue;
            }

            [$grupo, $campo] = $segments;
            $context[$grupo][$campo] = self::sampleValue((string) $tipo, (string) $campo);
        }

        foreach (($descriptor['collections'] ?? []) as $nome => $colunas) {
            $rows = [];
            for ($i = 1; $i <= 2; $i++) {
                $row = [];
                foreach ($colunas as $campo => $tipo) {
                    $row[$campo] = self::sampleValue((string) $tipo, (string) $campo, $i);
                }
                $rows[] = $row;
            }
            $context[$nome] = $rows;
        }

        return $context;
    }

    private static function sampleValue(string $tipo, string $campo, int $seed = 1): mixed
    {
        return match ($tipo) {
            'moeda' => 100.0 * $seed + 23.45,
            'inteiro' => $seed,
            'data' => Carbon::now()->addDays(7),
            'data_hora' => Carbon::now(),
            'telefone' => '11 98888-77' . str_pad((string) $seed, 2, '0', STR_PAD_LEFT),
            'documento' => '12345678000199',
            'html' => '<ul class="list"><li>Exemplo de item ' . $seed . '</li></ul>',
            'imagem' => '',
            default => 'Exemplo de ' . str_replace('_', ' ', $campo),
        };
    }
}
