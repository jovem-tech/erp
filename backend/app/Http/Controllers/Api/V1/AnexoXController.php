<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AnexoXAjuste;
use App\Models\AnexoXFechamento;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Fiscal\AnexoXAjusteService;
use App\Services\Fiscal\AnexoXFechamentoService;
use App\Services\Fiscal\AnexoXService;
use App\Services\Pdf\AnexoXRenderer;
use App\Support\PeriodoMensal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anexo X — Relatório Mensal das Receitas Brutas (Res. CGSN 140/2018, art. 106).
 *
 * Dois PDFs, dois endpoints: o formulário oficial sai sozinho, e a relação de
 * documentos fiscais emitidos — que a última cláusula do formulário manda
 * anexar — é arquivo separado. Não é organização de código: é o requisito de
 * não modificar um formulário padronizado pela Receita.
 */
class AnexoXController extends BaseApiController
{
    public function __construct(
        private readonly AnexoXService $anexoX,
        private readonly AnexoXFechamentoService $fechamentos,
        private readonly AnexoXRenderer $renderer,
        private readonly AdminCredentialVerifier $adminCredentialVerifier,
        private readonly AnexoXAjusteService $ajustes
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('fiscal:visualizar');

        return $this->success([
            'anexo_x' => $this->anexoX->relatorio(
                $this->competencia($request),
                $this->regime($request),
                $request->boolean('reconferir')
            ),
        ], request: $request);
    }

    /**
     * O formulário oficial: um mês, ou o ano inteiro com uma folha por mês.
     *
     * `ano=AAAA` manda no `competencia` quando presente — pedir os doze meses e
     * receber um só seria pior que um erro, porque o operador não perceberia.
     */
    public function pdf(Request $request): Response
    {
        $this->authorize('fiscal:visualizar');

        $regime = $this->regime($request);
        $ano = trim((string) $request->query('ano', ''));

        if (preg_match('/^\d{4}$/', $ano) === 1) {
            return $this->pdfResponse(
                $this->renderer->renderFormularioAnual($this->anexoX->relatorioAnual((int) $ano, $regime)),
                'anexo-x-'.$ano.'.pdf'
            );
        }

        $competencia = $this->competencia($request);

        return $this->pdfResponse(
            $this->renderer->renderFormulario($this->anexoX->relatorio($competencia, $regime)),
            'anexo-x-'.$competencia.'.pdf'
        );
    }

    public function documentosPdf(Request $request): Response
    {
        $this->authorize('fiscal:visualizar');

        $competencia = $this->competencia($request);

        return $this->pdfResponse(
            $this->renderer->renderRelacaoDocumentos($this->anexoX->documentosEmitidosNoMes($competencia)),
            'anexo-x-documentos-'.$competencia.'.pdf'
        );
    }

    /**
     * Congela o mês.
     *
     * Recusa competência malformada em vez de cair no mês corrente, ao
     * contrário das rotas de leitura: um querystring torto não pode congelar o
     * mês errado.
     */
    public function fechar(Request $request): JsonResponse
    {
        $this->authorize('fiscal:encerrar');

        $usuario = $this->authenticatedUser($request);

        if ($usuario === null) {
            return $this->unauthenticatedResponse($request);
        }

        $competencia = (string) $request->input('competencia', '');

        if (! PeriodoMensal::valido($competencia)) {
            return $this->error(
                'Informe a competência no formato AAAA-MM.',
                422,
                'ANEXO_X_COMPETENCIA_INVALIDA',
                null,
                request: $request
            );
        }

        $regime = AnexoXService::normalizarRegime((string) $request->input('regime', ''));

        if ($this->fechamentos->vigente($competencia, $regime) instanceof AnexoXFechamento) {
            return $this->error(
                'Esta competência já está fechada neste regime. Reabra antes de fechar de novo.',
                422,
                'ANEXO_X_JA_FECHADO',
                null,
                request: $request
            );
        }

        // Fecha sobre a apuração AO VIVO, nunca sobre `relatorio()`: este é o
        // ato de congelar, e congelar um valor já congelado não faria sentido.
        $fechamento = $this->fechamentos->fechar(
            $this->anexoX->apurar($competencia, $regime),
            (int) $usuario->id
        );

        return $this->success([
            'fechamento' => $this->fechamentos->apresentar($fechamento),
            'anexo_x' => $this->anexoX->relatorio($competencia, $regime),
        ], request: $request);
    }

    /**
     * Reabre o mês.
     *
     * Exige confirmação de administrador, como reabrir um caixa fechado: um
     * período já declarado ao fisco é da mesma classe de ato.
     */
    public function reabrir(Request $request): JsonResponse
    {
        $this->authorize('fiscal:encerrar');

        $usuario = $this->authenticatedUser($request);

        if ($usuario === null) {
            return $this->unauthenticatedResponse($request);
        }

        $competencia = (string) $request->input('competencia', '');

        if (! PeriodoMensal::valido($competencia)) {
            return $this->error(
                'Informe a competência no formato AAAA-MM.',
                422,
                'ANEXO_X_COMPETENCIA_INVALIDA',
                null,
                request: $request
            );
        }

        $regime = AnexoXService::normalizarRegime((string) $request->input('regime', ''));
        $motivo = trim((string) $request->input('motivo', ''));

        if (mb_strlen($motivo) < 10) {
            return $this->error(
                'Descreva em pelo menos 10 caracteres por que a competência está sendo reaberta.',
                422,
                'ANEXO_X_MOTIVO_OBRIGATORIO',
                null,
                request: $request
            );
        }

        $email = trim((string) $request->input('admin_email', ''));
        $senha = (string) $request->input('admin_password', '');

        if ($email === '' || $senha === '') {
            return $this->error(
                'Reabrir uma competência fechada exige confirmação de um administrador.',
                422,
                'ANEXO_X_ADMIN_AUTH_REQUIRED',
                null,
                request: $request
            );
        }

        $verificacao = $this->adminCredentialVerifier->verify(
            $email,
            $senha,
            'anexo-x-reopen-admin-auth',
            (string) $request->ip()
        );

        $erro = $this->respondToAdminVerification(
            $verificacao,
            $request,
            'ANEXO_X_ADMIN_AUTH_RATE_LIMITED',
            'ANEXO_X_ADMIN_AUTH_INVALID'
        );

        if ($erro !== null) {
            return $erro;
        }

        $fechamento = $this->fechamentos->reabrir($competencia, $regime, (int) $usuario->id, $motivo);

        if (! $fechamento instanceof AnexoXFechamento) {
            return $this->error(
                'Não há competência fechada para reabrir neste regime.',
                422,
                'ANEXO_X_SEM_FECHAMENTO',
                null,
                request: $request
            );
        }

        return $this->success([
            'fechamento' => $this->fechamentos->apresentar($fechamento),
            'anexo_x' => $this->anexoX->relatorio($competencia, $regime),
        ], request: $request);
    }

    /**
     * Os doze meses do ano nos dois regimes — o que alimenta a tabela da tela.
     */
    public function resumo(Request $request): JsonResponse
    {
        $this->authorize('fiscal:visualizar');

        return $this->success([
            'resumo' => $this->anexoX->resumoAnual($this->ano($request)),
        ], request: $request);
    }

    public function ajustes(Request $request): JsonResponse
    {
        $this->authorize('fiscal:visualizar');

        $competencia = $this->competencia($request);
        $regime = $this->regime($request);

        return $this->success([
            'ajustes' => $this->ajustes->apresentar(
                $competencia,
                $regime,
                bloqueado: $this->fechamentos->vigente($competencia, $regime) instanceof AnexoXFechamento
            ),
            'linhas_ajustaveis' => AnexoXAjuste::LINHAS_AJUSTAVEIS,
        ], request: $request);
    }

    /**
     * Lança um ajuste manual numa linha do formulário.
     *
     * Toda recusa é explícita, sem fallback silencioso: lançar receita na
     * competência errada é declaração errada ao fisco, e adivinhar o mês a
     * partir de um querystring torto seria o pior comportamento possível aqui.
     */
    public function lancarAjuste(Request $request): JsonResponse
    {
        $this->authorize('fiscal:editar');

        $usuario = $this->authenticatedUser($request);

        if ($usuario === null) {
            return $this->unauthenticatedResponse($request);
        }

        $competencia = (string) $request->input('competencia', '');

        if (! PeriodoMensal::valido($competencia)) {
            return $this->error(
                'Informe a competência no formato AAAA-MM.',
                422,
                'ANEXO_X_COMPETENCIA_INVALIDA',
                null,
                request: $request
            );
        }

        $linha = strtolower(trim((string) $request->input('linha', '')));

        if (! AnexoXAjuste::linhaAjustavel($linha)) {
            return $this->error(
                'III, VI, IX e X são somas das demais — ajuste a linha de origem.',
                422,
                'ANEXO_X_LINHA_NAO_AJUSTAVEL',
                null,
                request: $request
            );
        }

        $valor = round((float) $request->input('valor', 0), 2);

        if (abs($valor) < 0.01 || abs($valor) > 9999999.99) {
            return $this->error(
                'O valor do ajuste precisa ser diferente de zero.',
                422,
                'ANEXO_X_AJUSTE_VALOR_INVALIDO',
                null,
                request: $request
            );
        }

        $motivo = trim((string) $request->input('motivo', ''));

        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 500) {
            return $this->error(
                'Descreva em pelo menos 10 caracteres por que este ajuste está sendo lançado.',
                422,
                'ANEXO_X_MOTIVO_OBRIGATORIO',
                null,
                request: $request
            );
        }

        $regimes = $request->boolean('aplicar_no_outro_regime')
            ? AnexoXService::regimes()
            : [AnexoXService::normalizarRegime((string) $request->input('regime', ''))];

        // Mês encerrado não aceita ajuste: o congelamento é justamente a
        // promessa de que o número declarado não se move mais.
        foreach ($regimes as $regime) {
            if ($this->fechamentos->vigente($competencia, $regime) instanceof AnexoXFechamento) {
                return $this->error(
                    'Esta competência está encerrada. Reabra antes de ajustar.',
                    422,
                    'ANEXO_X_COMPETENCIA_FECHADA',
                    null,
                    request: $request
                );
            }
        }

        foreach ($regimes as $regime) {
            $this->ajustes->lancar($competencia, $regime, $linha, $valor, $motivo, (int) $usuario->id);
        }

        return $this->respostaDoAjuste($request, $competencia, $regimes[0]);
    }

    public function cancelarAjuste(Request $request, int $ajuste): JsonResponse
    {
        $this->authorize('fiscal:editar');

        $usuario = $this->authenticatedUser($request);

        if ($usuario === null) {
            return $this->unauthenticatedResponse($request);
        }

        $registro = AnexoXAjuste::query()->find($ajuste);

        if (! $registro instanceof AnexoXAjuste) {
            return $this->error('Ajuste não encontrado.', 404, 'ANEXO_X_AJUSTE_NAO_ENCONTRADO', null, request: $request);
        }

        $motivo = trim((string) $request->input('motivo', ''));

        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 500) {
            return $this->error(
                'Descreva em pelo menos 10 caracteres por que este ajuste está sendo cancelado.',
                422,
                'ANEXO_X_MOTIVO_OBRIGATORIO',
                null,
                request: $request
            );
        }

        if ($this->fechamentos->vigente((string) $registro->competencia, (string) $registro->regime) instanceof AnexoXFechamento) {
            return $this->error(
                'Esta competência está encerrada. Reabra antes de cancelar o ajuste.',
                422,
                'ANEXO_X_COMPETENCIA_FECHADA',
                null,
                request: $request
            );
        }

        $this->ajustes->cancelar($registro, (int) $usuario->id, $motivo);

        return $this->respostaDoAjuste($request, (string) $registro->competencia, (string) $registro->regime);
    }

    /**
     * Devolve o relatório recalculado junto do ajuste, para a tela atualizar as
     * linhas e a trilha numa resposta só.
     */
    private function respostaDoAjuste(Request $request, string $competencia, string $regime): JsonResponse
    {
        $relatorio = $this->anexoX->relatorio($competencia, $regime);

        return $this->success([
            'ajustes' => $relatorio['ajustes'],
            'linhas' => $relatorio['linhas'],
            'competencia' => $competencia,
            'regime' => $regime,
        ], request: $request);
    }

    private function ano(Request $request): int
    {
        $ano = trim((string) $request->query('ano', ''));

        if (preg_match('/^\d{4}$/', $ano) !== 1) {
            return (int) now()->format('Y');
        }

        $ano = (int) $ano;

        return $ano >= 2000 && $ano <= (int) now()->addYear()->format('Y') ? $ano : (int) now()->format('Y');
    }

    private function competencia(Request $request): string
    {
        return PeriodoMensal::normalizar((string) $request->query('competencia', ''));
    }

    private function regime(Request $request): string
    {
        return AnexoXService::normalizarRegime((string) $request->query('regime', ''));
    }

    private function pdfResponse(string $bytes, string $nome): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
