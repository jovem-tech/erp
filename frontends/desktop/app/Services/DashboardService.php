<?php

namespace App\Services;

class DashboardService
{
    /**
     * @var array<int, string>
     */
    private const MONTH_LABELS = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function bootstrapFilters(array $filters = []): array
    {
        $currentYear = (int) now()->year;
        $currentMonth = (int) now()->month;
        $year = $this->bootstrapYear($filters['ano'] ?? null, $currentYear);
        $equipmentYear = $this->bootstrapYear($filters['equip_ano'] ?? null, $currentYear);
        $equipmentMonth = $this->bootstrapMonth($filters['equip_mes'] ?? null, $currentMonth);

        return [
            'year' => $year,
            'years' => $this->bootstrapYears($year, $currentYear),
            'equipmentMonth' => $equipmentMonth,
            'equipmentYear' => $equipmentYear,
            'equipmentYears' => $this->bootstrapYears($equipmentYear, $currentYear),
            'months' => self::MONTH_LABELS,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $query = array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
        $response = $this->apiClient->get('/dashboard/summary', $query);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $heroCard = $this->normalizeHeroCard($data['hero_card'] ?? []);

        return [
            'access' => $this->arrayValue($data['access'] ?? []),
            'stats' => $this->arrayValue($data['stats'] ?? []),
            'heroCard' => $heroCard,
            'contextCard' => $this->arrayValue($data['context_card'] ?? []),
            'charts' => [
                'monthly' => $this->arrayValue($data['charts']['monthly'] ?? []),
                'status' => $this->arrayValue($data['charts']['status'] ?? []),
                'equipmentTypes' => $this->arrayValue($data['charts']['equipment_types'] ?? []),
                'financial' => $this->arrayValue($data['charts']['financial'] ?? []),
                'technician' => $this->arrayValue($data['charts']['technician'] ?? []),
            ],
            'filters' => [
                'year' => (int) ($data['filters']['year'] ?? date('Y')),
                'years' => $this->intList($data['filters']['years'] ?? []),
                'equipmentMonth' => (int) ($data['filters']['equipment_month'] ?? date('n')),
                'equipmentYear' => (int) ($data['filters']['equipment_year'] ?? date('Y')),
                'equipmentYears' => $this->intList($data['filters']['equipment_years'] ?? []),
                'months' => $this->arrayValue($data['filters']['months'] ?? []),
            ],
            'recentOrders' => $this->listValue($data['recent_orders'] ?? []),
            'recentClients' => $this->listValue($data['recent_clients'] ?? []),
            'recentEquipments' => $this->listValue($data['recent_equipments'] ?? []),
            'lowStock' => $this->listValue($data['low_stock'] ?? []),
            'raw' => $data,
        ];
    }

    /**
     * Normaliza CTAs recebidos da API para rotas válidas do frontend desktop.
     *
     * O backend central pode expor links de API como fallback de contrato, mas o
     * dashboard do desktop precisa apontar para rotas da interface, nao para a
     * origem da API.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeHeroCard(mixed $value): array
    {
        $heroCard = $this->arrayValue($value);
        $actionUrl = trim((string) ($heroCard['action_url'] ?? ''));

        if ($actionUrl === '') {
            $heroCard['action_url'] = null;

            return $heroCard;
        }

        $path = parse_url($actionUrl, PHP_URL_PATH);
        $normalizedPath = '/' . ltrim(is_string($path) && $path !== '' ? $path : $actionUrl, '/');

        if ($normalizedPath === '/api/v1/orders' || str_starts_with($normalizedPath, '/api/v1/orders/')) {
            $heroCard['action_url'] = route('orders.index');

            return $heroCard;
        }

        if (str_starts_with($normalizedPath, '/api/v1/')) {
            $heroCard['action_url'] = null;

            return $heroCard;
        }

        $heroCard['action_url'] = $actionUrl;

        return $heroCard;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function listValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_array($item)));
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_int($item) || is_numeric($item)) {
                $items[] = (int) $item;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<int, int>
     */
    private function bootstrapYears(int $selectedYear, int $currentYear): array
    {
        $years = range($currentYear, $currentYear - 4);

        if (! in_array($selectedYear, $years, true)) {
            $years[] = $selectedYear;
        }

        $years = array_values(array_unique(array_map(static fn ($year): int => (int) $year, $years)));
        rsort($years, SORT_NUMERIC);

        return $years;
    }

    private function bootstrapYear(mixed $value, int $fallback): int
    {
        if ((is_int($value) || is_numeric($value)) && (int) $value > 0) {
            return (int) $value;
        }

        return $fallback;
    }

    private function bootstrapMonth(mixed $value, int $fallback): int
    {
        if ((is_int($value) || is_numeric($value))) {
            $month = (int) $value;

            if ($month >= 1 && $month <= 12) {
                return $month;
            }
        }

        return $fallback;
    }
}
