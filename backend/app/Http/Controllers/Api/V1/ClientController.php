<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ClientController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('clientes:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $status = trim((string) $request->query('status', ''));
        $sort = trim((string) $request->query('sort', 'nome'));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $query = Client::query()->withCount(['orders', 'equipments']);

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(nome_razao, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cpf_cnpj, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(rg_ie, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(telefone1, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(telefone2, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(nome_contato, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(telefone_contato, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cep, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(endereco, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(numero, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(complemento, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(referencia, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(bairro, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cidade, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(uf, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(observacoes, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(status_cadastro, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term]);
            });
        }

        if ($status !== '') {
            $query->whereRaw('LOWER(COALESCE(status_cadastro, \'\')) = ?', [mb_strtolower($status)]);
        }

        match ($sort) {
            'nome_desc' => $query->orderByDesc('nome_razao'),
            'recentes' => $query->orderByDesc('id'),
            'recentes_asc' => $query->orderBy('id'),
            default => $query->orderBy('nome_razao'),
        };

        $paginator = $query
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Client $client): array => $this->mapClientSummary($client))
        );

        return $this->success(
            ['clients' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('clientes:criar');

        $payload = $this->validatedClientPayload($request);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $client = Client::query()->create($payload);

        return $this->success(
            ['client' => $this->mapClientDetail($client)],
            201,
            request: $request
        );
    }

    public function show(Request $request, int $client): JsonResponse
    {
        $this->authorize('clientes:visualizar');

        $clientModel = Client::query()->find($client);

        if (! $clientModel instanceof Client) {
            return $this->error(
                'Cliente não encontrado.',
                404,
                'CLIENT_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            ['client' => $this->mapClientDetail($clientModel)],
            request: $request
        );
    }

    public function update(Request $request, int $client): JsonResponse
    {
        $this->authorize('clientes:editar');

        $clientModel = Client::query()->find($client);

        if (! $clientModel instanceof Client) {
            return $this->error(
                'Cliente não encontrado.',
                404,
                'CLIENT_NOT_FOUND',
                null,
                request: $request
            );
        }

        $payload = $this->validatedClientPayload($request, $client);
        $payload['updated_at'] = now();

        $clientModel->fill($payload);
        $clientModel->save();

        return $this->success(
            ['client' => $this->mapClientDetail($clientModel->fresh() ?? $clientModel)],
            request: $request
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapClientSummary(Client $client): array
    {
        return [
            'id' => (int) $client->id,
            'tipo_pessoa' => (string) ($client->tipo_pessoa ?? ''),
            'nome_razao' => (string) ($client->nome_razao ?? ''),
            'cpf_cnpj' => (string) ($client->cpf_cnpj ?? ''),
            'nome_contato' => (string) ($client->nome_contato ?? ''),
            'orders_count' => (int) ($client->orders_count ?? 0),
            'equipments_count' => (int) ($client->equipments_count ?? 0),
            'email' => (string) ($client->email ?? ''),
            'telefone1' => (string) ($client->telefone1 ?? ''),
            'telefone_contato' => (string) ($client->telefone_contato ?? ''),
            'cidade' => (string) ($client->cidade ?? ''),
            'uf' => (string) ($client->uf ?? ''),
            'status_cadastro' => (string) ($client->status_cadastro ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapClientDetail(Client $client): array
    {
        return [
            'id' => (int) $client->id,
            'tipo_pessoa' => (string) ($client->tipo_pessoa ?? ''),
            'nome_razao' => (string) ($client->nome_razao ?? ''),
            'cpf_cnpj' => (string) ($client->cpf_cnpj ?? ''),
            'rg_ie' => (string) ($client->rg_ie ?? ''),
            'email' => (string) ($client->email ?? ''),
            'telefone1' => (string) ($client->telefone1 ?? ''),
            'telefone2' => (string) ($client->telefone2 ?? ''),
            'nome_contato' => (string) ($client->nome_contato ?? ''),
            'telefone_contato' => (string) ($client->telefone_contato ?? ''),
            'cep' => (string) ($client->cep ?? ''),
            'endereco' => (string) ($client->endereco ?? ''),
            'numero' => (string) ($client->numero ?? ''),
            'complemento' => (string) ($client->complemento ?? ''),
            'referencia' => (string) ($client->referencia ?? ''),
            'bairro' => (string) ($client->bairro ?? ''),
            'cidade' => (string) ($client->cidade ?? ''),
            'uf' => (string) ($client->uf ?? ''),
            'observacoes' => (string) ($client->observacoes ?? ''),
            'status_cadastro' => (string) ($client->status_cadastro ?? ''),
            'preferencia_contato' => (string) ($client->preferencia_contato ?? ''),
            'created_at' => $this->formatDateTime($client->created_at),
            'updated_at' => $this->formatDateTime($client->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedClientPayload(Request $request, ?int $ignoreId = null): array
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
            $payload[$field] = $this->normalizeFieldValue($value);
        }

        $payload['tipo_pessoa'] = $payload['tipo_pessoa'] !== null && $payload['tipo_pessoa'] !== ''
            ? $payload['tipo_pessoa']
            : 'fisica';
        $payload['status_cadastro'] = $payload['status_cadastro'] !== null && $payload['status_cadastro'] !== ''
            ? $payload['status_cadastro']
            : 'completo';

        // Armazena CPF/CNPJ apenas com digitos para manter consistencia com a
        // coluna unica e evitar duplicidade por conta da mascara.
        if (array_key_exists('cpf_cnpj', $payload) && $payload['cpf_cnpj'] !== null) {
            $digits = preg_replace('/\D+/', '', (string) $payload['cpf_cnpj']);
            $payload['cpf_cnpj'] = $digits === '' ? null : $digits;
        }

        // Uniqueness manual: a coluna cpf_cnpj possui indice unico. Sem esta
        // verificacao a violacao de integridade estoura como erro 500.
        if (! empty($payload['cpf_cnpj'])) {
            $exists = Client::query()
                ->where('cpf_cnpj', $payload['cpf_cnpj'])
                ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'cpf_cnpj' => 'Este CPF/CNPJ já está cadastrado para outro cliente.',
                ]);
            }
        }

        return $payload;
    }

    private function normalizeFieldValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return (string) $value;
    }
}
