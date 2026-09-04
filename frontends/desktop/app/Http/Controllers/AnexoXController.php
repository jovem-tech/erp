<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\AnexoXService;
use App\Support\DesktopSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Anexo X — Relatório Mensal das Receitas Brutas (Res. CGSN 140/2018, art. 106).
 *
 * Só tela: a apuração inteira vive no backend.
 */
class AnexoXController extends DesktopController
{
    private const REGIMES = ['competencia', 'caixa'];

    public function __construct(
        private readonly AnexoXService $anexoXService
    ) {
    }

    /**
     * A tela do ANO: doze meses numa tabela, com o acumulado e o gráfico acima.
     *
     * O resumo inteiro vai no bootstrap da página — os dois regimes de cada
     * mês, inclusive as dez linhas. É o que faz o alternador de regime e o
     * modal "Receitas brutas do mês" trocarem de leitura sem nenhuma ida ao
     * servidor. O detalhe caro (drill-down, ajustes) fica sob demanda.
     */
    public function index(Request $request): View
    {
        $ano = $this->resolveAno($request) ?? (int) now()->format('Y');
        $regime = $this->resolveRegime($request);

        return view('fiscal.anexo-x', [
            'pageTitle' => 'Relatório Mensal das Receitas',
            'ano' => $ano,
            'anoAnterior' => $ano - 1,
            'anoProximo' => $ano + 1,
            'regime' => $regime,
            'resumo' => $this->anexoXService->resumoAnual($ano),
            'podeEncerrar' => DesktopSession::can('fiscal', 'encerrar'),
            'podeEditar' => DesktopSession::can('fiscal', 'editar'),
        ]);
    }

    /**
     * Detalhe de um mês para os modais — drill-down, receita sem documento e
     * divergências da reconferência.
     */
    public function operacoesJson(Request $request): JsonResponse
    {
        $competencia = $this->resolveCompetencia($request);
        $regime = $this->resolveRegime($request);

        try {
            $relatorio = $this->anexoXService->relatorio($competencia, $regime, $request->boolean('reconferir'));
        } catch (ApiAuthenticationException $exception) {
            return $this->falhaJson($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->falhaJson($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->falhaJson($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->falhaJson('Não foi possível carregar as operações do mês.', 500);
        }

        return response()->json([
            'success' => true,
            'competencia' => $competencia,
            'regime' => $regime,
            'periodo_label' => $relatorio['periodo_label'] ?? '',
            'linhas' => $relatorio['linhas'] ?? [],
            'deducoes' => $relatorio['deducoes'] ?? [],
            'origens' => $relatorio['origens'] ?? [],
            'drill_down' => $relatorio['drill_down'] ?? [],
            'sem_documento' => $relatorio['sem_documento'] ?? [],
            'ajustes' => $relatorio['ajustes'] ?? [],
            'fechamento' => $relatorio['fechamento'] ?? null,
        ]);
    }

    public function ajustesJson(Request $request): JsonResponse
    {
        $competencia = $this->resolveCompetencia($request);
        $regime = $this->resolveRegime($request);

        try {
            $dados = $this->anexoXService->ajustes($competencia, $regime);
        } catch (ApiAuthenticationException $exception) {
            return $this->falhaJson($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->falhaJson($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            report($exception);

            return $this->falhaJson('Não foi possível carregar os ajustes.', 500);
        }

        return response()->json([
            'success' => true,
            'competencia' => $competencia,
            'regime' => $regime,
            'pode_editar' => DesktopSession::can('fiscal', 'editar'),
            'ajustes' => $dados['ajustes'] ?? [],
            'linhas_ajustaveis' => $dados['linhas_ajustaveis'] ?? [],
        ]);
    }

    public function lancarAjuste(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'competencia' => ['required', 'string', 'regex:/^\\d{4}-\\d{2}$/'],
            'regime' => ['required', 'string', 'in:competencia,caixa'],
            'linha' => ['required', 'string', 'max:4'],
            'valor' => ['required', 'numeric'],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
            'aplicar_no_outro_regime' => ['nullable', 'boolean'],
        ]);

        try {
            $dados = $this->anexoXService->lancarAjuste($validado);
        } catch (ApiAuthenticationException $exception) {
            return $this->falhaJson($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->falhaJson($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->falhaJson($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->falhaJson('Não foi possível lançar o ajuste.', 500);
        }

        return response()->json(['success' => true] + $dados);
    }

    public function cancelarAjuste(Request $request, int $ajuste): JsonResponse
    {
        $validado = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $dados = $this->anexoXService->cancelarAjuste($ajuste, $validado['motivo']);
        } catch (ApiAuthenticationException $exception) {
            return $this->falhaJson($exception->getMessage(), 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->falhaJson($exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            return $this->falhaJson($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->falhaJson('Não foi possível cancelar o ajuste.', 500);
        }

        return response()->json(['success' => true] + $dados);
    }

    /**
     * Mesma forma de falha que o StockController já usa nas rotas de fetch.
     */
    private function falhaJson(string $mensagem, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $mensagem, 'errors' => []], $status);
    }


    /**
     * O formulário: um mês, ou o ano inteiro com uma folha por mês.
     *
     * O modal envia `ano` OU `competencia`, nunca os dois — o campo não
     * escolhido vai desabilitado e o navegador não o serializa.
     */
    public function pdf(Request $request): Response|RedirectResponse
    {
        $regime = $this->resolveRegime($request);
        $ano = $this->resolveAno($request);

        if ($ano !== null) {
            return $this->repassarPdf(
                fn (): array => $this->anexoXService->pdfAnual($ano, $regime),
                'anexo-x-'.$ano.'.pdf',
                $ano.'-01',
                $regime
            );
        }

        $competencia = $this->resolveCompetencia($request);

        return $this->repassarPdf(
            fn (): array => $this->anexoXService->pdf($competencia, $regime),
            'anexo-x-'.$competencia.'.pdf',
            $competencia,
            $regime
        );
    }

    /**
     * A relação de documentos emitidos — download PRÓPRIO, nunca embutido no
     * formulário: o Anexo X é padrão da Receita e não se modifica.
     */
    public function documentosPdf(Request $request): Response|RedirectResponse
    {
        $competencia = $this->resolveCompetencia($request);

        return $this->repassarPdf(
            fn (): array => $this->anexoXService->documentosPdf($competencia),
            'anexo-x-documentos-'.$competencia.'.pdf',
            $competencia,
            $this->resolveRegime($request)
        );
    }

    public function fechar(Request $request): RedirectResponse
    {
        $validado = $request->validate([
            'competencia' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'regime' => ['required', 'string', 'in:competencia,caixa'],
        ], [
            'competencia.regex' => 'Informe a competência no formato AAAA-MM.',
        ]);

        try {
            $this->anexoXService->fechar($validado['competencia'], $validado['regime']);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return $this->voltar($validado['competencia'], $validado['regime'])
                ->with('error', $exception->getMessage());
        }

        return $this->voltar($validado['competencia'], $validado['regime'])
            ->with('success', 'Competência encerrada. Os valores do Anexo X estão congelados.');
    }

    public function reabrir(Request $request): RedirectResponse
    {
        $validado = $request->validate([
            'competencia' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'regime' => ['required', 'string', 'in:competencia,caixa'],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
            'admin_email' => ['required', 'string', 'email'],
            'admin_password' => ['required', 'string'],
        ], [
            'motivo.min' => 'Descreva em pelo menos 10 caracteres por que a competência está sendo reaberta.',
        ], [
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha do administrador',
        ]);

        try {
            $this->anexoXService->reabrir(
                $validado['competencia'],
                $validado['regime'],
                $validado['motivo'],
                $validado['admin_email'],
                $validado['admin_password']
            );
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return $this->voltar($validado['competencia'], $validado['regime'])
                ->with('error', $exception->getMessage());
        }

        return $this->voltar($validado['competencia'], $validado['regime'])
            ->with('success', 'Competência reaberta. O relatório volta a ser calculado com os dados atuais.');
    }

    public function help(): View
    {
        return view('fiscal.anexo-x-help', ['pageTitle' => 'Ajuda do Anexo X']);
    }

    /**
     * @param  callable(): array<string, mixed>  $download
     */
    private function repassarPdf(callable $download, string $nome, string $competencia, string $regime): Response|RedirectResponse
    {
        try {
            $resposta = $download();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->voltar($competencia, $regime)->with('error', 'Não foi possível gerar o PDF.');
        }

        return response($resposta['body'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }

    private function voltar(string $competencia, string $regime): RedirectResponse
    {
        return redirect()->route('fiscal.anexo-x', [
            'ano' => (int) substr($competencia, 0, 4),
            'regime' => $regime,
        ]);
    }

    private function resolveCompetencia(Request $request): string
    {
        $competencia = (string) $request->query('competencia', '');

        return preg_match('/^\d{4}-\d{2}$/', $competencia) === 1 ? $competencia : now()->format('Y-m');
    }

    /**
     * Ano do bloco anual, ou null quando o pedido é de um mês só.
     *
     * Fora da faixa plausível vira null em vez de erro: o download cai no mês
     * selecionado, que é o comportamento previsível para um querystring
     * adulterado à mão.
     */
    private function resolveAno(Request $request): ?int
    {
        $ano = trim((string) $request->query('ano', ''));

        if (preg_match('/^\d{4}$/', $ano) !== 1) {
            return null;
        }

        $ano = (int) $ano;

        return $ano >= 2000 && $ano <= (int) now()->addYear()->format('Y') ? $ano : null;
    }

    private function resolveRegime(Request $request): string
    {
        $regime = (string) $request->query('regime', '');

        return in_array($regime, self::REGIMES, true) ? $regime : 'competencia';
    }
}
