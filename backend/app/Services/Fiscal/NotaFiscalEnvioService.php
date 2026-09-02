<?php

namespace App\Services\Fiscal;

use App\Models\DocumentoFiscal;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Orders\OrderEventService;
use App\Services\Pdf\NfseDanfseRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Envia a nota fiscal ao cliente por e-mail ou WhatsApp.
 *
 * **O que vai anexado é o par XML + DANFSe**, e não só o PDF. O XML é o
 * documento fiscal em si — é dele que o contador do cliente precisa, e é ele
 * que prova a autenticidade; o DANFSe é a representação que a pessoa lê. Mandar
 * só o PDF é o erro comum deste fluxo, e obriga o cliente a pedir o XML depois.
 *
 * **Não reusa o `OrderDocumentCenterService`** de propósito, apesar de o envio
 * ser parecido: aquele serviço opera sobre `OrderDocument` (documentos que o
 * próprio sistema gera a partir de modelos editáveis) e a nota fiscal não é um
 * deles — ela é um `DocumentoFiscal`, com XML assinado pelo Ambiente Nacional e
 * guarda legal de cinco anos. Transformá-la num documento daquele catálogo a
 * exporia ao motor de modelos, que é justamente o que o DANFSe não pode ter.
 * O que se reusa são os despachadores de canal, que são os mesmos.
 */
class NotaFiscalEnvioService
{
    public const CANAIS = ['email', 'whatsapp'];

    /**
     * Teto do trecho de serviços na mensagem, em caracteres. A legenda de um
     * documento no WhatsApp é limitada, e uma OS com muitos serviços comeria o
     * espaço da mensagem inteira.
     */
    private const LIMITE_SERVICO = 400;

    public function __construct(
        private readonly DocumentoFiscalService $documentos,
        private readonly NfseXmlImporter $importador,
        private readonly NfseDanfseRenderer $renderer,
        private readonly IntegrationSettingsService $integracoes,
        private readonly OrderEventService $eventos,
    ) {}

    /**
     * Contatos do cliente, que o formulário de envio traz preenchidos.
     *
     * São os mesmos campos que o Centro de Documentos da OS usa — se
     * divergissem, o mesmo cliente receberia documentos em endereços
     * diferentes conforme a tela de onde saiu.
     *
     * @return array<string, string>
     */
    public function destinos(DocumentoFiscal $documento): array
    {
        $cliente = $documento->client;

        return [
            'email' => trim((string) ($cliente?->email ?? '')),
            'whatsapp' => trim((string) ($cliente?->telefone1 ?? $cliente?->telefone_contato ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function enviar(
        DocumentoFiscal $documento,
        string $canal,
        string $destino,
        ?string $mensagem = null,
        ?int $usuarioId = null
    ): array {
        $canal = $this->conferirCanal($canal);
        $destino = $this->conferirDestino($canal, $destino);

        if (! in_array((string) $documento->status, [DocumentoFiscal::STATUS_EMITIDO, DocumentoFiscal::STATUS_CANCELADO], true)) {
            throw ValidationException::withMessages([
                'canal' => 'Só dá para enviar nota emitida. Registre a emissão antes.',
            ]);
        }

        $arquivos = $this->arquivos($documento);

        if ($arquivos === []) {
            throw ValidationException::withMessages([
                'canal' => 'Esta nota não tem XML nem PDF guardado. Anexe o XML do portal antes de enviar.',
            ]);
        }

        $texto = trim((string) $mensagem) !== '' ? trim((string) $mensagem) : $this->mensagemPadrao($documento);

        try {
            $resultado = $canal === 'email'
                ? $this->porEmail($documento, $destino, $texto, $arquivos)
                : $this->porWhatsapp($destino, $texto, $arquivos);
        } finally {
            // O DANFSe gerado na hora vive num arquivo temporario; some aqui
            // dando certo ou errado.
            $this->limpar($arquivos);
        }

        $this->registrarNaTimeline($documento, $canal, $destino, $resultado, $usuarioId);

        if (! ($resultado['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'canal' => (string) ($resultado['message'] ?? 'Não foi possível enviar a nota.'),
            ]);
        }

        return [
            'canal' => $canal,
            'destino' => $this->mascarar($canal, $destino),
            'mensagem' => $texto,
        ];
    }

    // -----------------------------------------------------------------

    private function conferirCanal(string $canal): string
    {
        $canal = strtolower(trim($canal));

        if (! in_array($canal, self::CANAIS, true)) {
            throw ValidationException::withMessages(['canal' => 'Canal de envio inválido.']);
        }

        return $canal;
    }

    /**
     * O destino é sempre conferido aqui, e não só na tela: a tela oferece o
     * contato cadastrado, mas o operador pode digitar outro — e é digitando que
     * a nota do cliente vai para o endereço errado.
     */
    private function conferirDestino(string $canal, string $destino): string
    {
        $destino = trim($destino);

        if ($canal === 'email') {
            if (filter_var($destino, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['destino' => 'E-mail inválido.']);
            }

            return $destino;
        }

        $digitos = (string) preg_replace('/\D/', '', $destino);

        // 10 = fixo com DDD, 11 = celular com DDD; 12 e 13 com o 55 na frente.
        if (strlen($digitos) < 10 || strlen($digitos) > 13) {
            throw ValidationException::withMessages([
                'destino' => 'Telefone inválido. Informe com DDD, por exemplo (22) 99999-8888.',
            ]);
        }

        return $digitos;
    }

    /**
     * DANFSe primeiro; o XML só vai para tomador com CNPJ.
     *
     * **A ordem é a do leitor, não a da importância fiscal.** O DANFSe é o que a
     * pessoa abre e entende; o XML é o arquivo que ela repassa ao contador.
     * Mandando o XML na frente — como saía antes —, o cliente recebia primeiro
     * um arquivo que não abre no celular, e o documento legível chegava por
     * último e mudo.
     *
     * **Pessoa física não recebe o XML.** Quem tem CPF não tem contador para
     * quem repassar o arquivo, e o que chega é um anexo que não abre no celular
     * e não serve para nada — ruído no meio da nota que ela queria ver. Para
     * CNPJ o XML é justamente o que o contador vai pedir, então vai junto.
     *
     * O PDF do portal tem precedência quando foi anexado: é o arquivo que o
     * operador já tem em mãos. Sem ele, o DANFSe é gerado na hora pela NT-008.
     *
     * @return array<int, array<string, string>>
     */
    private function arquivos(DocumentoFiscal $documento): array
    {
        $arquivos = [];
        $numero = (string) ($documento->numero ?: $documento->id);

        $pdf = (string) ($documento->pdf_arquivo ?? '');

        if ($pdf !== '' && Storage::disk('local')->exists($pdf)) {
            $arquivos[] = [
                'papel' => 'danfse',
                'absolute_path' => (string) Storage::disk('local')->path($pdf),
                'filename' => sprintf('danfse-%s.pdf', $numero),
                'mime_type' => 'application/pdf',
                'temporario' => '0',
            ];
        } else {
            $gerado = $this->danfseGerado($documento, $numero);

            if ($gerado !== null) {
                $arquivos[] = $gerado;
            }
        }

        $xml = (string) ($documento->xml_arquivo ?? '');

        if ($this->tomadorTemCnpj($documento) && $xml !== '' && Storage::disk('local')->exists($xml)) {
            $arquivos[] = [
                'papel' => 'xml',
                'absolute_path' => (string) Storage::disk('local')->path($xml),
                'filename' => sprintf('nfse-%s.xml', $numero),
                'mime_type' => 'application/xml',
                'temporario' => '0',
            ];
        }

        return $arquivos;
    }

    /**
     * O tomador da nota é pessoa jurídica?
     *
     * Decide pelo documento gravado NO DOCUMENTO FISCAL, e não pelo cadastro do
     * cliente: o que vale é quem consta na nota emitida — o cadastro pode ter
     * mudado depois.
     */
    private function tomadorTemCnpj(DocumentoFiscal $documento): bool
    {
        return strlen((string) preg_replace('/\D/', '', (string) ($documento->tomador_documento ?? ''))) === 14;
    }

    /**
     * @return array<string, string>|null
     */
    private function danfseGerado(DocumentoFiscal $documento, string $numero): ?array
    {
        try {
            $dados = $this->documentos->dadosDoXml($documento, $this->importador);
        } catch (RuntimeException) {
            // Sem XML nao ha DANFSe para desenhar. O XML anexado, se existir,
            // ja' foi para a lista.
            return null;
        }

        $caminho = tempnam(sys_get_temp_dir(), 'danfse_').'.pdf';

        file_put_contents($caminho, $this->renderer->render(
            $dados,
            (string) $documento->status === DocumentoFiscal::STATUS_CANCELADO ? 'CANCELADA' : null
        ));

        return [
            'papel' => 'danfse',
            'absolute_path' => $caminho,
            'filename' => sprintf('danfse-%s.pdf', $numero),
            'mime_type' => 'application/pdf',
            'temporario' => '1',
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $arquivos
     */
    private function limpar(array $arquivos): void
    {
        foreach ($arquivos as $arquivo) {
            if (($arquivo['temporario'] ?? '0') === '1' && is_file($arquivo['absolute_path'])) {
                @unlink($arquivo['absolute_path']);
            }
        }
    }

    /**
     * Mensagem curta e humana.
     *
     * Antes ela explicava os dois anexos numa frase só, e ficava longa justo no
     * lugar em que o cliente só quer saber o que chegou. Agora cada anexo se
     * explica na própria legenda, e esta linha diz apenas de que nota se trata.
     */
    private function mensagemPadrao(DocumentoFiscal $documento): string
    {
        $numero = (string) ($documento->numero ?: '—');
        $os = trim((string) ($documento->order?->numero_os ?? ''));
        $referencia = $os !== '' ? sprintf(', referente à ordem de serviço %s', $os) : '';

        $abertura = (string) $documento->status === DocumentoFiscal::STATUS_CANCELADO
            ? sprintf(
                'Olá! A nota fiscal de serviço nº %s%s foi CANCELADA. '
                    .'Segue o documento com a marca de cancelamento.',
                $numero,
                $referencia
            )
            : sprintf('Olá! Segue a nota fiscal de serviço nº %s%s.', $numero, $referencia);

        $blocos = array_filter([
            $abertura,
            $this->descricaoDoEquipamento($documento),
            $this->servicoExecutado($documento),
        ], static fn (string $bloco): bool => $bloco !== '');

        return implode("\n\n", $blocos);
    }

    /**
     * O que foi executado, lido da OS.
     *
     * A fonte é o item de serviço da OS (`os_itens`, tipo `servico`) — é o que
     * o cliente contratou e pagou, e é o campo que a oficina realmente preenche:
     * 2.257 OS têm item de serviço, contra 69 com `solucao_aplicada`. Quando não
     * há item, a solução aplicada entra como reserva.
     *
     * **O que NÃO entra:** `procedimentos_executados` é diário do técnico, com
     * nome e horário de cada tentativa — registro interno, não descrição para o
     * cliente. E `relato_cliente` é o problema relatado, não o serviço feito:
     * sairia sob um rótulo que mente.
     *
     * Sem nenhuma das duas fontes o bloco some. Preencher com genérico
     * ("serviços de assistência técnica") ocuparia linha sem dizer nada.
     */
    private function servicoExecutado(DocumentoFiscal $documento): string
    {
        $order = $documento->order;

        if ($order === null) {
            return '';
        }

        $linhas = [];

        foreach (OrderItem::query()->where('os_id', $order->id)->where('tipo', 'servico')->orderBy('id')->get() as $item) {
            $descricao = trim((string) ($item->descricao ?? ''));

            if ($descricao === '') {
                continue;
            }

            $quantidade = (int) (float) ($item->quantidade ?? 1);

            $linhas[] = $quantidade > 1 ? sprintf('%s (%dx)', $descricao, $quantidade) : $descricao;
        }

        if ($linhas === []) {
            $solucao = trim((string) ($order->solucao_aplicada ?? ''));

            if ($solucao === '') {
                return '';
            }

            $linhas[] = $solucao;
        }

        // Marcador so' quando ha' mais de um: numa linha unica ele vira enfeite.
        $texto = count($linhas) > 1
            ? implode("\n", array_map(static fn (string $l): string => '• '.$l, $linhas))
            : $linhas[0];

        // Legenda de documento no WhatsApp tem limite, e uma OS com muitos
        // servicos comeria o espaco da mensagem inteira.
        if (mb_strlen($texto) > self::LIMITE_SERVICO) {
            $texto = mb_substr($texto, 0, self::LIMITE_SERVICO).'...';
        }

        return (count($linhas) > 1 ? 'Serviços executados:' : 'Serviço executado:')."\n".$texto;
    }

    /**
     * "Equipamento: ..." e o número de série, quando a OS tem essa informação.
     *
     * Serve para o cliente reconhecer a que atendimento a nota se refere sem
     * abrir o anexo — quem deixa três aparelhos na assistência recebe três notas
     * parecidas, e o número da OS sozinho não diz qual é qual.
     *
     * Cada parte só entra se existir: aparelho sem modelo cadastrado não pode
     * produzir "Equipamento: Smartphone  " com buraco no meio.
     */
    private function descricaoDoEquipamento(DocumentoFiscal $documento): string
    {
        $equipamento = $documento->order?->equipment;

        if ($equipamento === null) {
            return '';
        }

        // O tipo abre a linha, e vem do cadastro como o operador digitou
        // ("notebook"). So' a inicial sobe: mexer no resto estragaria siglas
        // como "TV" e "PC".
        $tipo = trim((string) ($equipamento->type?->nome ?? ''));
        $tipo = $tipo === '' ? '' : mb_strtoupper(mb_substr($tipo, 0, 1)).mb_substr($tipo, 1);

        $partes = array_filter([
            $tipo,
            trim((string) ($equipamento->brand?->nome ?? '')),
            trim((string) ($equipamento->model?->nome ?? '')),
        ], static fn (string $parte): bool => $parte !== '');

        $linhas = [];

        if ($partes !== []) {
            $linhas[] = 'Equipamento: '.implode(' ', $partes);
        }

        // Numero de serie e IMEI sao rotulados pelo que sao: chamar IMEI de
        // "numero de serie" confunde quem vai conferir contra o aparelho.
        $serie = trim((string) ($equipamento->numero_serie ?? ''));
        $imei = trim((string) ($equipamento->imei ?? ''));

        if ($serie !== '') {
            $linhas[] = 'Número de série: '.$serie;
        } elseif ($imei !== '') {
            $linhas[] = 'IMEI: '.$imei;
        }

        return implode("\n", $linhas);
    }

    /**
     * @param  array<int, array<string, string>>  $arquivos
     * @return array<string, mixed>
     */
    private function porEmail(DocumentoFiscal $documento, string $destino, string $texto, array $arquivos): array
    {
        $assunto = sprintf('Nota fiscal de serviço nº %s', $documento->numero ?: $documento->id);

        // No WhatsApp cada anexo leva a sua legenda; no e-mail eles chegam
        // juntos, entao a explicacao do que e' cada arquivo vai no corpo — e so'
        // cita o XML quando ele realmente foi anexado.
        $temXml = array_filter(
            $arquivos,
            static fn (array $arquivo): bool => ($arquivo['papel'] ?? '') === 'xml'
        ) !== [];

        $corpo = $texto."\n\n"
            .'Em anexo:'."\n"
            .'• DANFSe (PDF) — a nota para leitura e impressão.'
            .($temXml ? "\n".'• XML — o documento fiscal, para o seu contador.' : '');

        try {
            Mail::html(nl2br(e($corpo), false), function ($mail) use ($destino, $assunto, $arquivos): void {
                $mail->to($destino)->subject($assunto);

                foreach ($arquivos as $arquivo) {
                    $mail->attach($arquivo['absolute_path'], [
                        'as' => $arquivo['filename'],
                        'mime' => $arquivo['mime_type'],
                    ]);
                }
            });
        } catch (Throwable $excecao) {
            return ['ok' => false, 'provider' => 'mail', 'message' => $excecao->getMessage()];
        }

        return ['ok' => true, 'provider' => 'mail', 'message' => 'Nota enviada por e-mail.'];
    }

    /**
     * @param  array<int, array<string, string>>  $arquivos
     * @return array<string, mixed>
     */
    private function porWhatsapp(string $destino, string $texto, array $arquivos): array
    {
        $referencia = null;

        foreach ($arquivos as $arquivo) {
            // Cada anexo se explica. A mensagem principal vai no DANFSe, que e'
            // o que a pessoa abre; o XML leva uma linha propria dizendo para que
            // serve — sem ela chega um arquivo mudo que ninguem sabe abrir.
            $legenda = ($arquivo['papel'] ?? '') === 'xml'
                ? 'XML da nota fiscal — é o arquivo que o seu contador precisa. '
                    .'Não precisa abrir no celular; basta encaminhar.'
                : $texto;

            try {
                $resultado = $this->integracoes->sendDirectMedia(
                    $destino,
                    $arquivo['absolute_path'],
                    'document',
                    $legenda,
                    $arquivo['filename']
                );
            } catch (Throwable $excecao) {
                return ['ok' => false, 'provider' => 'whatsapp', 'message' => $excecao->getMessage()];
            }

            if (! ($resultado['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'provider' => (string) ($resultado['provider'] ?? 'whatsapp'),
                    'message' => (string) ($resultado['message'] ?? 'Falha ao enviar pelo WhatsApp.'),
                ];
            }

            $referencia = (string) ($resultado['reference'] ?? $referencia);
        }

        return [
            'ok' => true,
            'provider' => 'whatsapp',
            'reference' => $referencia,
            'message' => 'Nota enviada pelo WhatsApp.',
        ];
    }

    /**
     * Registra na timeline da OS, e não numa tabela nova: mandar documento
     * fiscal ao cliente é ato que se presta contas, e a timeline já é onde se
     * procura "o que aconteceu nesta OS".
     *
     * @param  array<string, mixed>  $resultado
     */
    private function registrarNaTimeline(
        DocumentoFiscal $documento,
        string $canal,
        string $destino,
        array $resultado,
        ?int $usuarioId
    ): void {
        if ($documento->os_id === null) {
            return;
        }

        $ok = (bool) ($resultado['ok'] ?? false);

        $this->eventos->record(
            (int) $documento->os_id,
            'fiscal',
            $ok ? 'nota_enviada' : 'nota_envio_falhou',
            $ok ? 'Nota fiscal enviada ao cliente' : 'Falha ao enviar a nota fiscal',
            sprintf(
                '%s para %s%s',
                $canal === 'email' ? 'E-mail' : 'WhatsApp',
                $this->mascarar($canal, $destino),
                $ok ? '' : ': '.(string) ($resultado['message'] ?? '')
            ),
            [
                'documento_fiscal_id' => (int) $documento->id,
                'numero' => (string) ($documento->numero ?? ''),
                'canal' => $canal,
                // Mascarado tambem nos dados: a timeline e' lida por quem nao
                // precisa do contato completo do cliente.
                'destino' => $this->mascarar($canal, $destino),
                'provedor' => (string) ($resultado['provider'] ?? ''),
            ],
            $usuarioId,
            OrderEvent::ORIGEM_USUARIO
        );
    }

    private function mascarar(string $canal, string $destino): string
    {
        if ($canal === 'email') {
            [$usuario, $dominio] = array_pad(explode('@', $destino, 2), 2, '');

            return mb_substr($usuario, 0, 2).str_repeat('*', max(1, mb_strlen($usuario) - 2)).'@'.$dominio;
        }

        return str_repeat('*', max(0, strlen($destino) - 4)).substr($destino, -4);
    }
}
