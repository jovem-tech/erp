<?php

namespace App\Services;

use App\Support\DesktopSession;
use Illuminate\Support\Str;

class SearchService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrcamentoService $orcamentoService,
        private readonly ClientService $clientService,
        private readonly SupplierService $supplierService,
        private readonly ServicoService $servicoService,
        private readonly StockService $stockService,
        private readonly EquipmentService $equipmentService,
        private readonly UserService $userService,
        private readonly GroupService $groupService
    ) {
    }

    /**
     * @return array<int, array{value:string,label:string,module:string,icon:string}>
     */
    public function scopes(): array
    {
        $scopes = [
            ['value' => 'tudo', 'label' => 'Busca completa', 'module' => 'dashboard', 'icon' => 'bi-search-heart'],
            ['value' => 'os', 'label' => 'OS', 'module' => 'os', 'icon' => 'bi-clipboard-check'],
            ['value' => 'orcamentos', 'label' => 'Orçamentos', 'module' => 'orcamentos', 'icon' => 'bi-receipt'],
            ['value' => 'clientes', 'label' => 'Clientes', 'module' => 'clientes', 'icon' => 'bi-people'],
            ['value' => 'fornecedores', 'label' => 'Fornecedores', 'module' => 'fornecedores', 'icon' => 'bi-truck'],
            ['value' => 'servicos', 'label' => 'Serviços', 'module' => 'servicos', 'icon' => 'bi-gear'],
            ['value' => 'estoque', 'label' => 'Estoque de Peças', 'module' => 'estoque', 'icon' => 'bi-box-seam'],
            ['value' => 'equipamentos', 'label' => 'Equipamentos', 'module' => 'equipamentos', 'icon' => 'bi-laptop'],
            ['value' => 'usuarios', 'label' => 'Usuários', 'module' => 'usuarios', 'icon' => 'bi-person-badge'],
            ['value' => 'grupos', 'label' => 'Grupos', 'module' => 'grupos', 'icon' => 'bi-shield-lock'],
        ];

        return array_values(array_filter($scopes, function (array $scope): bool {
            if ($scope['value'] === 'tudo') {
                return true;
            }

            return DesktopSession::can($scope['module'], 'visualizar');
        }));
    }

    /**
     * @param string|array<int, string> $scope
     * @return array<string, mixed>
     */
    public function search(string $query, string|array $scope = 'tudo', int $limit = 5): array
    {
        $query = trim($query);
        $scope = $this->normalizeScopes($scope);

        if ($query === '') {
            return [
                'query' => '',
                'scope' => $scope,
                'sections' => [],
                'total' => 0,
            ];
        }

        $sections = [];
        $total = 0;

        if ($this->scopeAllows('os', $scope)) {
            $items = $this->searchOrders($query, $limit);
            $total += count($items);
            $sections[] = $this->section('os', 'Ordens de Serviço', 'bi-clipboard-check', $items);
        }

        if ($this->scopeAllows('clientes', $scope)) {
            $items = $this->searchClients($query, $limit);
            $total += count($items);
            $sections[] = $this->section('clientes', 'Clientes', 'bi-people', $items);
        }

        if ($this->scopeAllows('orcamentos', $scope)) {
            $items = $this->searchBudgets($query, $limit);
            $total += count($items);
            $sections[] = $this->section('orcamentos', 'Orçamentos', 'bi-receipt', $items);
        }

        if ($this->scopeAllows('equipamentos', $scope)) {
            $items = $this->searchEquipments($query, $limit);
            $total += count($items);
            $sections[] = $this->section('equipamentos', 'Equipamentos', 'bi-laptop', $items);
        }

        if ($this->scopeAllows('servicos', $scope)) {
            $items = $this->searchServices($query, $limit);
            $total += count($items);
            $sections[] = $this->section('servicos', 'Serviços', 'bi-gear', $items);
        }

        if ($this->scopeAllows('estoque', $scope)) {
            $items = $this->searchStock($query, $limit);
            $total += count($items);
            $sections[] = $this->section('estoque', 'Estoque de Peças', 'bi-box-seam', $items);
        }

        if ($this->scopeAllows('fornecedores', $scope)) {
            $items = $this->searchSuppliers($query, $limit);
            $total += count($items);
            $sections[] = $this->section('fornecedores', 'Fornecedores', 'bi-truck', $items);
        }

        if ($this->scopeAllows('usuarios', $scope)) {
            $items = $this->searchUsers($query, $limit);
            $total += count($items);
            $sections[] = $this->section('usuarios', 'Usuários', 'bi-person-badge', $items);
        }

        if ($this->scopeAllows('grupos', $scope)) {
            $items = $this->searchGroups($query, $limit);
            $total += count($items);
            $sections[] = $this->section('grupos', 'Grupos', 'bi-shield-lock', $items);
        }

        return [
            'query' => $query,
            'scope' => $scope,
            'sections' => array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== [])),
            'total' => $total,
        ];
    }

    /**
     * @param string|array<int, string> $scope
     * @return array<string, mixed>
     */
    public function suggestions(string $query, string|array $scope = 'tudo', int $limit = 4): array
    {
        return $this->search($query, $scope, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchOrders(string $query, int $limit): array
    {
        $result = $this->orderService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $order): array {
            $title = (string) ($order['numero_os'] ?? '');

            if ($title === '') {
                $title = '#' . (string) ($order['id'] ?? '');
            }

            return [
                'id' => (int) ($order['id'] ?? 0),
                'label' => $title,
                'subtitle' => trim((string) ($order['cliente_nome'] ?? 'Cliente não informado')),
                'meta' => trim((string) ($order['status_nome'] ?? '')),
                'url' => route('orders.show', (int) ($order['id'] ?? 0)),
                'icon' => 'bi-clipboard-check',
                'badge' => trim((string) ($order['status_cor'] ?? '')),
                'kind' => 'OS',
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchClients(string $query, int $limit): array
    {
        $result = $this->clientService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $client): array {
            return [
                'id' => (int) ($client['id'] ?? 0),
                'label' => trim((string) ($client['nome_razao'] ?? 'Cliente')),
                'subtitle' => trim((string) ($client['telefone1'] ?? ($client['email'] ?? ''))),
                'meta' => trim((string) ($client['cidade'] ?? '')),
                'url' => route('clients.show', (int) ($client['id'] ?? 0)),
                'icon' => 'bi-people',
                'kind' => 'Cliente',
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchBudgets(string $query, int $limit): array
    {
        $result = $this->orcamentoService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $budget): array {
            $number = trim((string) ($budget['numero'] ?? ''));

            if ($number === '') {
                $number = '#' . (string) ($budget['id'] ?? 0);
            }

            $statusLabel = trim((string) ($budget['status_label'] ?? ($budget['status'] ?? '')));
            $typeLabel = trim((string) ($budget['tipo_label'] ?? ''));
            $valueLabel = trim((string) ($budget['total_formatado'] ?? '0,00'));

            return [
                'id' => (int) ($budget['id'] ?? 0),
                'label' => $number,
                'subtitle' => trim((string) ($budget['cliente_nome'] ?? ($budget['cliente_nome_avulso'] ?? 'Cliente não informado'))),
                'meta' => trim($typeLabel . ($statusLabel !== '' ? ' · ' . $statusLabel : '') . ' · R$ ' . $valueLabel),
                'url' => route('orcamentos.show', (int) ($budget['id'] ?? 0)),
                'icon' => 'bi-receipt',
                'kind' => 'Orçamento',
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchEquipments(string $query, int $limit): array
    {
        $result = $this->equipmentService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $equipment): array {
            $id = (int) ($equipment['id'] ?? 0);
            $type = trim((string) ($equipment['tipo_nome'] ?? ''));
            $brand = trim((string) ($equipment['marca_nome'] ?? ''));
            $model = trim((string) ($equipment['modelo_nome'] ?? ''));
            $client = trim((string) ($equipment['cliente_nome'] ?? ''));
            $serial = trim((string) ($equipment['numero_serie'] ?? ''));
            $summary = trim((string) ($equipment['resumo_tecnico'] ?? ''));
            $identity = implode(' · ', array_values(array_filter(
                [$type, $brand, $model],
                static fn (string $value): bool => $value !== ''
            )));
            $label = $identity !== '' ? $identity : ($summary !== '' ? $summary : ('Equipamento #' . $id));
            $primaryPhotoId = (int) ($equipment['primary_photo_id'] ?? 0);

            return [
                'id' => $id,
                'label' => $label,
                'subtitle' => $serial !== '' ? ('Nº de série: ' . $serial) : 'Número de série não informado',
                'meta' => $summary !== '' && $summary !== $label ? $summary : '',
                'url' => route('equipments.show', $id),
                'icon' => 'bi-laptop',
                'kind' => 'Equipamento',
                'image_url' => $primaryPhotoId > 0
                    ? route('equipments.photos.show', ['equipment' => $id, 'photo' => $primaryPhotoId])
                    : '',
                'facts' => [
                    ['label' => 'Tipo', 'value' => $type !== '' ? $type : 'Não informado'],
                    ['label' => 'Marca', 'value' => $brand !== '' ? $brand : 'Não informada'],
                    ['label' => 'Modelo', 'value' => $model !== '' ? $model : 'Não informado'],
                    ['label' => 'Cliente', 'value' => $client !== '' ? $client : 'Não informado'],
                ],
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchServices(string $query, int $limit): array
    {
        $result = $this->servicoService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $service) use ($query): array {
            return [
                'id' => (int) ($service['id'] ?? 0),
                'label' => trim((string) ($service['nome'] ?? 'Serviço')),
                'subtitle' => trim((string) ($service['descricao'] ?? ($service['tipo_equipamento'] ?? ''))),
                'meta' => trim((string) ($service['status'] ?? '')),
                'url' => DesktopSession::can('servicos', 'editar')
                    ? route('servicos.edit', (int) ($service['id'] ?? 0))
                    : route('servicos.index', ['search' => $query]),
                'icon' => 'bi-gear',
                'kind' => 'Serviço',
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchStock(string $query, int $limit): array
    {
        $result = $this->stockService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $part) use ($query): array {
            $code = trim((string) ($part['codigo'] ?? ''));
            $name = trim((string) ($part['nome'] ?? ''));

            return [
                'id' => (int) ($part['id'] ?? 0),
                'label' => $code !== '' ? $code : ($name !== '' ? $name : 'Peça'),
                'subtitle' => trim((string) ($part['categoria'] ?? ($part['fornecedor'] ?? ''))),
                'meta' => 'QTD: ' . (int) ($part['quantidade_atual'] ?? 0),
                'url' => DesktopSession::can('estoque', 'editar')
                    ? route('estoque.edit', (int) ($part['id'] ?? 0))
                    : route('estoque.index', ['search' => $query]),
                'icon' => 'bi-box-seam',
                'kind' => 'Peça',
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchSuppliers(string $query, int $limit): array
    {
        $result = $this->supplierService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $supplier) use ($query): array {
            $name = trim((string) ($supplier['nome_fantasia'] ?? ''));
            $companyName = trim((string) ($supplier['razao_social'] ?? ''));
            $label = $name !== '' ? $name : ($companyName !== '' ? $companyName : 'Fornecedor');

            return [
                'id' => (int) ($supplier['id'] ?? 0),
                'label' => $label,
                'subtitle' => trim((string) ($supplier['cnpj_cpf'] ?? ($supplier['telefone1'] ?? ''))),
                'meta' => trim((string) ($supplier['cidade'] ?? '')),
                'url' => DesktopSession::can('fornecedores', 'editar')
                    ? route('suppliers.edit', (int) ($supplier['id'] ?? 0))
                    : route('suppliers.index', ['search' => $query]),
                'icon' => 'bi-truck',
                'kind' => 'Fornecedor',
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchUsers(string $query, int $limit): array
    {
        $result = $this->userService->paginate([
            'search' => $query,
            'per_page' => $limit,
        ]);

        return array_map(function (array $user) use ($query): array {
            return [
                'id' => (int) ($user['id'] ?? 0),
                'label' => trim((string) ($user['nome'] ?? 'Usuário')),
                'subtitle' => trim((string) ($user['email'] ?? ($user['telefone'] ?? ''))),
                'meta' => trim((string) ($user['group']['nome'] ?? ($user['perfil'] ?? ''))),
                'url' => route('users.index', ['search' => $query]),
                'icon' => 'bi-person-badge',
                'kind' => 'Usuário',
            ];
        }, $result['items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchGroups(string $query, int $limit): array
    {
        $groups = array_filter($this->groupService->all(), function (array $group) use ($query): bool {
            $haystack = Str::lower(
                trim((string) ($group['nome'] ?? '')) . ' ' . trim((string) ($group['descricao'] ?? ''))
            );

            return Str::contains($haystack, Str::lower($query));
        });

        $groups = array_slice(array_values($groups), 0, $limit);

        return array_map(function (array $group) use ($query): array {
            return [
                'id' => (int) ($group['id'] ?? 0),
                'label' => trim((string) ($group['nome'] ?? 'Grupo')),
                'subtitle' => trim((string) ($group['descricao'] ?? '')),
                'meta' => (bool) ($group['sistema'] ?? false) ? 'Grupo de sistema' : 'Grupo administrativo',
                'url' => route('groups.index', ['search' => $query]),
                'icon' => 'bi-shield-lock',
                'kind' => 'Grupo',
            ];
        }, $groups);
    }

    /**
     * Aceita tanto um array de valores (checkboxes reais, `scope[]=os&scope[]=clientes`)
     * quanto uma string (usada pelo dropdown do topbar, que guarda a seleção num único
     * input hidden como "os,clientes"). Selecionar "tudo" junto de escopos específicos
     * equivale a selecionar só "tudo" (é um superconjunto de qualquer outro escopo).
     *
     * @param string|array<int, string> $scope
     * @return array<int, string>
     */
    private function normalizeScopes(string|array $scope): array
    {
        $values = is_array($scope) ? $scope : explode(',', $scope);
        $values = array_map(static fn ($value): string => trim((string) $value), $values);
        $values = array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));

        $allowed = array_map(fn (array $item): string => $item['value'], $this->scopes());
        $values = array_values(array_intersect($values, $allowed));

        if ($values === [] || in_array('tudo', $values, true)) {
            return ['tudo'];
        }

        return $values;
    }

    /**
     * @param array<int, string> $scopes
     */
    private function scopeAllows(string $module, array $scopes): bool
    {
        if (in_array('tudo', $scopes, true)) {
            return DesktopSession::can($module, 'visualizar');
        }

        return in_array($module, $scopes, true) && DesktopSession::can($module, 'visualizar');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function section(string $key, string $label, string $icon, array $items): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'items' => $items,
        ];
    }
}
