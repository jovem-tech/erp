<?php

namespace App\Services\Financeiro;

use App\Models\Configuration;
use RuntimeException;

class FinanceiroGatewayTaxaService
{
    private const STORAGE_KEY = 'financeiro_gateway_taxas';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function catalog(): array
    {
        return [
            'asaas' => [
                'label' => 'Asaas',
                'modes' => [
                    [
                        'code' => 'PIX',
                        'label' => 'PIX',
                        'description' => 'Cobrança PIX do Asaas com QR Code e copia e cola.',
                    ],
                    [
                        'code' => 'BOLETO',
                        'label' => 'Boleto',
                        'description' => 'Boleto bancário do Asaas para pagamento em bancos e apps.',
                    ],
                    [
                        'code' => 'CREDIT_CARD',
                        'label' => 'Cartão de crédito',
                        'description' => 'Checkout hospedado do Asaas para pagamento em cartão.',
                    ],
                ],
            ],
            'mercado_pago' => [
                'label' => 'Mercado Pago',
                'modes' => [
                    [
                        'code' => 'PIX',
                        'label' => 'PIX',
                        'description' => 'Payment Brick do Mercado Pago focado em PIX.',
                    ],
                    [
                        'code' => 'BOLETO',
                        'label' => 'Boleto',
                        'description' => 'Payment Brick do Mercado Pago focado em boleto.',
                    ],
                    [
                        'code' => 'CREDIT_CARD',
                        'label' => 'Cartão de crédito',
                        'description' => 'Payment Brick do Mercado Pago focado em cartão de crédito.',
                    ],
                    [
                        'code' => 'DEBIT_CARD',
                        'label' => 'Cartão de débito',
                        'description' => 'Payment Brick do Mercado Pago focado em cartão de débito.',
                    ],
                    [
                        'code' => 'payment_brick',
                        'label' => 'Checkout Bricks (legado)',
                        'description' => 'Modo legado com todos os meios liberados no mesmo brick.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $rows = $this->rows();

        return [
            'gateway_catalog' => $this->catalog(),
            'gateway_taxas' => $rows,
            'gateway_summary' => [
                'total' => count($rows),
                'ativas' => count(array_filter($rows, static fn (array $row): bool => (int) ($row['ativo'] ?? 0) === 1)),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        $storedRows = $this->loadRows();
        $rows = $storedRows !== [] ? $storedRows : $this->defaultRows();

        return $this->decorateRows($this->sortRows($rows));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $rows = $this->storedRowsOrDefaults();
        $id = (int) ($payload['id'] ?? 0);
        $rowId = $id > 0 ? $id : $this->nextId($rows);

        $normalized = $this->normalizeStoredRow($payload, $rowId);
        $updated = false;

        foreach ($rows as $index => $row) {
            if ((int) ($row['id'] ?? 0) !== $rowId) {
                continue;
            }

            $rows[$index] = $normalized;
            $updated = true;
            break;
        }

        if (! $updated) {
            $rows[] = $normalized;
        }

        $this->persistRows($rows);

        return $this->decorateRow($normalized);
    }

    public function delete(int $id): void
    {
        $rows = $this->storedRowsOrDefaults();

        foreach ($rows as $index => $row) {
            if ((int) ($row['id'] ?? 0) !== $id) {
                continue;
            }

            $rows[$index]['ativo'] = 0;
            $rows[$index]['updated_at'] = now()->toISOString();
            $this->persistRows($rows);

            return;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        foreach ($this->rows() as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveRate(string $provider, ?string $mode): ?array
    {
        $provider = $this->normalizeProvider($provider);
        $mode = $this->normalizeMode($provider, $mode);

        if ($provider === '' || $mode === '') {
            return null;
        }

        foreach ($this->rows() as $row) {
            if ((int) ($row['ativo'] ?? 0) !== 1) {
                continue;
            }

            if ($this->normalizeProvider((string) ($row['provider'] ?? '')) !== $provider) {
                continue;
            }

            if ($this->normalizeMode($provider, (string) ($row['modalidade'] ?? '')) !== $mode) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateGrossAmount(float $baseAmount, string $provider, ?string $mode): array
    {
        $provider = $this->normalizeProvider($provider);
        $mode = $this->normalizeMode($provider, $mode);
        $baseAmount = round(max(0, $baseAmount), 2);
        $config = $this->findActiveRate($provider, $mode);

        $taxPercent = round((float) ($config['taxa_percentual'] ?? 0), 4);
        $fixedFee = round((float) ($config['taxa_fixa'] ?? 0), 2);
        $chargeAmount = $baseAmount;
        $feeAmount = 0.0;

        if ($config !== null && $baseAmount > 0 && ($taxPercent > 0 || $fixedFee > 0)) {
            $divisor = 1 - ($taxPercent / 100);
            if ($divisor <= 0) {
                throw new RuntimeException('A taxa configurada para este gateway invalida o calculo do valor final.');
            }

            $chargeAmount = round(($baseAmount + $fixedFee) / $divisor, 2);
            $feeAmount = round(max(0, $chargeAmount - $baseAmount), 2);
        }

        return [
            'gateway_taxa_id' => (int) ($config['id'] ?? 0) > 0 ? (int) $config['id'] : null,
            'provider' => $provider,
            'provider_label' => $this->resolveProviderLabel($provider),
            'mode' => $mode,
            'mode_label' => $this->resolveModeLabel($provider, $mode),
            'description' => $this->resolveModeDescription($provider, $mode),
            'base_amount' => $baseAmount,
            'charge_amount' => $chargeAmount,
            'fee_amount' => $feeAmount,
            'tax_percent' => $taxPercent,
            'fixed_fee' => $fixedFee,
            'is_configured' => $config !== null,
            'has_fee' => $feeAmount > 0.009,
            'summary' => $feeAmount > 0.009
                ? sprintf(
                    'Cliente paga %s, com %s de taxa embutida.',
                    $this->formatMoney($chargeAmount),
                    $this->formatMoney($feeAmount)
                )
                : sprintf('Cliente paga %s.', $this->formatMoney($chargeAmount)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedRowsOrDefaults(): array
    {
        $rows = $this->loadRows();

        return $rows !== [] ? $rows : $this->defaultRows();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(): array
    {
        $json = Configuration::query()
            ->where('chave', self::STORAGE_KEY)
            ->value('valor');

        if (! is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function persistRows(array $rows): void
    {
        Configuration::query()->updateOrInsert(
            ['chave' => self::STORAGE_KEY],
            [
                'valor' => json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tipo' => 'json',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function nextId(array $rows): int
    {
        $current = 0;

        foreach ($rows as $row) {
            $current = max($current, (int) ($row['id'] ?? 0));
        }

        return $current + 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeStoredRow(array $payload, int $id): array
    {
        $provider = $this->normalizeProvider((string) ($payload['provider'] ?? ''));
        $mode = $this->normalizeMode($provider, (string) ($payload['modalidade'] ?? ''));

        if ($provider === '' || $mode === '') {
            throw new RuntimeException('Selecione um gateway e uma modalidade válidos.');
        }

        return [
            'id' => $id,
            'provider' => $provider,
            'modalidade' => $mode,
            'taxa_percentual' => round((float) ($payload['taxa_percentual'] ?? 0), 4),
            'taxa_fixa' => round((float) ($payload['taxa_fixa'] ?? 0), 2),
            'ordem_exibicao' => max(0, (int) ($payload['ordem_exibicao'] ?? 0)),
            'ativo' => (int) filter_var($payload['ativo'] ?? true, FILTER_VALIDATE_BOOL),
            'observacoes' => trim((string) ($payload['observacoes'] ?? '')) !== '' ? trim((string) $payload['observacoes']) : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $leftActive = (int) ($left['ativo'] ?? 0);
            $rightActive = (int) ($right['ativo'] ?? 0);

            if ($leftActive !== $rightActive) {
                return $rightActive <=> $leftActive;
            }

            $leftOrder = (int) ($left['ordem_exibicao'] ?? 0);
            $rightOrder = (int) ($right['ordem_exibicao'] ?? 0);

            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            $leftProvider = (string) ($left['provider'] ?? '');
            $rightProvider = (string) ($right['provider'] ?? '');

            if ($leftProvider !== $rightProvider) {
                return $leftProvider <=> $rightProvider;
            }

            $leftMode = (string) ($left['modalidade'] ?? '');
            $rightMode = (string) ($right['modalidade'] ?? '');

            if ($leftMode !== $rightMode) {
                return $leftMode <=> $rightMode;
            }

            return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorateRow(array $row): array
    {
        $provider = $this->normalizeProvider((string) ($row['provider'] ?? ''));
        $mode = $this->normalizeMode($provider, (string) ($row['modalidade'] ?? ''));

        $row['provider'] = $provider;
        $row['modalidade'] = $mode;
        $row['ativo'] = (bool) ($row['ativo'] ?? false);
        $row['provider_label'] = $this->resolveProviderLabel($provider);
        $row['modalidade_label'] = $this->resolveModeLabel($provider, $mode);
        $row['catalog_description'] = $this->resolveModeDescription($provider, $mode);

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorateRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->decorateRow($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultRows(): array
    {
        return [
            [
                'id' => 1,
                'provider' => 'asaas',
                'modalidade' => 'PIX',
                'taxa_percentual' => 0,
                'taxa_fixa' => 1.99,
                'ordem_exibicao' => 10,
                'ativo' => 1,
                'observacoes' => 'Tabela pública do Asaas em 14/06/2026.',
            ],
            [
                'id' => 2,
                'provider' => 'asaas',
                'modalidade' => 'BOLETO',
                'taxa_percentual' => 0,
                'taxa_fixa' => 1.99,
                'ordem_exibicao' => 20,
                'ativo' => 1,
                'observacoes' => 'Tabela pública do Asaas em 14/06/2026.',
            ],
            [
                'id' => 3,
                'provider' => 'asaas',
                'modalidade' => 'CREDIT_CARD',
                'taxa_percentual' => 2.99,
                'taxa_fixa' => 0.49,
                'ordem_exibicao' => 30,
                'ativo' => 1,
                'observacoes' => 'Crédito à vista no checkout online do Asaas em 14/06/2026.',
            ],
            [
                'id' => 4,
                'provider' => 'mercado_pago',
                'modalidade' => 'PIX',
                'taxa_percentual' => 0.99,
                'taxa_fixa' => 0.00,
                'ordem_exibicao' => 110,
                'ativo' => 1,
                'observacoes' => 'Tabela inicial parametrizada para o Mercado Pago. Validar com o contrato vigente.',
            ],
            [
                'id' => 5,
                'provider' => 'mercado_pago',
                'modalidade' => 'BOLETO',
                'taxa_percentual' => 3.49,
                'taxa_fixa' => 0.00,
                'ordem_exibicao' => 120,
                'ativo' => 1,
                'observacoes' => 'Tabela inicial parametrizada para o Mercado Pago. Validar com o contrato vigente.',
            ],
            [
                'id' => 6,
                'provider' => 'mercado_pago',
                'modalidade' => 'CREDIT_CARD',
                'taxa_percentual' => 4.99,
                'taxa_fixa' => 0.00,
                'ordem_exibicao' => 130,
                'ativo' => 1,
                'observacoes' => 'Tabela inicial parametrizada para o Mercado Pago. Validar com o contrato vigente.',
            ],
            [
                'id' => 7,
                'provider' => 'mercado_pago',
                'modalidade' => 'DEBIT_CARD',
                'taxa_percentual' => 1.99,
                'taxa_fixa' => 0.00,
                'ordem_exibicao' => 140,
                'ativo' => 1,
                'observacoes' => 'Tabela inicial parametrizada para o Mercado Pago. Validar com o contrato vigente.',
            ],
        ];
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return in_array($provider, ['asaas', 'mercado_pago'], true) ? $provider : '';
    }

    private function normalizeMode(string $provider, ?string $mode): string
    {
        $provider = $this->normalizeProvider($provider);
        $rawMode = trim((string) $mode);

        if ($rawMode === '') {
            return $provider === 'mercado_pago' ? 'PIX' : 'PIX';
        }

        if ($rawMode === 'payment_brick') {
            return 'payment_brick';
        }

        $upper = strtoupper($rawMode);
        $aliases = [
            'PIX' => 'PIX',
            'BOLETO' => 'BOLETO',
            'CREDIT_CARD' => 'CREDIT_CARD',
            'DEBIT_CARD' => 'DEBIT_CARD',
        ];

        return $aliases[$upper] ?? $upper;
    }

    private function resolveProviderLabel(string $provider): string
    {
        $provider = $this->normalizeProvider($provider);

        return (string) ($this->catalog()[$provider]['label'] ?? 'Gateway online');
    }

    private function resolveModeLabel(string $provider, ?string $mode): string
    {
        $provider = $this->normalizeProvider($provider);
        $mode = $this->normalizeMode($provider, $mode);
        $modes = $this->catalog()[$provider]['modes'] ?? [];

        foreach ($modes as $item) {
            if ((string) ($item['code'] ?? '') === $mode) {
                return (string) ($item['label'] ?? $mode);
            }
        }

        return $mode !== '' ? $mode : 'Modalidade online';
    }

    private function resolveModeDescription(string $provider, ?string $mode): string
    {
        $provider = $this->normalizeProvider($provider);
        $mode = $this->normalizeMode($provider, $mode);
        $modes = $this->catalog()[$provider]['modes'] ?? [];

        foreach ($modes as $item) {
            if ((string) ($item['code'] ?? '') === $mode) {
                return (string) ($item['description'] ?? '');
            }
        }

        return '';
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
