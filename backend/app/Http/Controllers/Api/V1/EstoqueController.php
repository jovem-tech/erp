<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EquipmentType;
use App\Models\Movimentacao;
use App\Models\Peca;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class EstoqueController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $ativo = $request->query('active');
        $categoria = trim((string) $request->query('categoria', ''));
        $tipoEquipamento = trim((string) $request->query('tipo_equipamento', ''));
        $status = trim((string) $request->query('status', ''));

        $query = Peca::query();

        if ($search !== '') {
            $query->search($search);
        }

        if ($ativo !== null && $ativo !== '') {
            $query->where('ativo', filter_var($ativo, FILTER_VALIDATE_BOOL));
        }

        if ($categoria !== '') {
            $query->where('categoria', $categoria);
        }

        if ($tipoEquipamento !== '') {
            $query->where('tipo_equipamento', $tipoEquipamento);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query
            ->orderBy('nome')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Peca $peca): array => $this->mapPecaSummary($peca))
        );

        return $this->success(
            ['pecas' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function formData(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        return $this->success([
            'form' => [
                'codigo_sugerido' => Peca::generateCodigo(),
                'tipos_equipamento' => Peca::tiposEquipamentoAtivos(),
                'categorias' => Peca::categoriasAtivas(),
                'status_options' => [
                    ['value' => 'ativo', 'label' => 'Ativo'],
                    ['value' => 'encerrado', 'label' => 'Encerrado'],
                ],
            ],
        ], request: $request);
    }

    public function show(Request $request, int $peca): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $part = Peca::query()->find($peca);

        if (! $part instanceof Peca) {
            return $this->error(
                'Peça não encontrada.',
                404,
                'PART_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success([
            'peca' => $this->mapPecaDetail($part),
        ], request: $request);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $parts = Peca::query()
            ->where('ativo', 1)
            ->whereColumn('quantidade_atual', '<=', 'estoque_minimo')
            ->orderBy('nome')
            ->get();

        return $this->success([
            'pecas' => $parts->map(fn (Peca $peca): array => $this->mapPecaSummary($peca))->values()->all(),
        ], request: $request);
    }

    public function movements(Request $request, int $peca): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $part = Peca::query()->find($peca);

        if (! $part instanceof Peca) {
            return $this->error(
                'Peça não encontrada.',
                404,
                'PART_NOT_FOUND',
                null,
                request: $request
            );
        }

        $movements = Movimentacao::query()
            ->select([
                'movimentacoes.id',
                'movimentacoes.peca_id',
                'movimentacoes.os_id',
                'movimentacoes.tipo',
                'movimentacoes.quantidade',
                'movimentacoes.motivo',
                'movimentacoes.responsavel_id',
                'movimentacoes.created_at',
                'usuarios.nome as responsavel_nome',
                'os.numero_os',
            ])
            ->leftJoin('usuarios', 'usuarios.id', '=', 'movimentacoes.responsavel_id')
            ->leftJoin('os', 'os.id', '=', 'movimentacoes.os_id')
            ->where('movimentacoes.peca_id', $peca)
            ->orderByDesc('movimentacoes.created_at')
            ->get();

        return $this->success([
            'peca' => $this->mapPecaDetail($part),
            'movimentacoes' => $movements->map(fn ($movement): array => $this->mapMovimentacao($movement))->values()->all(),
        ], request: $request);
    }

    public function storeMovement(Request $request, int $peca): JsonResponse
    {
        $this->authorize('estoque:editar');

        $part = Peca::query()->find($peca);

        if (! $part instanceof Peca) {
            return $this->error(
                'Peça não encontrada.',
                404,
                'PART_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'tipo' => ['required', 'string', 'in:entrada,saida,ajuste'],
            'quantidade' => ['required', 'integer', 'min:1'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'os_id' => ['nullable', 'integer', 'exists:os,id'],
        ]);

        $newQuantity = (int) ($part->quantidade_atual ?? 0);
        $quantity = (int) $validated['quantidade'];

        if ($validated['tipo'] === 'entrada') {
            $newQuantity += $quantity;
        } elseif ($validated['tipo'] === 'saida') {
            $newQuantity -= $quantity;
        } else {
            $newQuantity = $quantity;
        }

        $newQuantity = max(0, $newQuantity);

        DB::transaction(static function () use ($part, $validated, $newQuantity, $request): void {
            Movimentacao::query()->create([
                'peca_id' => (int) $part->id,
                'os_id' => isset($validated['os_id']) ? (int) $validated['os_id'] : null,
                'tipo' => (string) $validated['tipo'],
                'quantidade' => (int) $validated['quantidade'],
                'motivo' => $validated['motivo'] ?? null,
                'responsavel_id' => (int) $request->user()->id,
                'created_at' => now(),
            ]);

            $part->forceFill([
                'quantidade_atual' => $newQuantity,
                'updated_at' => now(),
            ])->save();
        });

        return $this->success([
            'peca' => $this->mapPecaDetail($part->fresh() ?? $part),
        ], request: $request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('estoque:criar');

        $part = Peca::query()->create($this->validatedPayload($request, true));

        return $this->success([
            'peca' => $this->mapPecaDetail($part->fresh() ?? $part),
        ], 201, request: $request);
    }

    public function update(Request $request, int $peca): JsonResponse
    {
        $this->authorize('estoque:editar');

        $part = Peca::query()->find($peca);

        if (! $part instanceof Peca) {
            return $this->error(
                'Peça não encontrada.',
                404,
                'PART_NOT_FOUND',
                null,
                request: $request
            );
        }

        $part->fill($this->validatedPayload($request, false));
        $part->save();

        return $this->success([
            'peca' => $this->mapPecaDetail($part->fresh() ?? $part),
        ], request: $request);
    }

    public function close(Request $request, int $peca): JsonResponse
    {
        $this->authorize('estoque:encerrar');

        $part = Peca::query()->find($peca);

        if (! $part instanceof Peca) {
            return $this->error(
                'Peça não encontrada.',
                404,
                'PART_NOT_FOUND',
                null,
                request: $request
            );
        }

        $part->forceFill([
            'ativo' => false,
            'status' => 'encerrado',
            'encerrado_em' => now(),
            'updated_at' => now(),
        ])->save();

        return $this->success([
            'peca' => $this->mapPecaDetail($part->fresh() ?? $part),
        ], request: $request);
    }

    public function destroy(Request $request, int $peca): JsonResponse
    {
        $this->authorize('estoque:excluir');

        $part = Peca::query()->find($peca);

        if (! $part instanceof Peca) {
            return $this->error(
                'Peça não encontrada.',
                404,
                'PART_NOT_FOUND',
                null,
                request: $request
            );
        }

        $part->forceFill([
            'ativo' => false,
            'updated_at' => now(),
        ])->save();

        return $this->success([
            'deleted' => true,
            'peca_id' => $peca,
        ], request: $request);
    }

    public function exportCsv(Request $request)
    {
        $this->authorize('estoque:exportar');

        $parts = Peca::query()->orderBy('nome')->get();

        $filename = 'estoque_pecas_' . now()->format('Y-m-d_H-i') . '.csv';

        return response()->streamDownload(function () use ($parts): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['codigo', 'codigo_fabricante', 'nome', 'categoria', 'tipo_equipamento', 'modelos_compativeis', 'fornecedor', 'localizacao', 'preco_custo', 'preco_venda', 'quantidade_atual', 'estoque_minimo', 'estoque_maximo', 'status', 'observacoes'], ';');

            foreach ($parts as $part) {
                fputcsv($handle, [
                    (string) ($part->codigo ?? ''),
                    (string) ($part->codigo_fabricante ?? ''),
                    (string) ($part->nome ?? ''),
                    (string) ($part->categoria ?? ''),
                    (string) ($part->tipo_equipamento ?? ''),
                    (string) ($part->modelos_compativeis ?? ''),
                    (string) ($part->fornecedor ?? ''),
                    (string) ($part->localizacao ?? ''),
                    number_format((float) ($part->preco_custo ?? 0), 2, ',', '.'),
                    number_format((float) ($part->preco_venda ?? 0), 2, ',', '.'),
                    (int) ($part->quantidade_atual ?? 0),
                    (int) ($part->estoque_minimo ?? 0),
                    (int) ($part->estoque_maximo ?? 0),
                    (string) ($part->status ?? ''),
                    (string) ($part->observacoes ?? ''),
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadCsvTemplate(Request $request)
    {
        $this->authorize('estoque:importar');

        $filename = 'modelo_importacao_estoque.csv';

        return response()->streamDownload(static function (): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['codigo', 'codigo_fabricante', 'nome', 'categoria', 'tipo_equipamento', 'modelos_compativeis', 'fornecedor', 'localizacao', 'preco_custo', 'preco_venda', 'quantidade_atual', 'estoque_minimo', 'estoque_maximo', 'status', 'observacoes'], ';');
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $this->authorize('estoque:importar');

        $validated = $request->validate([
            'arquivo' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['arquivo'];
        $imported = $this->importFromFile($file);

        return $this->success([
            'imported' => $imported,
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $includeCode = false): array
    {
        $rules = [
            'codigo_fabricante' => ['nullable', 'string', 'max:120'],
            'nome' => ['required', 'string', 'max:160'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
            'modelos_compativeis' => ['nullable', 'string'],
            'fornecedor' => ['nullable', 'string', 'max:120'],
            'localizacao' => ['nullable', 'string', 'max:120'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'quantidade_atual' => ['nullable', 'integer', 'min:0'],
            'estoque_minimo' => ['nullable', 'integer', 'min:0'],
            'estoque_maximo' => ['nullable', 'integer', 'min:0'],
            'observacoes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:30'],
            'ativo' => ['nullable', 'boolean'],
        ];

        if ($includeCode) {
            $rules['codigo'] = ['nullable', 'string', 'max:120'];
        }

        $validated = $request->validate($rules);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = in_array($field, ['preco_custo', 'preco_venda'], true)
                ? $this->normalizeDecimal($value)
                : $this->normalizeText($value);
        }

        $payload['codigo'] = $includeCode
            ? ($this->normalizeText($validated['codigo'] ?? null) ?: Peca::generateCodigo())
            : ($this->normalizeText($validated['codigo'] ?? null));
        $payload['codigo_fabricante'] = $this->normalizeText($validated['codigo_fabricante'] ?? null);
        $payload['nome'] = $this->normalizeText($validated['nome'] ?? '');
        $payload['categoria'] = $this->normalizeText($validated['categoria'] ?? null);
        $payload['tipo_equipamento'] = $this->normalizeText($validated['tipo_equipamento'] ?? null);
        $payload['modelos_compativeis'] = $this->normalizeText($validated['modelos_compativeis'] ?? null);
        $payload['fornecedor'] = $this->normalizeText($validated['fornecedor'] ?? null);
        $payload['localizacao'] = $this->normalizeText($validated['localizacao'] ?? null);
        $payload['preco_custo'] = $this->normalizeDecimal($validated['preco_custo'] ?? 0);
        $payload['preco_venda'] = $this->normalizeDecimal($validated['preco_venda'] ?? 0);
        $payload['quantidade_atual'] = (int) ($validated['quantidade_atual'] ?? 0);
        $payload['estoque_minimo'] = (int) ($validated['estoque_minimo'] ?? 0);
        $payload['estoque_maximo'] = (int) ($validated['estoque_maximo'] ?? 0);
        $payload['observacoes'] = $this->normalizeText($validated['observacoes'] ?? null);
        $payload['status'] = $this->normalizeStatus($validated['status'] ?? null);
        $payload['ativo'] = $request->boolean('ativo', true);

        return $payload;
    }

    private function importFromFile(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return 0;
        }

        $headers = [];
        $imported = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($headers === []) {
                $headers = array_map(static function ($value): string {
                    $header = (string) $value;
                    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

                    return mb_strtolower(trim($header));
                }, $row);
                continue;
            }

            $data = [];
            foreach ($headers as $index => $header) {
                $data[$header] = $row[$index] ?? null;
            }

            $payload = [
                'codigo' => $this->normalizeText($data['codigo'] ?? null) ?: Peca::generateCodigo(),
                'codigo_fabricante' => $this->normalizeText($data['codigo_fabricante'] ?? null),
                'nome' => $this->normalizeText($data['nome'] ?? ''),
                'categoria' => $this->normalizeText($data['categoria'] ?? null),
                'tipo_equipamento' => $this->normalizeText($data['tipo_equipamento'] ?? null),
                'modelos_compativeis' => $this->normalizeText($data['modelos_compativeis'] ?? null),
                'fornecedor' => $this->normalizeText($data['fornecedor'] ?? null),
                'localizacao' => $this->normalizeText($data['localizacao'] ?? null),
                'preco_custo' => $this->normalizeDecimal($data['preco_custo'] ?? 0),
                'preco_venda' => $this->normalizeDecimal($data['preco_venda'] ?? 0),
                'quantidade_atual' => (int) ($data['quantidade_atual'] ?? 0),
                'estoque_minimo' => (int) ($data['estoque_minimo'] ?? 0),
                'estoque_maximo' => (int) ($data['estoque_maximo'] ?? 0),
                'status' => $this->normalizeStatus($data['status'] ?? null),
                'observacoes' => $this->normalizeText($data['observacoes'] ?? null),
                'ativo' => true,
            ];

            if ($payload['nome'] === '') {
                continue;
            }

            Peca::query()->create($payload);
            $imported++;
        }

        fclose($handle);

        return $imported;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPecaSummary(Peca $peca): array
    {
        return [
            'id' => (int) $peca->id,
            'codigo' => (string) ($peca->codigo ?? ''),
            'codigo_fabricante' => (string) ($peca->codigo_fabricante ?? ''),
            'nome' => (string) ($peca->nome ?? ''),
            'categoria' => (string) ($peca->categoria ?? ''),
            'tipo_equipamento' => (string) ($peca->tipo_equipamento ?? ''),
            'modelos_compativeis' => (string) ($peca->modelos_compativeis ?? ''),
            'fornecedor' => (string) ($peca->fornecedor ?? ''),
            'localizacao' => (string) ($peca->localizacao ?? ''),
            'preco_custo' => (float) ($peca->preco_custo ?? 0),
            'preco_venda' => (float) ($peca->preco_venda ?? 0),
            'quantidade_atual' => (int) ($peca->quantidade_atual ?? 0),
            'estoque_minimo' => (int) ($peca->estoque_minimo ?? 0),
            'estoque_maximo' => (int) ($peca->estoque_maximo ?? 0),
            'ativo' => (bool) ($peca->ativo ?? false),
            'status' => (string) ($peca->status ?? 'ativo'),
            'encerrado_em' => $this->formatDateTime($peca->encerrado_em),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPecaDetail(Peca $peca): array
    {
        return $this->mapPecaSummary($peca) + [
            'observacoes' => (string) ($peca->observacoes ?? ''),
            'created_at' => $this->formatDateTime($peca->created_at),
            'updated_at' => $this->formatDateTime($peca->updated_at),
            'movimentacoes' => $peca->movimentacoes()
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (Movimentacao $movimentacao): array => $this->mapMovimentacao($movimentacao))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param mixed $movement
     * @return array<string, mixed>
     */
    private function mapMovimentacao(mixed $movement): array
    {
        $tipo = (string) data_get($movement, 'tipo', '');

        return [
            'id' => (int) data_get($movement, 'id', 0),
            'peca_id' => (int) data_get($movement, 'peca_id', 0),
            'os_id' => data_get($movement, 'os_id') !== null ? (int) data_get($movement, 'os_id') : null,
            'numero_os' => (string) data_get($movement, 'numero_os', ''),
            'tipo' => $tipo,
            'tipo_label' => match ($tipo) {
                'entrada' => 'Entrada',
                'saida' => 'Saída',
                default => 'Ajuste',
            },
            'quantidade' => (int) data_get($movement, 'quantidade', 0),
            'motivo' => (string) data_get($movement, 'motivo', ''),
            'responsavel_id' => data_get($movement, 'responsavel_id') !== null ? (int) data_get($movement, 'responsavel_id') : null,
            'responsavel_nome' => (string) data_get($movement, 'responsavel_nome', ''),
            'created_at' => $this->formatDateTime(data_get($movement, 'created_at')),
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : trim((string) $value);
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeDecimal(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(['.', ','], ['', '.'], (string) $value);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = mb_strtolower(trim((string) $value));

        return in_array($status, ['ativo', 'encerrado', 'inativo'], true) ? $status : 'ativo';
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (Throwable) {
                return $value;
            }
        }

        return null;
    }
}
