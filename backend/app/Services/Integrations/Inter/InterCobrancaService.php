<?php

namespace App\Services\Integrations\Inter;

use App\Models\Financeiro;
use App\Models\Inter\InterCobranca;
use App\Models\Inter\InterEvento;
use App\Models\OrderEvent;
use App\Models\User;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Integrations\PaymentIntegrationSettingsService;
use App\Services\Orders\OrderEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Emissao de cobranca Pix imediata (cob) no Banco Inter.
 *
 * ## O problema central desta classe
 *
 * Emitir cobranca e' uma escrita em DOIS sistemas: o nosso e o do banco. Nao ha
 * transacao entre eles. Um timeout depois do banco ter criado a cobranca
 * deixaria uma cobranca viva la' fora sem nenhum rastro aqui — o cliente
 * pagaria e ninguem saberia de onde veio o dinheiro.
 *
 * A defesa e' gerar o `txid` do NOSSO lado e gravar a linha ANTES de chamar:
 *
 *   1. INSERT local com status EMITINDO
 *   2. PUT /pix/v2/cob/{txid} no banco
 *   3a. sucesso  -> atualiza com o retorno (copia-e-cola, status real)
 *   3b. falha    -> marca FALHA_EMISSAO e MANTEM a linha
 *
 * O passo 3b e' o que importa: falha NAO significa "nao foi criada". Como o
 * txid e' nosso, a conciliacao sempre consegue perguntar depois. Apagar a linha
 * ali destruiria o unico handle para essa pergunta.
 */
class InterCobrancaService
{
    public function __construct(
        private readonly InterClient $client,
        private readonly InterCredentials $credentials,
        private readonly FinanceiroService $financeiroService,
        private readonly OrderEventService $orderEventService,
        private readonly PaymentIntegrationSettingsService $settings,
    ) {
    }

    /**
     * Emite (ou reaproveita) a cobranca Pix de um titulo a receber.
     *
     * @throws InterException
     */
    public function emitir(Financeiro $financeiro, ?User $ator = null): InterCobranca
    {
        $this->assertTituloCobravel($financeiro);
        $this->credentials->assertUsavel();

        $chavePix = $this->chavePix();

        // Idempotencia do clique: dois cliques no botao nao viram duas
        // cobrancas. Uma cobranca ativa e' devolvida como esta'.
        $existente = $this->cobrancaAtivaDe($financeiro);

        if ($existente instanceof InterCobranca) {
            return $existente;
        }

        // Emissao anterior de desfecho desconhecido: antes de criar outra,
        // descobrir o que aconteceu com aquela. Criar uma segunda as cegas
        // poderia deixar duas cobrancas vivas para o mesmo titulo.
        $pendente = $this->cobrancaComFalhaDe($financeiro);

        if ($pendente instanceof InterCobranca) {
            $resolvida = $this->resolverPendencia($pendente);

            if ($resolvida instanceof InterCobranca) {
                return $resolvida;
            }
        }

        $valor = $this->valorEmAberto($financeiro);
        $txid = $this->gerarTxid($financeiro);

        $cobranca = DB::transaction(fn (): InterCobranca => InterCobranca::query()->create([
            'provider' => 'inter',
            'txid' => $txid,
            'conta_corrente' => $this->credentials->contaCorrente() ?: null,
            'financeiro_id' => $financeiro->id,
            'os_id' => $financeiro->os_id,
            'valor' => $valor,
            'status' => InterCobranca::STATUS_EMITINDO,
            'expira_em' => now()->addSeconds($this->expiracaoSegundos()),
            'criado_por_usuario_id' => $ator?->id,
        ]));

        $payload = $this->montarPayload($financeiro, $valor, $chavePix);

        try {
            $resposta = $this->client->put(
                $this->cobPath($txid),
                (array) config('inter.escopos.cobranca', ['cob.write', 'cob.read']),
                $payload
            );
        } catch (InterException $e) {
            $cobranca->update([
                'status' => InterCobranca::STATUS_FALHA_EMISSAO,
                'solicitacao_payload' => $this->payloadParaTrilha($payload),
            ]);

            InterEvento::registrar([
                'txid' => $txid,
                'evento' => 'emissao_falhou',
                'nivel' => 'error',
                'origem' => InterEvento::ORIGEM_MANUAL,
                'http_status' => $e->statusHttp,
                'decisao' => InterEvento::DECISAO_ERRO,
                'motivo' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::channel('pagamentos')->error('[INTER] Falha ao emitir cobranca.', [
                'txid' => $txid,
                'financeiro_id' => $financeiro->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }

        $cobranca = $this->aplicarRespostaDoBanco($cobranca, $resposta, $payload);

        InterEvento::registrar([
            'txid' => $txid,
            'evento' => 'emitida',
            'nivel' => 'info',
            'origem' => InterEvento::ORIGEM_MANUAL,
            'decisao' => 'emitida',
            'payload_reconsulta' => $resposta,
        ]);

        $this->registrarEventoDaOs($financeiro, $cobranca, $ator);

        return $cobranca;
    }

    /** Cobranca ativa do titulo, se houver. */
    public function cobrancaAtivaDe(Financeiro $financeiro): ?InterCobranca
    {
        return InterCobranca::query()
            ->where('financeiro_id', $financeiro->id)
            ->abertas()
            ->latest('id')
            ->first();
    }

    /**
     * Cancela a cobranca no banco e localmente.
     *
     * @throws InterException
     */
    public function cancelar(InterCobranca $cobranca, ?User $ator = null): InterCobranca
    {
        if ($cobranca->valorLiquidado() > 0) {
            throw InterException::local(
                'Esta cobranca ja recebeu pagamento e nao pode ser cancelada.',
                ['txid' => $cobranca->txid]
            );
        }

        $this->client->put(
            $this->cobPath((string) $cobranca->txid),
            (array) config('inter.escopos.cobranca', ['cob.write', 'cob.read']),
            ['status' => InterCobranca::STATUS_REMOVIDA_PELO_RECEBEDOR]
        );

        $cobranca->update([
            'status' => InterCobranca::STATUS_REMOVIDA_PELO_RECEBEDOR,
            'cancelada_em' => now(),
        ]);

        InterEvento::registrar([
            'txid' => $cobranca->txid,
            'evento' => 'cancelada',
            'nivel' => 'info',
            'origem' => InterEvento::ORIGEM_MANUAL,
            'motivo' => 'Cancelamento solicitado por usuario '.($ator?->id ?? '-'),
        ]);

        return $cobranca->fresh();
    }

    /**
     * Descobre o que aconteceu com uma emissao de desfecho desconhecido.
     *
     * Devolve a cobranca quando ela existe e esta' viva no banco; null quando
     * o banco confirma que nao existe (e a' entao emitir outra e' seguro).
     */
    private function resolverPendencia(InterCobranca $cobranca): ?InterCobranca
    {
        try {
            $resposta = $this->client->get(
                $this->cobPath((string) $cobranca->txid),
                (array) config('inter.escopos.cobranca', ['cob.write', 'cob.read'])
            );
        } catch (InterException $e) {
            if ($e->statusHttp === 404) {
                // O banco garante que nao existe: a emissao anterior nao
                // chegou. Marcar como removida encerra o rastro sem mentir.
                $cobranca->update([
                    'status' => InterCobranca::STATUS_REMOVIDA_PELO_PSP,
                    'cancelada_em' => now(),
                ]);

                InterEvento::registrar([
                    'txid' => $cobranca->txid,
                    'evento' => 'emissao_confirmada_inexistente',
                    'nivel' => 'info',
                    'origem' => InterEvento::ORIGEM_MANUAL,
                    'http_status' => 404,
                    'decisao' => InterEvento::DECISAO_IGNORADO,
                ]);

                return null;
            }

            // Nao deu para saber. Nao emitir outra: duas cobrancas vivas para o
            // mesmo titulo e' pior que nenhuma.
            throw InterException::local(
                'Existe uma emissao anterior sem desfecho conhecido (txid '.$cobranca->txid
                .') e nao foi possivel confirmar com o banco. Tente novamente em instantes.',
                ['txid' => $cobranca->txid]
            );
        }

        $cobranca = $this->aplicarRespostaDoBanco($cobranca, $resposta, null);

        InterEvento::registrar([
            'txid' => $cobranca->txid,
            'evento' => 'emissao_confirmada_apos_falha',
            'nivel' => 'warning',
            'origem' => InterEvento::ORIGEM_MANUAL,
            'decisao' => InterEvento::DECISAO_JA_PROCESSADO,
            'payload_reconsulta' => $resposta,
        ]);

        return $cobranca->fresh();
    }

    /**
     * @param  array<string, mixed>  $resposta
     * @param  array<string, mixed>|null  $solicitacao
     */
    private function aplicarRespostaDoBanco(
        InterCobranca $cobranca,
        array $resposta,
        ?array $solicitacao
    ): InterCobranca {
        $atualizacao = [
            'status' => trim((string) ($resposta['status'] ?? InterCobranca::STATUS_ATIVA)),
            'pix_copia_e_cola' => $this->extrairCopiaECola($resposta),
            'location' => trim((string) ($resposta['location'] ?? '')) ?: null,
        ];

        if ($solicitacao !== null) {
            $atualizacao['solicitacao_payload'] = $this->payloadParaTrilha($solicitacao);
        }

        $expiracao = (int) data_get($resposta, 'calendario.expiracao', 0);
        $criacao = trim((string) data_get($resposta, 'calendario.criacao', ''));

        if ($expiracao > 0) {
            $base = $criacao !== '' ? Carbon::parse($criacao) : now();
            $atualizacao['expira_em'] = $base->addSeconds($expiracao);
        }

        $cobranca->update($atualizacao);

        return $cobranca->fresh();
    }

    private function cobrancaComFalhaDe(Financeiro $financeiro): ?InterCobranca
    {
        return InterCobranca::query()
            ->where('financeiro_id', $financeiro->id)
            ->where('status', InterCobranca::STATUS_FALHA_EMISSAO)
            ->whereNull('cancelada_em')
            ->latest('id')
            ->first();
    }

    private function assertTituloCobravel(Financeiro $financeiro): void
    {
        if ($financeiro->tipo !== Financeiro::TIPO_RECEBER) {
            throw InterException::local('So e possivel cobrar titulos a receber.');
        }

        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            throw InterException::local('Este titulo esta cancelado.');
        }

        if ($this->valorEmAberto($financeiro) <= 0) {
            throw InterException::local('Este titulo ja esta totalmente liquidado.');
        }
    }

    private function valorEmAberto(Financeiro $financeiro): float
    {
        // O valor da cobranca e' o SALDO, nao o total do titulo: cobrar o
        // cheio depois de um adiantamento cobraria duas vezes o mesmo dinheiro.
        return round((float) $this->financeiroService->movementSummary($financeiro)['valor_aberto'], 2);
    }

    private function chavePix(): string
    {
        $chave = trim((string) ($this->settings->interSettings()['pagamentos_inter_chave_pix'] ?? ''));

        if ($chave === '') {
            throw InterException::local(
                'Informe a chave Pix do Banco Inter em Configuracoes > Integracoes. '
                .'Ela precisa ser uma chave registrada nesta conta do Inter.'
            );
        }

        return $chave;
    }

    /**
     * @return array<string, mixed>
     */
    private function montarPayload(Financeiro $financeiro, float $valor, string $chavePix): array
    {
        $payload = [
            'calendario' => ['expiracao' => $this->expiracaoSegundos()],
            'valor' => ['original' => number_format($valor, 2, '.', '')],
            'chave' => $chavePix,
            'solicitacaoPagador' => mb_substr(
                trim((string) ($financeiro->descricao ?: config('inter.cobranca.solicitacao_pagador', 'Pagamento de servico'))),
                0,
                140
            ),
        ];

        $devedor = $this->montarDevedor($financeiro);

        if ($devedor !== []) {
            $payload['devedor'] = $devedor;
        }

        return $payload;
    }

    /**
     * Identifica o pagador quando ha' documento valido.
     *
     * Omitido quando o CPF/CNPJ nao bate o tamanho esperado: o Inter recusa a
     * cobranca inteira por documento invalido, e uma cobranca sem devedor e'
     * valida. Melhor emitir sem identificacao do que nao emitir.
     *
     * @return array<string, string>
     */
    private function montarDevedor(Financeiro $financeiro): array
    {
        $cliente = $financeiro->client;

        if ($cliente === null) {
            return [];
        }

        $nome = trim((string) ($cliente->nome_razao ?? ''));
        $documento = preg_replace('/\D/', '', (string) ($cliente->cpf_cnpj ?? '')) ?? '';

        if ($nome === '') {
            return [];
        }

        if (strlen($documento) === 11) {
            return ['cpf' => $documento, 'nome' => mb_substr($nome, 0, 200)];
        }

        if (strlen($documento) === 14) {
            return ['cnpj' => $documento, 'nome' => mb_substr($nome, 0, 200)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadParaTrilha(array $payload): array
    {
        // A chave Pix e' da empresa e aparece no QR de qualquer forma, mas o
        // documento do cliente e' dado dele: nao vai para a trilha.
        if (isset($payload['devedor'])) {
            $payload['devedor'] = ['nome' => $payload['devedor']['nome'] ?? ''];
        }

        return $payload;
    }

    private function extrairCopiaECola(array $resposta): ?string
    {
        foreach (['pixCopiaECola', 'pix_copia_e_cola', 'emv'] as $campo) {
            $valor = trim((string) ($resposta[$campo] ?? ''));

            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    private function registrarEventoDaOs(Financeiro $financeiro, InterCobranca $cobranca, ?User $ator): void
    {
        $osId = (int) ($financeiro->os_id ?? 0);

        if ($osId <= 0) {
            return;
        }

        $this->orderEventService->record(
            $osId,
            OrderEvent::CATEGORIA_FINANCEIRO,
            'cobranca_pix_emitida',
            'Cobranca Pix emitida',
            sprintf('R$ %s via Banco Inter.', number_format((float) $cobranca->valor, 2, ',', '.')),
            ['txid' => $cobranca->txid, 'valor' => (float) $cobranca->valor],
            $ator?->id,
            $ator !== null ? OrderEvent::ORIGEM_USUARIO : OrderEvent::ORIGEM_AUTOMACAO
        );
    }

    private function gerarTxid(Financeiro $financeiro): string
    {
        // txid do Pix: 26 a 35 caracteres [a-zA-Z0-9]. O prefixo com o id do
        // titulo torna o rastreio manual possivel sem consultar o banco.
        return sprintf('ERP%010d%s', (int) $financeiro->id, Str::upper(Str::random(13)));
    }

    private function cobPath(string $txid): string
    {
        return rtrim((string) config('inter.cobranca.path', 'pix/v2/cob'), '/').'/'.$txid;
    }

    private function expiracaoSegundos(): int
    {
        return max(60, (int) config('inter.cobranca.expiracao_segundos', 259200));
    }
}
