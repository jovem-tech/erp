<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\EquipmentCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Tela "Equipamentos" em Cadastros (specs/044-catalogo-equipamentos-csv):
 * listagem consultiva de tipos/marcas/modelos usada para padronizar o
 * cadastro de aparelhos em OS/orcamento. Nao tem vinculo direto com OS/
 * orcamento — so' alimenta os selects que ja existiam la'.
 */
class EquipmentCatalogController extends DesktopController
{
    private const ABAS = ['modelos', 'marcas', 'tipos'];

    public function __construct(private readonly EquipmentCatalogService $catalogService)
    {
    }

    public function index(Request $request): View
    {
        $aba = $this->resolveAba($request);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'tipo_id' => (int) $request->query('tipo_id', 0),
            'marca_id' => (int) $request->query('marca_id', 0),
            'ativo' => (string) $request->query('ativo', ''),
            'page' => max(1, (int) $request->query('page', 1)),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $viewData = [
            'pageTitle' => 'Equipamentos',
            'aba' => $aba,
            'filters' => $filters,
            'types' => $this->catalogService->types(),
            'allBrands' => $this->catalogService->brands(),
        ];

        if ($aba === 'modelos') {
            $result = $this->catalogService->paginateModels(
                $this->apiFilters($filters, ['search', 'tipo_id', 'marca_id', 'ativo', 'page', 'per_page'])
            );
            $viewData['modelos'] = $result['items'];
            $viewData['pagination'] = $result['pagination'];
        } elseif ($aba === 'marcas') {
            $viewData['marcas'] = $this->catalogService->brands(
                $this->apiFilters($filters, ['search', 'tipo_id', 'ativo'])
            );
        } else {
            $viewData['tipos'] = $viewData['types'];
        }

        return view('equipments.catalog.index', $viewData);
    }

    public function help(): View
    {
        return view('equipments.catalog.help', [
            'pageTitle' => 'Ajuda do catálogo de equipamentos',
            'aiPrompt' => $this->aiReviewPrompt(),
        ]);
    }

    /**
     * Prompt pronto para colar numa conversa com uma IA (Claude, ChatGPT etc.)
     * junto com o CSV exportado — pedido explicitamente pelo dono do sistema
     * para não depender de pedir isso de volta a cada revisão do catalogo.
     * Fica no controller (nao na view) so' para nao poluir o Blade com um
     * texto longo; nenhuma logica alem de devolver a string.
     */
    private function aiReviewPrompt(): string
    {
        return <<<'PROMPT'
Este CSV é o catálogo de equipamentos (tipos/marcas/modelos) de uma assistência técnica, com colunas id;tipo;marca;modelo;ativo;mesclar_com_id separadas por ponto e vírgula. Preciso que você revise e padronize os nomes na coluna modelo, e identifique duplicatas.

Regras que preciso que você siga à risca:

1. Nunca altere as colunas id, tipo e marca. Só mexa em modelo, e opcionalmente em ativo e mesclar_com_id.

2. Padronize o nome do modelo para o formato mais completo e oficial possível (ex.: prefira "Galaxy S23 Ultra" a "s23 ultra" ou "S23U"; inclua o código de modelo do fabricante quando fizer sentido, ex.: "SM-J730G" para o Galaxy J7).

3. Identifique duplicatas dentro da mesma marca — linhas diferentes que representam o mesmo aparelho físico com nomes diferentes (ex.: "J7", "j 7" e "SM-J730G" da mesma marca).
   - Escolha qual das linhas duplicadas deve ser a vencedora (a que tem o nome mais completo/correto) e mantenha o modelo dela padronizado.
   - Nas linhas perdedoras (duplicatas), preencha a coluna mesclar_com_id com o id da linha vencedora. Só mescle duplicatas dentro da mesma marca — nunca aponte mesclar_com_id para um id de outra marca.
   - Não mescle em cadeia (uma linha perdedora não pode apontar para outra linha que também é perdedora de uma terceira).

4. Se um modelo estiver vazio, incompleto ou genérico demais (ex.: "Aparelho", "Sem nome"), tente inferir o nome correto pelo contexto (marca + padrão de nomenclatura dela) ou deixe como está se não for possível ter certeza — não invente informação.

5. Modelos com nomes claramente diferentes (mesmo que pareçam a mesma "família", ex.: "Galaxy S23" e "Galaxy S23 Ultra") são aparelhos DIFERENTES — não mescle.

6. Mantenha o arquivo com a mesma estrutura: mesmo cabeçalho, mesmo delimitador ;, uma linha por registro, sem adicionar nem remover linhas.

Me devolva o CSV completo revisado, pronto para eu reimportar no sistema.
PROMPT;
    }

    public function saveModel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'tipo_id' => ['required_without:id', 'nullable', 'integer', 'min:1'],
            'marca_id' => ['required_without:id', 'nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:100'],
        ]);

        try {
            $this->catalogService->saveModel($validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Modelo salvo com sucesso.');
    }

    public function toggleModel(int $model, Request $request): RedirectResponse
    {
        try {
            $this->catalogService->toggleModel($model, $request->boolean('ativo'));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $request->boolean('ativo') ? 'Modelo ativado.' : 'Modelo desativado.');
    }

    public function saveBrand(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'tipo_id' => ['required_without:id', 'nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:100'],
        ]);

        try {
            $this->catalogService->saveBrand($validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Marca salva com sucesso.');
    }

    public function toggleBrand(int $brand, Request $request): RedirectResponse
    {
        try {
            $this->catalogService->toggleBrand($brand, $request->boolean('ativo'));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $request->boolean('ativo') ? 'Marca ativada.' : 'Marca desativada.');
    }

    public function saveType(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'nome' => ['required', 'string', 'max:100'],
        ]);

        try {
            $this->catalogService->saveType($validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $this->forgetTypeCaches();

        return back()->with('success', 'Tipo salvo com sucesso.');
    }

    public function toggleType(int $type, Request $request): RedirectResponse
    {
        try {
            $this->catalogService->toggleType($type, $request->boolean('ativo'));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->forgetTypeCaches();

        return back()->with('success', $request->boolean('ativo') ? 'Tipo ativado.' : 'Tipo desativado.');
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function exportCsv()
    {
        try {
            $download = $this->catalogService->exportCsv();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('equipments.catalog.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'catalogo_equipamentos.csv', $download['headers']);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function downloadCsvTemplate()
    {
        try {
            $download = $this->catalogService->downloadCsvTemplate();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('equipments.catalog.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'modelo_importacao_catalogo_equipamentos.csv', $download['headers']);
    }

    public function importCsv(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'arquivo' => ['required', 'file', 'mimes:csv,txt'],
            ]);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->with('error', 'Selecione um arquivo CSV válido.');
        }

        try {
            $result = $this->catalogService->importCsv($validated['arquivo']);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('equipments.catalog.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível importar o arquivo agora.');
        }

        return redirect()
            ->route('equipments.catalog.index')
            ->with('success', $this->summarizeImportResult($result))
            ->with('catalog_import_report', $result);
    }

    private function resolveAba(Request $request): string
    {
        $aba = (string) $request->query('aba', 'modelos');

        return in_array($aba, self::ABAS, true) ? $aba : 'modelos';
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function apiFilters(array $filters, array $keys): array
    {
        $subset = array_intersect_key($filters, array_flip($keys));

        return array_filter($subset, static fn ($value): bool => $value !== '' && $value !== 0);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function summarizeImportResult(array $result): string
    {
        $criados = (int) ($result['criados']['marcas'] ?? 0) + (int) ($result['criados']['modelos'] ?? 0);
        $erros = count($result['erros'] ?? []);

        $mensagem = sprintf(
            'Importação concluída: %d criados, %d atualizados, %d reativados, %d desativados, %d ignorados.',
            $criados,
            (int) ($result['atualizados'] ?? 0),
            (int) ($result['reativados'] ?? 0),
            (int) ($result['desativados'] ?? 0),
            (int) ($result['ignorados'] ?? 0)
        );

        if ($erros > 0) {
            $mensagem .= sprintf(' %d linha(s) com erro — veja o relatório abaixo.', $erros);
        }

        return $mensagem;
    }

    private function forgetTypeCaches(): void
    {
        Cache::forget('desktop:estoque_filters:taxonomia');
        Cache::forget('desktop:servico_filters:tipos_equipamento');
    }
}
