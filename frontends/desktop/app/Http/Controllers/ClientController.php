<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ClientService;
use App\Services\EquipmentService;
use App\Services\FinanceiroService;
use App\Services\OrderService;
use App\Support\DesktopSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ClientController extends DesktopController
{
    public function __construct(
        private readonly ClientService $clientService,
        private readonly OrderService $orderService,
        private readonly EquipmentService $equipmentService,
        private readonly FinanceiroService $financeiroService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'sort' => trim((string) $request->query('sort', 'nome')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->clientService->paginate(array_filter($filters, static fn ($value) => $value !== '' && $value !== 0));

        return view('clients.index', [
            'pageTitle' => 'Clientes',
            'clients' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('clients.create', [
            'pageTitle' => 'Novo cliente',
            'client' => $this->clientFormDefaults(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $this->clientService->create($this->validatedClientPayload($request));

        return redirect()
            ->route('clients.show', $client['id'] ?? 0)
            ->with('success', 'Cliente criado com sucesso.');
    }

    public function quickStore(Request $request): JsonResponse
    {
        try {
            $client = $this->clientService->create($this->validatedQuickClientPayload($request));
        } catch (ApiAuthenticationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Sua sessão expirou. Faça login novamente.',
            ], 401);
        } catch (ApiAuthorizationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Você não tem permissão para executar esta ação.',
            ], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Não foi possível cadastrar o cliente.',
                'errors' => $exception->details() ?? [],
            ], $exception->statusCode() > 0 ? $exception->statusCode() : 422);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Verifique os campos do cliente.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível cadastrar o cliente agora. Tente novamente.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cliente cadastrado com sucesso.',
            'client' => $client,
        ], 201);
    }

    public function show(int $client): View
    {
        $clientData = $this->clientService->find($client);
        abort_if($clientData === [], 404);

        $canViewOrders = DesktopSession::can('os', 'visualizar');
        $canViewEquipments = DesktopSession::can('equipamentos', 'visualizar');
        $canViewFinanceiro = DesktopSession::can('financeiro', 'visualizar');

        $orders = $canViewOrders ? $this->orderService->paginate([
            'client_id' => $client,
            'per_page' => 5,
        ]) : [
            'items' => [],
            'pagination' => [
                'total' => 0,
            ],
        ];

        $equipments = $canViewEquipments ? $this->equipmentService->paginate([
            'client_id' => $client,
            'per_page' => 5,
        ]) : [
            'items' => [],
            'pagination' => [
                'total' => 0,
            ],
        ];

        $financeiro = $canViewFinanceiro ? $this->financeiroService->paginate([
            'cliente_id' => $client,
            'tipo' => 'receber',
            'per_page' => 5,
        ]) : [
            'items' => [],
            'pagination' => [
                'total' => 0,
            ],
        ];

        return view('clients.show', [
            'pageTitle' => 'Detalhe do Cliente',
            'client' => $clientData,
            'orders' => $orders['items'],
            'ordersPagination' => $orders['pagination'],
            'equipments' => $equipments['items'],
            'equipmentsPagination' => $equipments['pagination'],
            'canViewOrders' => $canViewOrders,
            'canViewEquipments' => $canViewEquipments,
            'financeiro' => $financeiro['items'],
            'financeiroPagination' => $financeiro['pagination'],
            'canViewFinanceiro' => $canViewFinanceiro,
            'newOrderUrl' => route('orders.create', ['cliente_id' => $client]),
            'ordersIndexUrl' => route('orders.index', ['client_id' => $client]),
            'equipmentsIndexUrl' => route('equipments.index', ['client_id' => $client]),
            'financeiroIndexUrl' => route('financeiro.index', ['cliente_id' => $client, 'tipo' => 'receber']),
            'editUrl' => route('clients.edit', $client),
        ]);
    }

    public function edit(int $client): View
    {
        $clientData = $this->clientService->find($client);
        abort_if($clientData === [], 404);

        return view('clients.edit', [
            'pageTitle' => 'Editar cliente',
            'client' => $clientData,
        ]);
    }

    public function update(Request $request, int $client): RedirectResponse
    {
        $updated = $this->clientService->update($client, $this->validatedClientPayload($request));

        return redirect()
            ->route('clients.show', $updated['id'] ?? $client)
            ->with('success', 'Cliente atualizado com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedClientPayload(Request $request): array
    {
        $validated = $request->validate([
            'tipo_pessoa' => ['required', 'string', 'max:20'],
            'nome_razao' => ['required', 'string', 'max:100'],
            'cpf_cnpj' => ['nullable', 'string', 'max:20'],
            'rg_ie' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'telefone1' => ['required', 'string', 'max:20'],
            'telefone2' => ['nullable', 'string', 'max:20'],
            'nome_contato' => ['nullable', 'string', 'max:100'],
            'telefone_contato' => ['nullable', 'string', 'max:20'],
            'cep' => ['nullable', 'string', 'max:10'],
            'endereco' => ['nullable', 'string', 'max:100'],
            'numero' => ['nullable', 'string', 'max:10'],
            'complemento' => ['nullable', 'string', 'max:50'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:50'],
            'cidade' => ['nullable', 'string', 'max:50'],
            'uf' => ['nullable', 'string', 'max:2'],
            'observacoes' => ['nullable', 'string'],
            'status_cadastro' => ['required', 'string', 'max:20'],
            'preferencia_contato' => ['nullable', 'string', 'max:50'],
        ], [], [
            'tipo_pessoa' => 'tipo de pessoa',
            'nome_razao' => 'nome / razão social',
            'cpf_cnpj' => 'CPF/CNPJ',
            'rg_ie' => 'RG/IE',
            'email' => 'e-mail',
            'telefone1' => 'telefone principal',
            'telefone2' => 'telefone secundário',
            'nome_contato' => 'nome do contato',
            'telefone_contato' => 'telefone do contato',
            'cep' => 'CEP',
            'endereco' => 'endereço',
            'numero' => 'número',
            'complemento' => 'complemento',
            'referencia' => 'referência',
            'bairro' => 'bairro',
            'cidade' => 'cidade',
            'uf' => 'UF',
            'observacoes' => 'observações',
            'status_cadastro' => 'situação cadastral',
            'preferencia_contato' => 'preferência de contato',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            $payload[$field] = $this->normalizeValue($value);
        }

        $payload['tipo_pessoa'] = $payload['tipo_pessoa'] !== null && $payload['tipo_pessoa'] !== ''
            ? $payload['tipo_pessoa']
            : 'fisica';
        $payload['status_cadastro'] = $payload['status_cadastro'] !== null && $payload['status_cadastro'] !== ''
            ? $payload['status_cadastro']
            : 'completo';

        // Title Case do nome/razao social apenas para pessoa fisica; razao social de
        // empresa (juridica) fica como digitada. Nome de contato e sempre pessoa.
        if ($payload['tipo_pessoa'] === 'fisica') {
            $payload['nome_razao'] = $this->formatPersonName($payload['nome_razao'] ?? null);
        }
        $payload['nome_contato'] = $this->formatPersonName($payload['nome_contato'] ?? null);
        $payload['telefone1'] = $this->formatBrazilPhone($payload['telefone1'] ?? null);
        $payload['telefone2'] = $this->formatBrazilPhone($payload['telefone2'] ?? null);
        $payload['telefone_contato'] = $this->formatBrazilPhone($payload['telefone_contato'] ?? null);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedQuickClientPayload(Request $request): array
    {
        $validated = $request->validate([
            'nome_razao' => ['required', 'string', 'max:100'],
            'telefone1' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'cpf_cnpj' => ['nullable', 'string', 'max:20'],
            'nome_contato' => ['nullable', 'string', 'max:100'],
            'telefone_contato' => ['nullable', 'string', 'max:20'],
            'cep' => ['nullable', 'string', 'max:10'],
            'endereco' => ['nullable', 'string', 'max:100'],
            'numero' => ['nullable', 'string', 'max:10'],
            'bairro' => ['nullable', 'string', 'max:50'],
            'cidade' => ['nullable', 'string', 'max:50'],
            'uf' => ['nullable', 'string', 'max:2'],
        ], [], [
            'nome_razao' => 'nome / razão social',
            'telefone1' => 'telefone principal',
            'email' => 'e-mail',
            'cpf_cnpj' => 'CPF/CNPJ',
            'nome_contato' => 'nome do contato',
            'telefone_contato' => 'telefone do contato',
            'cep' => 'CEP',
            'endereco' => 'endereço',
            'numero' => 'número',
            'bairro' => 'bairro',
            'cidade' => 'cidade',
            'uf' => 'UF',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            $payload[$field] = $this->normalizeValue($value);
        }

        // Cadastro rapido e sempre pessoa fisica: padroniza nome e telefones.
        $payload['nome_razao'] = $this->formatPersonName($payload['nome_razao'] ?? null);
        $payload['nome_contato'] = $this->formatPersonName($payload['nome_contato'] ?? null);
        $payload['telefone1'] = $this->formatBrazilPhone($payload['telefone1'] ?? null);
        $payload['telefone_contato'] = $this->formatBrazilPhone($payload['telefone_contato'] ?? null);

        $payload['tipo_pessoa'] = 'fisica';
        $payload['status_cadastro'] = 'completo';

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientFormDefaults(): array
    {
        return [
            'tipo_pessoa' => 'fisica',
            'nome_razao' => '',
            'cpf_cnpj' => '',
            'rg_ie' => '',
            'email' => '',
            'telefone1' => '',
            'telefone2' => '',
            'nome_contato' => '',
            'telefone_contato' => '',
            'cep' => '',
            'endereco' => '',
            'numero' => '',
            'complemento' => '',
            'referencia' => '',
            'bairro' => '',
            'cidade' => '',
            'uf' => '',
            'observacoes' => '',
            'status_cadastro' => 'completo',
            'preferencia_contato' => '',
        ];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Conectores de nomes em portugues que permanecem em minusculo no Title Case,
     * exceto quando sao a primeira palavra do nome.
     */
    private const NAME_CONNECTORS = ['de', 'da', 'do', 'das', 'dos', 'e'];

    /**
     * Padroniza nome de pessoa em Title Case pt-BR (ex.: "joao da silva" ->
     * "Joao da Silva"), mantendo conectores em minusculo. Espacos multiplos sao
     * colapsados. Usado apenas para pessoa fisica e para nome de contato.
     */
    private function formatPersonName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($collapsed === '') {
            return null;
        }

        $words = explode(' ', $collapsed);

        $formatted = array_map(function (string $word, int $index): string {
            $lower = mb_strtolower($word, 'UTF-8');

            if ($index > 0 && in_array($lower, self::NAME_CONNECTORS, true)) {
                return $lower;
            }

            $first = mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8');

            return $first . mb_substr($lower, 1, null, 'UTF-8');
        }, $words, array_keys($words));

        return implode(' ', $formatted);
    }

    /**
     * Padroniza telefone brasileiro com mascara (DDD entre parenteses):
     * celular (11 digitos) "(21) 98061-4757", fixo (10 digitos) "(22) 2627-4120".
     * Remove um codigo de pais "55" excedente. Se nao bater 10/11 digitos, devolve
     * o valor saneado (trim) sem mascara.
     */
    private function formatBrazilPhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ((strlen($digits) === 12 || strlen($digits) === 13) && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7, 4));
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6, 4));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
