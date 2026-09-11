<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EquipmentType;
use App\Models\EstoqueCategoria;
use App\Models\EstoqueReserva;
use App\Models\EstoqueSubcategoria;
use App\Models\Movimentacao;
use App\Models\Peca;
use App\Models\User;
use App\Services\Estoque\EstoqueMovimentacaoService;
use App\Services\Estoque\SaldoInsuficienteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class EstoqueController extends BaseApiController
{
    public function __construct(
        // specs/040: `storeMovement()` era a quarta porta de escrita de saldo,
        // e a unica sem lock. Agora entrada/saida passam pelo motor unico.
        private readonly EstoqueMovimentacaoService $estoqueMovimentacaoService,
    ) {
    }

    /**
     * Colunas novas (`grupo`/`estoque_categoria`/`estoque_subcategoria`) vão
     * ao final, opcionais no import — casadas por nome contra a árvore ativa;
     * se não baterem, a peça importada nasce sem classificação, igual às
     * peças legadas nunca reclassificadas.
     *
     * @var array<int, string>
     */
    private const CSV_COLUMNS = [
        'codigo', 'codigo_fabricante', 'nome', 'categoria', 'tipo_equipamento',
        'modelos_compativeis', 'fornecedor', 'localizacao', 'preco_custo', 'preco_venda',
        'quantidade_atual', 'estoque_minimo', 'estoque_maximo', 'status', 'observacoes',
        'grupo', 'estoque_categoria', 'estoque_subcategoria',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $ativo = $request->query('active');
        $tipoEquipamentoId = (int) $request->query('tipo_equipamento_id', 0);
        $estoqueCategoriaId = (int) $request->query('estoque_categoria_id', 0);
        $estoqueSubcategoriaId = (int) $request->query('estoque_subcategoria_id', 0);
        $status = trim((string) $request->query('status', ''));

        $query = Peca::query()->with([
            'tipoEquipamento:id,nome',
            'estoqueCategoria:id,nome',
            'estoqueSubcategoria:id,nome',
        ]);

        if ($search !== '') {
            $query->search($search);
        }

        if ($ativo !== null && $ativo !== '') {
            $query->where('ativo', filter_var($ativo, FILTER_VALIDATE_BOOL));
        }

        if ($tipoEquipamentoId > 0) {
            $query->where('tipo_equipamento_id', $tipoEquipamentoId);
        }

        if ($estoqueCategoriaId > 0) {
            $query->where('estoque_categoria_id', $estoqueCategoriaId);
        }

        if ($estoqueSubcategoriaId > 0) {
            $query->where('estoque_subcategoria_id', $estoqueSubcategoriaId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        // Mesmo criterio de lowStock() e de Peca::estoqueBaixo(): o alerta do
        // dashboard abre esta listagem com estoque_baixo=1 e a contagem tem de
        // bater com o numero mostrado no painel.
        if (filter_var($request->query('estoque_baixo'), FILTER_VALIDATE_BOOL)) {
            $query->whereColumn('quantidade_atual', '<=', 'estoque_minimo');
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
                // Legado: alimentam os campos de texto livre que ainda não
                // migraram para a árvore nova (ex.: modal de serviço).
                'tipos_equipamento' => Peca::tiposEquipamentoAtivos(),
                'categorias' => Peca::categoriasAtivas(),
                // Taxonomia de estoque (Grupo → Categoria → Subcategoria).
                // Vem tudo de uma vez, achatado com o id do pai em cada
                // registro, para os 3 selects em cascata montarem no cliente
                // sem round-trip extra.
                'grupos' => EquipmentType::query()
                    ->where('ativo', 1)
                    ->orderBy('nome')
                    ->get(['id', 'nome'])
                    ->map(static fn (EquipmentType $tipo): array => [
                        'id' => (int) $tipo->id,
                        'nome' => (string) $tipo->nome,
                    ])
                    ->values()
                    ->all(),
                'estoque_categorias' => EstoqueCategoria::activeOptions(),
                'estoque_subcategorias' => EstoqueSubcategoria::activeOptions(),
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
            ->with(['tipoEquipamento:id,nome', 'estoqueCategoria:id,nome', 'estoqueSubcategoria:id,nome'])
            ->where('ativo', 1)
            ->whereColumn('quantidade_atual', '<=', 'estoque_minimo')
            ->orderBy('nome')
            ->limit(500)
            ->get();

        return $this->success([
            'pecas' => $parts->map(fn (Peca $peca): array => $this->mapPecaSummary($peca))->values()->all(),
        ], request: $request);
    }

    /**
     * Pecas a comprar (specs/040).
     *
     * Consolida, sobre TODOS os orcamentos com reserva ativa, o que foi
     * prometido ao cliente e nao existe na gaveta. E a outra metade do pedido
     * do dono: "deve haver um jeito de saber se a peca esta disponivel no
     * estoque ou precisa ser adquirida, encomendada".
     *
     * A conta e por peca e nao por orcamento de proposito — quem vai comprar
     * precisa de UMA linha por peca com o total a adquirir, nao de N linhas
     * para somar na mao.
     */
    public function toBuy(Request $request): JsonResponse
    {
        $this->authorize('estoque:visualizar');

        $reservas = EstoqueReserva::query()
            ->where('status', EstoqueReserva::STATUS_ATIVA)
            ->selectRaw('peca_id, COALESCE(SUM(quantidade - quantidade_consumida), 0) as reservado')
            ->groupBy('peca_id')
            ->get()
            ->mapWithKeys(static fn ($linha): array => [(int) $linha->peca_id => (float) $linha->reservado])
            ->all();

        if ($reservas === []) {
            return $this->success(['pecas' => [], 'total_itens' => 0], request: $request);
        }

        $pecas = Peca::query()
            ->whereIn('id', array_keys($reservas))
            ->get()
            ->keyBy('id');

        // Quem segura cada peca: o operador precisa saber de qual cliente e de
        // qual aparelho e a promessa antes de decidir a compra.
        $porPeca = EstoqueReserva::query()
            ->where('estoque_reservas.status', EstoqueReserva::STATUS_ATIVA)
            ->whereIn('estoque_reservas.peca_id', array_keys($reservas))
            ->leftJoin('orcamentos', 'orcamentos.id', '=', 'estoque_reservas.orcamento_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'orcamentos.cliente_id')
            ->orderBy('estoque_reservas.peca_id')
            ->orderBy('estoque_reservas.id')
            ->get([
                'estoque_reservas.peca_id',
                'estoque_reservas.orcamento_id',
                'estoque_reservas.os_id',
                'estoque_reservas.quantidade',
                'estoque_reservas.quantidade_consumida',
                'estoque_reservas.expira_em',
                'orcamentos.numero as orcamento_numero',
                'orcamentos.status as orcamento_status',
                'orcamentos.cliente_nome_avulso',
                'clientes.nome_razao as cliente_nome',
            ])
            ->groupBy(static fn ($linha): int => (int) $linha->peca_id);

        $itens = [];

        foreach ($reservas as $pecaId => $reservado) {
            $peca = $pecas->get($pecaId);

            if (! $peca instanceof Peca) {
                continue;
            }

            $saldo = round((float) ($peca->quantidade_atual ?? 0), 4);
            $falta = round($reservado - $saldo, 4);

            // Prometido dentro do que existe na gaveta nao e' compra.
            if ($falta <= 0) {
                continue;
            }

            $itens[] = [
                'peca_id' => (int) $peca->id,
                'codigo' => (string) ($peca->codigo ?? ''),
                'nome' => (string) ($peca->nome ?? ''),
                'unidade' => (string) ($peca->unidade ?? 'UN'),
                'fornecedor' => (string) ($peca->fornecedor ?? ''),
                'localizacao' => (string) ($peca->localizacao ?? ''),
                'preco_custo' => (float) ($peca->preco_custo ?? 0),
                'quantidade_atual' => $saldo,
                'reservado' => round($reservado, 4),
                'falta' => $falta,
                // Custo estimado da compra, para o operador priorizar.
                'custo_estimado' => round($falta * (float) ($peca->preco_custo ?? 0), 2),
                'orcamentos' => $porPeca->get($pecaId, collect())
                    ->map(static fn ($linha): array => [
                        'orcamento_id' => (int) $linha->orcamento_id,
                        'numero' => (string) ($linha->orcamento_numero ?? ''),
                        'status' => (string) ($linha->orcamento_status ?? ''),
                        'os_id' => $linha->os_id !== null ? (int) $linha->os_id : null,
                        'cliente' => (string) ($linha->cliente_nome ?: $linha->cliente_nome_avulso ?: ''),
                        'quantidade' => round(
                            (float) $linha->quantidade - (float) $linha->quantidade_consumida,
                            4
                        ),
                        'expira_em' => $linha->expira_em !== null
                            ? Carbon::parse((string) $linha->expira_em)->toIso8601String()
                            : null,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        // Maior falta primeiro: e a peca que trava mais promessa.
        usort($itens, static fn (array $a, array $b): int => $b['falta'] <=> $a['falta']);

        return $this->success([
            'pecas' => $itens,
            'total_itens' => count($itens),
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
                // Sem isto toda saída de venda de balcão apareceria na ficha da
                // peça sem origem (specs/027-vendas-balcao-pdv).
                'movimentacoes.venda_id',
                'vendas.numero as venda_numero',
            ])
            ->leftJoin('usuarios', 'usuarios.id', '=', 'movimentacoes.responsavel_id')
            ->leftJoin('os', 'os.id', '=', 'movimentacoes.os_id')
            ->leftJoin('vendas', 'vendas.id', '=', 'movimentacoes.venda_id')
            ->where('movimentacoes.peca_id', $peca)
            ->orderByDesc('movimentacoes.created_at')
            ->limit(500)
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
            // numeric, nao integer: a coluna virou DECIMAL(14,4) na migration
            // 2026_08_27_000001 justamente para aceitar insumo fracionado
            // (0,5 m de cabo). O piso e a menor fracao representavel.
            'quantidade' => ['required', 'numeric', 'min:0.0001'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'os_id' => ['nullable', 'integer', 'exists:os,id'],
        ]);

        $quantity = round((float) $validated['quantidade'], 4);
        $tipo = (string) $validated['tipo'];
        $permitirNegativo = $request->boolean('confirmar_saldo_negativo');

        // specs/040 — este metodo era a QUARTA porta de escrita de saldo, e a
        // unica ainda sem lock: lia `quantidade_atual`, calculava em PHP e
        // gravava de volta, truncando em zero em silencio. Enquanto ele
        // existisse assim, qualquer reserva era furavel por uma movimentacao
        // manual. `entrada`/`saida` passam a ir pelo motor unico, que confere o
        // DISPONIVEL (saldo menos reservado) e trava as linhas em ordem.
        if ($tipo !== 'ajuste') {
            try {
                $this->estoqueMovimentacaoService->registrarLote(
                    [[
                        'peca_id' => (int) $part->id,
                        'quantidade' => $quantity,
                        'os_id' => isset($validated['os_id']) ? (int) $validated['os_id'] : null,
                        'motivo' => $validated['motivo'] ?? null,
                    ]],
                    [
                        'tipo' => $tipo,
                        'motivo' => (string) ($validated['motivo'] ?? ''),
                        'responsavel_id' => (int) $request->user()->id,
                    ],
                    $permitirNegativo
                );
            } catch (SaldoInsuficienteException $exception) {
                return $this->error(
                    $exception->getMessage(),
                    422,
                    'ESTOQUE_INSUFICIENTE',
                    ['itens' => $exception->faltas()],
                    request: $request
                );
            }

            return $this->success([
                'peca' => $this->mapPecaDetail($part->fresh() ?? $part),
            ], request: $request);
        }

        // `ajuste` fixa o saldo num valor absoluto — o motor nao suporta isso
        // (ele so soma e subtrai) e a contagem de inventario e da 036. Fica
        // aqui, mas nao pode mais atropelar reserva: contar 2 numa peca com 5
        // prometidos deixaria promessa sem lastro.
        $reservado = (float) ($part->quantidade_reservada ?? 0);

        if ($quantity < $reservado && ! $permitirNegativo) {
            return $this->error(
                sprintf(
                    'Esta peça tem %s reservada(s) para orçamento. Ajustar o saldo para %s deixaria reserva sem lastro.',
                    rtrim(rtrim(number_format($reservado, 4, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format($quantity, 4, ',', '.'), '0'), ',')
                ),
                422,
                'ESTOQUE_AJUSTE_ABAIXO_DA_RESERVA',
                ['itens' => [[
                    'peca_id' => (int) $part->id,
                    'codigo' => (string) ($part->codigo ?? ''),
                    'nome' => (string) ($part->nome ?? ''),
                    'disponivel' => $reservado,
                    'solicitado' => $quantity,
                ]]],
                request: $request
            );
        }

        DB::transaction(static function () use ($part, $validated, $quantity, $request): void {
            Movimentacao::query()->create([
                'peca_id' => (int) $part->id,
                'os_id' => isset($validated['os_id']) ? (int) $validated['os_id'] : null,
                'tipo' => 'ajuste',
                'quantidade' => $quantity,
                'motivo' => $validated['motivo'] ?? null,
                'responsavel_id' => (int) $request->user()->id,
                'created_at' => now(),
            ]);

            $part->forceFill([
                'quantidade_atual' => $quantity,
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

        // specs/040: saldo nao se edita por formulario de cadastro — se
        // editasse, qualquer PATCH passaria por cima de uma reserva ativa e a
        // reserva viraria teatro. Recusa explicita, e nao omissao silenciosa,
        // pelo mesmo motivo que a 039 recusa `itens_estoque` no update: o
        // cliente precisa saber que o campo foi ignorado.
        if ($request->has('quantidade_atual')) {
            return $this->error(
                'A quantidade em estoque não pode ser alterada pelo cadastro da peça. Use a movimentação de estoque (entrada, saída ou ajuste).',
                422,
                'PART_QUANTITY_IMMUTABLE',
                ['quantidade_atual' => ['Ajuste o saldo por movimentação de estoque.']],
                request: $request
            );
        }

        $part->fill($this->validatedPayload($request, false, false));
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

        $parts = Peca::query()
            ->with(['tipoEquipamento:id,nome', 'estoqueCategoria:id,nome', 'estoqueSubcategoria:id,nome'])
            ->orderBy('nome')
            ->get();

        $filename = 'estoque_pecas_' . now()->format('Y-m-d_H-i') . '.csv';

        return response()->streamDownload(function () use ($parts): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::CSV_COLUMNS, ';');

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
                    $this->formatQuantidadeCsv($part->quantidade_atual ?? 0),
                    $this->formatQuantidadeCsv($part->estoque_minimo ?? 0),
                    $this->formatQuantidadeCsv($part->estoque_maximo ?? 0),
                    (string) ($part->status ?? ''),
                    (string) ($part->observacoes ?? ''),
                    // Taxonomia nova, colunas no final — não desloca nada que
                    // uma planilha externa já leia por posição. Em branco
                    // para quem nunca foi classificado pela árvore.
                    (string) ($part->tipoEquipamento?->nome ?? ''),
                    (string) ($part->estoqueCategoria?->nome ?? ''),
                    (string) ($part->estoqueSubcategoria?->nome ?? ''),
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
            fputcsv($handle, self::CSV_COLUMNS, ';');
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
    private function validatedPayload(Request $request, bool $includeCode = false, bool $aceitaQuantidade = true): array
    {
        $rules = [
            'codigo_fabricante' => ['nullable', 'string', 'max:120'],
            'nome' => ['required', 'string', 'max:160'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
            // Fonte da verdade da taxonomia de estoque (Grupo → Categoria →
            // Subcategoria) — Grupo/Categoria são derivados a partir desta
            // subcategoria logo abaixo, nunca aceitos crus do cliente, para
            // nunca existir uma tripla inconsistente.
            'estoque_subcategoria_id' => [
                'required',
                'integer',
                Rule::exists('estoque_subcategorias', 'id')->where(fn ($query) => $query->where('ativo', 1)),
            ],
            'modelos_compativeis' => ['nullable', 'string'],
            'fornecedor' => ['nullable', 'string', 'max:120'],
            'localizacao' => ['nullable', 'string', 'max:120'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'quantidade_atual' => ['nullable', 'numeric', 'min:0'],
            'estoque_minimo' => ['nullable', 'numeric', 'min:0'],
            'estoque_maximo' => ['nullable', 'numeric', 'min:0'],
            'observacoes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:30'],
            'ativo' => ['nullable', 'boolean'],
            // Operacionais do PDV: a busca da venda reconhece código de barras.
            'codigo_barras' => ['nullable', 'string', 'max:20'],
            'unidade' => ['nullable', 'string', 'max:6'],
            // Fiscais: sem uso no sistema hoje, preparam a emissão futura
            // (specs/027-vendas-balcao-pdv, fase fiscal).
            'ncm' => ['nullable', 'string', 'max:8'],
            'cest' => ['nullable', 'string', 'max:7'],
            'cfop_venda' => ['nullable', 'string', 'max:4'],
            'origem_mercadoria' => ['nullable', 'string', 'max:1'],
            'cst_icms' => ['nullable', 'string', 'max:3'],
            'csosn' => ['nullable', 'string', 'max:4'],
            'unidade_tributavel' => ['nullable', 'string', 'max:6'],
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
        // specs/040: na EDICAO a chave nem entra no payload. Nao e so a reserva
        // que isso protege — a regra era `nullable` com `?? 0`, entao um PATCH
        // que apenas OMITISSE o campo zerava o saldo da peca em silencio.
        if ($aceitaQuantidade) {
            $payload['quantidade_atual'] = round((float) ($validated['quantidade_atual'] ?? 0), 4);
        }
        $payload['estoque_minimo'] = round((float) ($validated['estoque_minimo'] ?? 0), 4);
        $payload['estoque_maximo'] = round((float) ($validated['estoque_maximo'] ?? 0), 4);
        $payload['observacoes'] = $this->normalizeText($validated['observacoes'] ?? null);
        $payload['status'] = $this->normalizeStatus($validated['status'] ?? null);
        $payload['ativo'] = $request->boolean('ativo', true);

        // Grupo/Categoria nunca vêm crus do cliente — derivados aqui a partir
        // da Subcategoria escolhida, a única id realmente validada acima.
        // Isso garante que a tripla gravada em `pecas` é sempre consistente,
        // mesmo que o cliente mande um `estoque_categoria_id` desencontrado.
        $subcategoria = EstoqueSubcategoria::query()
            ->with('categoria')
            ->find((int) $validated['estoque_subcategoria_id']);

        $payload['estoque_subcategoria_id'] = $subcategoria instanceof EstoqueSubcategoria ? (int) $subcategoria->id : null;
        $payload['estoque_categoria_id'] = $subcategoria instanceof EstoqueSubcategoria ? (int) $subcategoria->categoria_id : null;
        $payload['tipo_equipamento_id'] = $subcategoria instanceof EstoqueSubcategoria && $subcategoria->categoria instanceof EstoqueCategoria
            ? (int) $subcategoria->categoria->tipo_equipamento_id
            : null;

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
                'quantidade_atual' => $this->normalizeDecimal($data['quantidade_atual'] ?? 0),
                'estoque_minimo' => $this->normalizeDecimal($data['estoque_minimo'] ?? 0),
                'estoque_maximo' => $this->normalizeDecimal($data['estoque_maximo'] ?? 0),
                'status' => $this->normalizeStatus($data['status'] ?? null),
                'observacoes' => $this->normalizeText($data['observacoes'] ?? null),
                'ativo' => true,
            ];

            if ($payload['nome'] === '') {
                continue;
            }

            $payload += $this->resolveEstoqueTaxonomyIds(
                $this->normalizeText($data['grupo'] ?? null),
                $this->normalizeText($data['estoque_categoria'] ?? null),
                $this->normalizeText($data['estoque_subcategoria'] ?? null)
            );

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
            // Taxonomia de estoque (Grupo → Categoria → Subcategoria). Os
            // ids ficam null para peças ainda não classificadas (as 9
            // legadas, ou qualquer uma importada por CSV sem a coluna nova).
            'tipo_equipamento_id' => $peca->tipo_equipamento_id !== null ? (int) $peca->tipo_equipamento_id : null,
            'estoque_categoria_id' => $peca->estoque_categoria_id !== null ? (int) $peca->estoque_categoria_id : null,
            'estoque_subcategoria_id' => $peca->estoque_subcategoria_id !== null ? (int) $peca->estoque_subcategoria_id : null,
            'grupo_nome' => (string) ($peca->tipoEquipamento?->nome ?? ''),
            'estoque_categoria_nome' => (string) ($peca->estoqueCategoria?->nome ?? ''),
            'estoque_subcategoria_nome' => (string) ($peca->estoqueSubcategoria?->nome ?? ''),
            // Fallback para exibição/precificação: nome da árvore nova, ou o
            // texto legado se a peça nunca foi reclassificada.
            'tipo_equipamento_efetivo' => (string) ($peca->tipoEquipamento?->nome ?: ($peca->tipo_equipamento ?? '')),
            'categoria_efetiva' => (string) ($peca->estoqueSubcategoria?->nome ?: ($peca->categoria ?? '')),
            'modelos_compativeis' => (string) ($peca->modelos_compativeis ?? ''),
            'fornecedor' => (string) ($peca->fornecedor ?? ''),
            'localizacao' => (string) ($peca->localizacao ?? ''),
            'preco_custo' => (float) ($peca->preco_custo ?? 0),
            'preco_venda' => (float) ($peca->preco_venda ?? 0),
            // float, nao int: DECIMAL(14,4) desde 2026_08_27_000001. Truncar
            // aqui devolveria 1 para um saldo real de 1,25.
            'quantidade_atual' => (float) ($peca->quantidade_atual ?? 0),
            // specs/040: o que o operador precisa ver e o DISPONIVEL — o saldo
            // bruto menos o que ja esta prometido a algum orcamento enviado.
            'quantidade_reservada' => (float) ($peca->quantidade_reservada ?? 0),
            'quantidade_disponivel' => round(
                (float) ($peca->quantidade_atual ?? 0) - (float) ($peca->quantidade_reservada ?? 0),
                4
            ),
            'estoque_minimo' => (float) ($peca->estoque_minimo ?? 0),
            'estoque_maximo' => (float) ($peca->estoque_maximo ?? 0),
            'ativo' => (bool) ($peca->ativo ?? false),
            'status' => (string) ($peca->status ?? 'ativo'),
            'encerrado_em' => $this->formatDateTime($peca->encerrado_em),
            'codigo_barras' => (string) ($peca->codigo_barras ?? ''),
            'unidade' => (string) ($peca->unidade ?? 'UN'),
            'ncm' => (string) ($peca->ncm ?? ''),
            'cest' => (string) ($peca->cest ?? ''),
            'cfop_venda' => (string) ($peca->cfop_venda ?? ''),
            'origem_mercadoria' => (string) ($peca->origem_mercadoria ?? ''),
            'cst_icms' => (string) ($peca->cst_icms ?? ''),
            'csosn' => (string) ($peca->csosn ?? ''),
            'unidade_tributavel' => (string) ($peca->unidade_tributavel ?? ''),
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
            'venda_id' => data_get($movement, 'venda_id') !== null ? (int) data_get($movement, 'venda_id') : null,
            'venda_numero' => (string) data_get($movement, 'venda_numero', ''),
            'tipo' => $tipo,
            'tipo_label' => match ($tipo) {
                'entrada' => 'Entrada',
                'saida' => 'Saída',
                default => 'Ajuste',
            },
            // float: DECIMAL(14,4) desde 2026_08_27_000001. Com (int), uma
            // saida de 0,5 aparecia como 0 na ficha da peca.
            'quantidade' => (float) data_get($movement, 'quantidade', 0),
            'motivo' => (string) data_get($movement, 'motivo', ''),
            'responsavel_id' => data_get($movement, 'responsavel_id') !== null ? (int) data_get($movement, 'responsavel_id') : null,
            'responsavel_nome' => (string) data_get($movement, 'responsavel_nome', ''),
            'created_at' => $this->formatDateTime(data_get($movement, 'created_at')),
        ];
    }

    /**
     * Resolve nomes de Grupo/Categoria/Subcategoria (vindos do CSV) para os
     * ids da taxonomia — casamento exato case-insensitive contra os ativos.
     * Import não exige essas 3 colunas: qualquer uma ausente ou que não bata
     * com nada deixa a peça sem classificação, igual às legadas.
     *
     * @return array{tipo_equipamento_id: ?int, estoque_categoria_id: ?int, estoque_subcategoria_id: ?int}
     */
    private function resolveEstoqueTaxonomyIds(?string $grupoNome, ?string $categoriaNome, ?string $subcategoriaNome): array
    {
        $vazio = ['tipo_equipamento_id' => null, 'estoque_categoria_id' => null, 'estoque_subcategoria_id' => null];

        if ($grupoNome === null || $grupoNome === '') {
            return $vazio;
        }

        $grupo = EquipmentType::query()->where('ativo', 1)->whereRaw('LOWER(nome) = ?', [mb_strtolower($grupoNome)])->first();
        if (! $grupo instanceof EquipmentType) {
            return $vazio;
        }

        if ($categoriaNome === null || $categoriaNome === '') {
            return $vazio;
        }

        $categoria = EstoqueCategoria::query()
            ->where('ativo', 1)
            ->where('tipo_equipamento_id', $grupo->id)
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($categoriaNome)])
            ->first();
        if (! $categoria instanceof EstoqueCategoria) {
            return $vazio;
        }

        if ($subcategoriaNome === null || $subcategoriaNome === '') {
            return $vazio;
        }

        $subcategoria = EstoqueSubcategoria::query()
            ->where('ativo', 1)
            ->where('categoria_id', $categoria->id)
            ->whereRaw('LOWER(nome) = ?', [mb_strtolower($subcategoriaNome)])
            ->first();
        if (! $subcategoria instanceof EstoqueSubcategoria) {
            return $vazio;
        }

        return [
            'tipo_equipamento_id' => (int) $grupo->id,
            'estoque_categoria_id' => (int) $categoria->id,
            'estoque_subcategoria_id' => (int) $subcategoria->id,
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

    /**
     * Quantidade para o CSV de exportacao.
     *
     * Sai no formato pt-BR ("1.234,5"), o mesmo dos precos, para que
     * normalizeDecimal() consiga reler no import — exportar e reimportar tem
     * de fechar. Zeros a direita sao aparados: "10" e mais legivel que
     * "10,0000" numa planilha que o dono vai abrir no Excel.
     */
    private function formatQuantidadeCsv(mixed $valor): string
    {
        $numero = round((float) $valor, 4);

        if ($numero === floor($numero)) {
            return number_format($numero, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($numero, 4, ',', '.'), '0'), ',');
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
