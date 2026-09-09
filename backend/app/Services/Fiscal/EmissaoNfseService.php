<?php

namespace App\Services\Fiscal;

use App\Models\DocumentoFiscal;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
            'ambiente' => (int) config('fiscal.nfse.ambiente', 2),
            'retentativa' => $retentativa,
        ]);

        $xmlDps = $this->builder->gerarAssinado($documento, $numeroDps);

        try {
            $xmlNfse = $this->cliente->emitir($xmlDps);
        } catch (NfseException $e) {
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
        $resultado = $this->documentos->registrarPorConteudoXml(
            $documento,
            $xmlNfse,
            $this->importador,
            $usuarioId
        );

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
    }
}
