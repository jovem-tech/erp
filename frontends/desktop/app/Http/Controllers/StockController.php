<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroPrecificacaoService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class StockController extends DesktopController
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly FinanceiroPrecificacaoService $financeiroPrecificacaoService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'active' => $request->query('active', ''),
            'tipo_equipamento_id' => (int) $request->query('tipo_equipamento_id', 0),
            'estoque_categoria_id' => (int) $request->query('estoque_categoria_id', 0),
            'estoque_subcategoria_id' => (int) $request->query('estoque_subcategoria_id', 0),
            'status' => trim((string) $request->query('status', '')),
            // Destino do alerta "itens abaixo do estoque minimo" do dashboard.
            'estoque_baixo' => $request->boolean('estoque_baixo') ? 1 : 0,
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->stockService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        $taxonomy = $this->resolveTaxonomyOptions();

        return view('estoque.index', [
            'pageTitle' => 'Estoque de Peças',
            'parts' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
            'grupos' => $taxonomy['grupos'],
            'estoqueCategorias' => $taxonomy['estoque_categorias'],
            'estoqueSubcategorias' => $taxonomy['estoque_subcategorias'],
            // Sem cache e incluindo inativos: o modal "Gerenciar categorias"
            // precisa ver (e reativar) o que o filtro/cadastro escondem.
            'gruposAdmin' => $this->safeCatalogList(fn () => $this->stockService->grupos()),
            'estoqueCategoriasAdmin' => $this->safeCatalogList(fn () => $this->stockService->categorias()),
            'estoqueSubcategoriasAdmin' => $this->safeCatalogList(fn () => $this->stockService->subcategorias()),
        ]);
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $fetch
     * @return array<int, array<string, mixed>>
     */
    private function safeCatalogList(callable $fetch): array
    {
        try {
            return $fetch();
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            return [];
        }
    }

    /**
     * Taxonomia de estoque para os 3 selects em cascata do filtro (Grupo →
     * Categoria → Subcategoria) — só ativos, igual ao que formData() já
     * expõe para os formulários de cadastro. Cacheado pelo mesmo motivo que
     * o antigo resolveEquipmentTypeOptions(): catálogo de referência igual
     * para qualquer usuário, não vale repetir a chamada a cada carregamento
     * da listagem.
     *
     * @return array{grupos: array<int, array<string, mixed>>, estoque_categorias: array<int, array<string, mixed>>, estoque_subcategorias: array<int, array<string, mixed>>}
     */
    private function resolveTaxonomyOptions(): array
    {
        try {
            return Cache::remember(
                'desktop:estoque_filters:taxonomia',
                300,
                function (): array {
                    $formData = $this->stockService->formData();

                    return [
                        'grupos' => $formData['grupos'] ?? [],
                        'estoque_categorias' => $formData['estoque_categorias'] ?? [],
                        'estoque_subcategorias' => $formData['estoque_subcategorias'] ?? [],
                    ];
                }
            );
        } catch (ApiAuthenticationException|ApiAuthorizationException|ApiRequestException) {
            return ['grupos' => [], 'estoque_categorias' => [], 'estoque_subcategorias' => []];
        }
    }

    public function help(): View
    {
        return view('estoque.help', [
            'pageTitle' => 'Ajuda de estoque',
        ]);
    }

    public function create(): View
    {
        return view('estoque.form', [
            'pageTitle' => 'Nova peça',
            'part' => $this->partFormDefaults(),
            'formData' => $this->stockService->formData(),
            'mode' => 'create',
        ]);
    }

    public function edit(int $part): View|RedirectResponse
    {
        try {
            $partData = $this->stockService->find($part);
            $formData = $this->stockService->formData();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        if ($partData === []) {
            abort(404);
        }

        return view('estoque.form', [
            'pageTitle' => 'Editar peça',
            'part' => array_merge($this->partFormDefaults(), $partData),
            'formData' => $formData,
            'mode' => 'edit',
        ]);
    }

    public function movements(int $part): View|RedirectResponse
    {
        try {
            $result = $this->stockService->movements($part);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        $partData = $result['part'] ?? [];

        if ($partData === []) {
            abort(404);
        }

        return view('estoque.movimentacoes', [
            'pageTitle' => 'Movimentações da peça',
            'part' => $partData,
            'movements' => $result['movements'] ?? [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPartPayload($request);

        try {
            $part = $this->stockService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos da peça.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível cadastrar a peça agora. Tente novamente.');
        }

        return redirect()
            ->route('estoque.index', ['search' => trim((string) ($part['nome'] ?? ''))])
            ->with('success', 'Peça cadastrada com sucesso.');
    }

    /**
     * Preço sugerido para o cadastro de peça (specs/037).
     *
     * Delega ao mesmo endpoint que a tela de precificação usa — criar rota
     * própria no backend duplicaria o simulador. A resposta já vem redigida
     * pela permissão de quem pediu: o estoquista recebe o valor sugerido e o
     * semáforo, não a composição de custo.
     */
    public function suggestPrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'peca_id' => ['nullable', 'integer', 'min:1'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'categoria' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $simulacao = $this->financeiroPrecificacaoService->simulatePeca(
                $this->normalizeMoneyPayload($validated, ['preco_custo', 'preco_venda'])
            );
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Sua sessão expirou. Faça login novamente.', 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Sem permissão para simular preços.', 403);
        } catch (Throwable $exception) {
            report($exception);

            // Sugestão é conveniência, não requisito: falhar aqui não pode
            // impedir o cadastro da peça. O JS trata 5xx escondendo a dica.
            return $this->jsonFailure('Não foi possível calcular a sugestão agora.', 500);
        }

        return response()->json([
            'success' => true,
            'simulation' => $simulacao,
        ]);
    }

    /**
     * Prévia do código sugerido para o cadastro de peça.
     *
     * `formData()` já calcula isto (`Peca::generateCodigo()`, MAX(id)+1) para
     * a tela de Estoque, mas nenhum formulário lia o campo — o operador só
     * via o placeholder "Será sugerido se ficar em branco" e o valor real
     * nascia mudo, direto no INSERT. É só uma prévia: como não reserva nada,
     * pode ficar desatualizada entre duas aberturas do modal — o JS reenvia
     * o campo em branco se o operador não tiver editado o valor, deixando o
     * backend gerar de novo, fresco, na hora de salvar.
     */
    public function suggestCode(): JsonResponse
    {
        try {
            $formData = $this->stockService->formData();
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Sua sessão expirou. Faça login novamente.', 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Sem permissão para sugerir código.', 403);
        } catch (Throwable $exception) {
            report($exception);

            // Sugestão é conveniência: falhar aqui não pode impedir o
            // cadastro. O backend gera o código de qualquer forma se o
            // campo chegar em branco.
            return $this->jsonFailure('Não foi possível sugerir um código agora.', 500);
        }

        return response()->json([
            'success' => true,
            'codigo_sugerido' => (string) ($formData['codigo_sugerido'] ?? ''),
        ]);
    }

    public function quickStore(Request $request): JsonResponse
    {
        try {
            $part = $this->stockService->create($this->validatedPartPayload($request));
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Sua sessão expirou. Faça login novamente.', 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Você não tem permissão para executar esta ação.', 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure(
                $exception->getMessage() ?: 'Não foi possível cadastrar a peça.',
                $exception->statusCode() > 0 ? $exception->statusCode() : 422,
                $exception->details() ?? []
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Verifique os campos da peça.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível cadastrar a peça agora. Tente novamente.', 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Peça cadastrada com sucesso.',
            'part' => $part,
        ], 201);
    }

    public function update(Request $request, int $part): RedirectResponse
    {
        $payload = $this->validatedPartPayload($request);

        try {
            $updated = $this->stockService->update($part, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos da peça.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível atualizar a peça agora. Tente novamente.');
        }

        return redirect()
            ->route('estoque.index', ['search' => trim((string) ($updated['nome'] ?? ''))])
            ->with('success', 'Peça atualizada com sucesso.');
    }

    public function storeMovement(Request $request, int $part): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'tipo' => ['required', 'string', 'in:entrada,saida,ajuste'],
                // numeric: insumo se mede em fracao (0,5 m de cabo). A coluna
                // virou DECIMAL(14,4) — ver specs/036-estoque-nucleo-razao.
                'quantidade' => ['required', 'numeric', 'min:0.0001'],
                'motivo' => ['nullable', 'string', 'max:255'],
                'os_id' => ['nullable', 'integer'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos da movimentação.');
        }

        try {
            $this->stockService->createMovement($part, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível registrar a movimentação agora.');
        }

        return redirect()
            ->route('estoque.movements', $part)
            ->with('success', 'Movimentação registrada com sucesso.');
    }

    public function close(int $part): RedirectResponse
    {
        try {
            $this->stockService->close($part);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('estoque.index')
            ->with('success', 'Peça encerrada com sucesso.');
    }

    public function destroy(int $part): RedirectResponse
    {
        try {
            $this->stockService->destroy($part);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('estoque.index')
            ->with('success', 'Peça desativada com sucesso.');
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function exportCsv()
    {
        try {
            $download = $this->stockService->exportCsv();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'estoque_pecas.csv', $download['headers']);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function downloadCsvTemplate()
    {
        try {
            $download = $this->stockService->downloadCsvTemplate();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'modelo_importacao_estoque.csv', $download['headers']);
    }

    public function importCsv(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'arquivo' => ['required', 'file', 'mimes:csv,txt'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Selecione um arquivo CSV válido.');
        }

        try {
            $this->stockService->importCsv($validated['arquivo']);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível importar o arquivo agora.');
        }

        return redirect()
            ->route('estoque.index')
            ->with('success', 'Arquivo importado com sucesso.');
    }

    /**
     * Gerenciamento da taxonomia de estoque (Grupo → Categoria →
     * Subcategoria) — modal "Gerenciar categorias" em Estoque > Mais ações.
     * `Cache::forget` depois de cada gravação: os selects de cadastro (3
     * formulários) e o filtro da listagem leem `resolveTaxonomyOptions()`
     * cacheado 300s — sem isso, um Grupo/Categoria/Subcategoria recém-criado
     * ficaria invisível nos selects por até 5 minutos.
     */
    public function saveGrupo(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:120'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            $this->stockService->saveGrupo($validated + ['ativo' => $request->boolean('ativo', true)]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        Cache::forget('desktop:estoque_filters:taxonomia');

        return back()->with('success', 'Grupo salvo com sucesso.');
    }

    public function deactivateGrupo(int $grupo): RedirectResponse
    {
        try {
            $this->stockService->deactivateGrupo($grupo);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Cache::forget('desktop:estoque_filters:taxonomia');

        return back()->with('success', 'Grupo desativado com sucesso.');
    }

    public function saveCategoria(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'tipo_equipamento_id' => ['required', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:120'],
            'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            $this->stockService->saveCategoria($validated + ['ativo' => $request->boolean('ativo', true)]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        Cache::forget('desktop:estoque_filters:taxonomia');

        return back()->with('success', 'Categoria salva com sucesso.');
    }

    public function deactivateCategoria(int $categoria): RedirectResponse
    {
        try {
            $this->stockService->deactivateCategoria($categoria);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Cache::forget('desktop:estoque_filters:taxonomia');

        return back()->with('success', 'Categoria desativada com sucesso.');
    }

    public function saveSubcategoria(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'categoria_id' => ['required', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:120'],
            'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            $this->stockService->saveSubcategoria($validated + ['ativo' => $request->boolean('ativo', true)]);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        Cache::forget('desktop:estoque_filters:taxonomia');

        return back()->with('success', 'Subcategoria salva com sucesso.');
    }

    public function deactivateSubcategoria(int $subcategoria): RedirectResponse
    {
        try {
            $this->stockService->deactivateSubcategoria($subcategoria);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Cache::forget('desktop:estoque_filters:taxonomia');

        return back()->with('success', 'Subcategoria desativada com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function partFormDefaults(): array
    {
        return [
            'id' => null,
            'codigo' => '',
            'codigo_fabricante' => '',
            'nome' => '',
            'categoria' => '',
            'tipo_equipamento' => '',
            'tipo_equipamento_id' => null,
            'estoque_categoria_id' => null,
            'estoque_subcategoria_id' => null,
            'modelos_compativeis' => '',
            'fornecedor' => '',
            'localizacao' => '',
            'preco_custo' => 0,
            'preco_venda' => 0,
            'quantidade_atual' => 0,
            'estoque_minimo' => 0,
            'estoque_maximo' => 0,
            'status' => 'ativo',
            'observacoes' => '',
            'ativo' => true,
            // Operacionais do PDV + preparação fiscal (specs/027-vendas-balcao-pdv).
            'codigo_barras' => '',
            'unidade' => 'UN',
            'ncm' => '',
            'cest' => '',
            'cfop_venda' => '',
            'origem_mercadoria' => '',
            'cst_icms' => '',
            'csosn' => '',
            'unidade_tributavel' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPartPayload(Request $request): array
    {
        $request->replace($this->normalizeMoneyPayload(
            $request->all(),
            ['preco_custo', 'preco_venda']
        ));

        $validated = $request->validate([
            'codigo' => ['nullable', 'string', 'max:120'],
            'codigo_fabricante' => ['nullable', 'string', 'max:120'],
            'nome' => ['required', 'string', 'max:160'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
            // Fonte da verdade da taxonomia de estoque — obrigatório nos 3
            // formulários de cadastro (tela cheia e os dois modais rápidos,
            // que também caem aqui). Grupo/Categoria são derivados no
            // backend a partir desta subcategoria, ver EstoqueController.
            'estoque_subcategoria_id' => ['required', 'integer', 'min:1'],
            'modelos_compativeis' => ['nullable', 'string'],
            'fornecedor' => ['nullable', 'string', 'max:120'],
            'localizacao' => ['nullable', 'string', 'max:120'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'quantidade_atual' => ['nullable', 'numeric', 'min:0'],
            'estoque_minimo' => ['nullable', 'numeric', 'min:0'],
            'estoque_maximo' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:ativo,encerrado,inativo'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
            'codigo_barras' => ['nullable', 'string', 'max:20'],
            'unidade' => ['nullable', 'string', 'max:6'],
            'ncm' => ['nullable', 'string', 'max:8'],
            'cest' => ['nullable', 'string', 'max:7'],
            'cfop_venda' => ['nullable', 'string', 'max:4'],
            'origem_mercadoria' => ['nullable', 'string', 'max:1'],
            'cst_icms' => ['nullable', 'string', 'max:3'],
            'csosn' => ['nullable', 'string', 'max:4'],
            'unidade_tributavel' => ['nullable', 'string', 'max:6'],
        ]);

        return [
            'codigo' => trim((string) ($validated['codigo'] ?? '')),
            'codigo_fabricante' => trim((string) ($validated['codigo_fabricante'] ?? '')),
            'nome' => trim((string) ($validated['nome'] ?? '')),
            'categoria' => trim((string) ($validated['categoria'] ?? '')),
            'tipo_equipamento' => trim((string) ($validated['tipo_equipamento'] ?? '')),
            'estoque_subcategoria_id' => (int) ($validated['estoque_subcategoria_id'] ?? 0),
            'modelos_compativeis' => trim((string) ($validated['modelos_compativeis'] ?? '')),
            'fornecedor' => trim((string) ($validated['fornecedor'] ?? '')),
            'localizacao' => trim((string) ($validated['localizacao'] ?? '')),
            'preco_custo' => (float) ($validated['preco_custo'] ?? 0),
            'preco_venda' => (float) ($validated['preco_venda'] ?? 0),
            'quantidade_atual' => round((float) ($validated['quantidade_atual'] ?? 0), 4),
            'estoque_minimo' => round((float) ($validated['estoque_minimo'] ?? 0), 4),
            'estoque_maximo' => round((float) ($validated['estoque_maximo'] ?? 0), 4),
            'status' => trim((string) ($validated['status'] ?? 'ativo')),
            'observacoes' => trim((string) ($validated['observacoes'] ?? '')),
            'ativo' => $request->boolean('ativo', true),
            'codigo_barras' => trim((string) ($validated['codigo_barras'] ?? '')),
            'unidade' => trim((string) ($validated['unidade'] ?? 'UN')) ?: 'UN',
            'ncm' => trim((string) ($validated['ncm'] ?? '')),
            'cest' => trim((string) ($validated['cest'] ?? '')),
            'cfop_venda' => trim((string) ($validated['cfop_venda'] ?? '')),
            'origem_mercadoria' => trim((string) ($validated['origem_mercadoria'] ?? '')),
            'cst_icms' => trim((string) ($validated['cst_icms'] ?? '')),
            'csosn' => trim((string) ($validated['csosn'] ?? '')),
            'unidade_tributavel' => trim((string) ($validated['unidade_tributavel'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $errors
     * @return array<string, string>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();
        if (! is_array($details)) {
            return ['erro' => $exception->getMessage()];
        }

        $messages = [];

        foreach ($details as $field => $value) {
            if (is_array($value)) {
                $messages[(string) $field] = implode(' ', array_map('strval', $value));
                continue;
            }

            $messages[(string) $field] = (string) $value;
        }

        return $messages;
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function jsonFailure(string $message, int $status, ?array $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $details ?? [],
        ], $status);
    }
}
