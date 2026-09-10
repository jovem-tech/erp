<?php

namespace App\Services\Fiscal;

use App\Models\DocumentoFiscal;
use App\Services\Company\CompanyProfileService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Emite a NFS-e direto pelo sistema, sem passar pelo portal do gov.br.
 *
 * Junta pecas que ja' existiam e nunca tinham sido ligadas: `DpsXmlBuilder`
 * montava e assinava a DPS mas ninguem chamava fora de teste, e o
 * `NfseXmlImporter` sabia ler a nota mas so' era alimentado por upload manual.
 * O que faltava no meio era transmitir.
 *
 * ## O perigo central
 *
 * O pior caso desta integracao nao e' falhar — e' emitir DUAS notas para o
 * mesmo servico. Nota fiscal duplicada nao se desfaz com nova tentativa: exige
 * pedido de cancelamento, com prazo e justificativa. E a janela existe de
 * verdade: o ADN pode processar a DPS e a resposta se perder na volta, deixando
 * o sistema convencido de que nada aconteceu.
 *
 * A protecao tem tres partes, e as tres precisam existir juntas:
 *
 *  1. O `nDPS` e' gravado no documento **antes** de transmitir;
 *  2. Retentativa REUSA esse numero, nunca aloca outro;
 *  3. Antes de retransmitir, pergunta-se ao ADN se aquela DPS ja' virou nota.
 *
 * Sem (1) a retentativa nao saberia o que consultar; sem (2) a consulta olharia
 * para o Id errado; sem (3) a consulta nao aconteceria. Por isso o POST nao tem
 * retry automatico em `AmbienteNacionalClient` — repetir as cegas contorna
 * justamente este desenho.
 */
class EmissaoNfseService
{
    public function __construct(
        private readonly DocumentoFiscalService $documentos,
        private readonly DpsXmlBuilder $builder,
        private readonly AmbienteNacionalClient $cliente,
        private readonly NfseXmlImporter $importador,
        private readonly SequenciaDps $sequencia,
        private readonly CertificadoA1 $certificado,
        private readonly CompanyProfileService $empresa,
        private readonly AmbienteFiscal $ambiente,
    ) {}

    /**
     * @throws ValidationException quando o documento nao esta em condicao de emitir
     * @throws NfseException quando a transmissao falha ou o ADN recusa
     */
    public function emitir(DocumentoFiscal $documento, ?int $usuarioId = null): DocumentoFiscal
    {
        $this->conferirCondicoes($documento);

        $serie = (string) config('fiscal.nfse.serie', '00001');
        $numeroDps = (int) ($documento->numero_dps ?? 0);
        $retentativa = $numeroDps > 0;

        if (! $retentativa) {
            $numeroDps = $this->sequencia->proximo($serie);

            // Gravar ANTES de transmitir. Se o processo morrer no meio da
            // chamada, e' esta linha que permite a proxima tentativa descobrir
            // o que perguntar ao ADN.
            $documento->forceFill(['numero_dps' => $numeroDps])->save();
        }

        $idDps = $this->builder->idDps($numeroDps);

        if ($retentativa) {
            $ja = $this->consultarEmitida($idDps);

            if ($ja !== null) {
                Log::channel('fiscal')->warning('[NFSE] DPS ja constava emitida no ADN; registrando sem reenviar.', [
                    'documento_id' => $documento->id,
                    'id_dps' => $idDps,
                ]);

                return $this->registrar($documento, $ja, $usuarioId);
            }
        }

        Log::channel('fiscal')->info('[NFSE] Transmitindo DPS.', [
            'documento_id' => $documento->id,
            'id_dps' => $idDps,
            'ambiente' => $this->ambiente->atual(),
            'retentativa' => $retentativa,
        ]);

        try {
            $xmlDps = $this->builder->gerarAssinado($documento, $numeroDps);
        } catch (RuntimeException $e) {
            // O builder sinaliza cadastro/layout invalido com `RuntimeException`,
            // que nao e' `NfseException` e por isso escapava do tratamento do
            // controller virando 500. A guarda acima ja' cobre o caso comum;
            // esta e' a rede para o que ela nao previu — o operador precisa ler
            // O QUE falta, nao "erro inesperado".
            throw NfseException::local(
                'A DPS não pôde ser montada: '.$e->getMessage(),
                ['documento_id' => $documento->id]
            );
        }

        try {
            $xmlNfse = $this->cliente->emitir($xmlDps);
        } catch (NfseException $e) {
            // "Essa DPS ja' virou nota" (E0014) NAO e' rejeicao: e' o ADN
            // dizendo que o documento existe do lado dele. Marcar o rascunho
            // como rejeitado seria registrar o oposto da verdade — e deixaria
            // uma nota fiscal emitida sem contrapartida no sistema.
            //
            // Acontece quando a consulta previa nao encontrou a nota (por
            // exemplo, porque o contrato da consulta mudou) e o envio seguiu
            // assim mesmo. A saida certa e' buscar a nota e registra-la.
            if ($this->ehDpsJaEmitida($e)) {
                Log::channel('fiscal')->warning('[NFSE] ADN informou DPS ja emitida; recuperando a nota.', [
                    'documento_id' => $documento->id,
                    'id_dps' => $idDps,
                ]);

                $recuperada = $this->consultarEmitida($idDps);

                if ($recuperada !== null) {
                    return $this->registrar($documento, $recuperada, $usuarioId);
                }
            }

            // Rejeicao de regra de negocio fica registrada NO documento: o
            // operador precisa ver o motivo na tela da nota, nao so' num flash
            // que some no proximo clique.
            //
            // Falha de transporte NAO vira rejeicao — a nota pode existir do
            // outro lado, e marcar "rejeitado" apagaria a pista de que ha' algo
            // a consultar na proxima tentativa.
            if ($e->ehRejeicaoFiscal()) {
                $this->documentos->registrarRejeicao($documento, $e->getMessage());
            }

            throw $e;
        }

        return $this->registrar($documento, $xmlNfse, $usuarioId);
    }

    /**
     * O ADN esta dizendo "essa DPS ja' virou nota"?
     *
     * E0014 e' o codigo; o texto e' conferido junto porque codigo de rejeicao
     * muda de catalogo entre versoes e uma checagem so' por codigo silenciaria
     * o caso mais importante desta integracao.
     */
    private function ehDpsJaEmitida(NfseException $e): bool
    {
        $mensagem = mb_strtolower($e->getMessage());

        return str_contains($mensagem, 'e0014')
            || (str_contains($mensagem, 'ja existe') || str_contains($mensagem, 'já existe'));
    }

    /**
     * Consulta tolerante: aqui a pergunta e' "ja' existe?", e uma consulta que
     * falha nao pode virar o erro principal. Se o ADN nao responde a consulta,
     * seguimos como se nao existisse — e o proprio envio dira' se ha' duplicata.
     */
    private function consultarEmitida(string $idDps): ?string
    {
        try {
            return $this->cliente->consultarPorIdDps($idDps);
        } catch (NfseException $e) {
            Log::channel('fiscal')->warning('[NFSE] Consulta previa falhou; seguindo para o envio.', [
                'id_dps' => $idDps,
                'erro' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * O XML devolvido pelo ADN entra pela MESMA porta do XML baixado a mao —
     * assinatura conferida, tomador conferido, duplicidade conferida.
     */
    private function registrar(DocumentoFiscal $documento, string $xmlNfse, ?int $usuarioId): DocumentoFiscal
    {
        try {
            $resultado = $this->documentos->registrarPorConteudoXml(
                $documento,
                $xmlNfse,
                $this->importador,
                $usuarioId
            );
        } catch (Throwable $e) {
            // ESTE E' O PIOR ESTADO POSSIVEL: a nota EXISTE no Ambiente
            // Nacional — foi autorizada — e nao ficou registrada aqui. E ate'
            // agora ele passava em silencio, porque `ValidationException` nao
            // e' logada pelo Laravel: o operador via um erro na tela e o log
            // nao tinha linha nenhuma entre "Nota autorizada" e o nada.
            //
            // Duas coisas acontecem aqui: o motivo vai para o log fiscal, e o
            // XML — que e' o documento legal, com guarda de cinco anos — e'
            // salvo em disco antes de a excecao subir. Perder o XML de uma
            // nota ja' emitida por causa de uma validacao local seria trocar
            // um problema pequeno por um irreversivel.
            $recuperacao = storage_path(
                'app/private/fiscal/recuperacao/nfse-doc'.$documento->id
                .'-'.now()->format('Ymd-His').'.xml'
            );

            try {
                File::ensureDirectoryExists(dirname($recuperacao), 0750);
                File::put($recuperacao, $xmlNfse);
            } catch (Throwable) {
                $recuperacao = '(nao foi possivel salvar)';
            }

            Log::channel('fiscal')->error('[NFSE] NOTA AUTORIZADA MAS NAO REGISTRADA.', [
                'documento_id' => $documento->id,
                'numero_dps' => $documento->numero_dps,
                'erro' => $e->getMessage(),
                'erros_validacao' => $e instanceof ValidationException ? $e->errors() : null,
                'xml_salvo_em' => $recuperacao,
            ]);

            throw NfseException::local(sprintf(
                'A nota FOI EMITIDA no Ambiente Nacional, mas não pôde ser registrada aqui: %s '
                .'O XML foi salvo em %s. Não emita de novo — tente registrar novamente, '
                .'que o sistema reaproveita a nota já emitida.',
                $e->getMessage(),
                $recuperacao
            ), ['documento_id' => $documento->id]);
        }

        $registrado = $resultado['documento'];

        Log::channel('fiscal')->info('[NFSE] Nota registrada.', [
            'documento_id' => $registrado->id,
            'numero' => $registrado->numero,
            'chave' => $registrado->chave,
        ]);

        return $registrado;
    }

    /**
     * @throws ValidationException|NfseException
     */
    private function conferirCondicoes(DocumentoFiscal $documento): void
    {
        if ($documento->foiEmitido()) {
            throw ValidationException::withMessages([
                'documento' => 'Esta nota já foi emitida. Cancele-a antes de emitir outra.',
            ]);
        }

        if ($documento->tipo !== DocumentoFiscal::TIPO_NFSE) {
            // Peca e' mercadoria: sai por NF-e/NFC-e na SEFAZ estadual, que nao
            // e' este webservice.
            throw ValidationException::withMessages([
                'documento' => 'A emissão automática atende apenas NFS-e (serviço).',
            ]);
        }

        $problemas = $this->certificado->problemas();

        if ($problemas !== []) {
            throw NfseException::local(
                'Certificado A1 indisponível: '.implode(' ', $problemas)
                .' Instale ou corrija em Configurações > Integrações.'
            );
        }

        $this->conferirCadastroDaEmpresa();
        $this->conferirFaixaDaSerie();
    }

    /**
     * A serie tem de estar na faixa do emissor por APLICATIVO PROPRIO.
     *
     * No padrao nacional a serie da DPS declara COMO a nota foi emitida, nao
     * quem a emitiu:
     *
     *   00001-49999  aplicativo proprio (API) — este sistema
     *   50000-69999  emissor movel
     *   70000-79999  emissor web (portal do gov.br)
     *   80000-89999  transcricao manual
     *
     * Falhar aqui, antes de assinar e transmitir, troca uma rejeicao E0010 do
     * ADN — que chega depois de queimar um numero e marcar o documento como
     * rejeitado — por uma mensagem que diz o que fazer. O erro e' facil de
     * cometer: basta copiar a serie de uma nota que a propria empresa emitiu
     * pelo portal, que e' de outra faixa.
     *
     * @throws NfseException
     */
    private function conferirFaixaDaSerie(): void
    {
        $serie = trim((string) config('fiscal.nfse.serie', '00001'));

        if ($serie === '' || ! ctype_digit($serie)) {
            throw NfseException::local(
                "Série fiscal inválida ({$serie}): use apenas dígitos, na faixa 00001 a 49999."
            );
        }

        $numero = (int) $serie;

        if ($numero < 1 || $numero > 49999) {
            throw NfseException::local(sprintf(
                'A série %s não pode ser usada para emitir pelo sistema: essa faixa é de %s. '
                .'Emissão por aplicativo próprio usa série de 00001 a 49999 (FISCAL_NFSE_SERIE).',
                $serie,
                match (true) {
                    $numero >= 50000 && $numero <= 69999 => 'emissor móvel',
                    $numero >= 70000 && $numero <= 79999 => 'emissor web, o portal do gov.br',
                    $numero >= 80000 && $numero <= 89999 => 'transcrição manual',
                    default => 'outro tipo de emissor',
                }
            ));
        }
    }

    /**
     * Os dados da empresa que a DPS exige, conferidos ANTES de alocar o nDPS.
     *
     * Sem isto o `DpsXmlBuilder` estoura no meio da emissao — e estoura tarde
     * demais: o numero da DPS ja' foi alocado e gravado, entao um cadastro
     * incompleto queimava um numero da serie a cada clique. Pior, a excecao de
     * la' e' `RuntimeException`, que nao carrega a intencao de "falta cadastro"
     * e chegava na tela como erro 500 generico, mandando o operador procurar
     * defeito no lugar errado.
     *
     * @throws NfseException
     */
    private function conferirCadastroDaEmpresa(): void
    {
        $settings = (array) ($this->empresa->payload()['settings'] ?? []);

        $obrigatorios = [
            'empresa_cnpj' => 'CNPJ da empresa',
            'empresa_codigo_ibge' => 'código IBGE do município',
            'empresa_codigo_tributacao_nacional' => 'código de tributação nacional do serviço',
        ];

        $faltando = [];

        foreach ($obrigatorios as $chave => $rotulo) {
            if (trim((string) ($settings[$chave] ?? '')) === '') {
                $faltando[] = $rotulo;
            }
        }

        if ($faltando === []) {
            return;
        }

        throw NfseException::local(sprintf(
            'Cadastro fiscal da empresa incompleto — falta %s. '
            .'Preencha em Configurações do Sistema antes de emitir.',
            implode(', ', $faltando)
        ), ['faltando' => array_keys(array_intersect($obrigatorios, $faltando))]);
    }
}
