<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DocumentoFiscal;
use App\Models\Order;
use App\Services\Fiscal\DocumentoFiscalService;
use App\Services\Fiscal\NfseXmlImporter;
use App\Services\Fiscal\NotaFiscalEnvioService;
use App\Services\Pdf\NfseDanfseRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;

/**
 * Modo assistido de emissão (spec 041, fase 042).
 *
 * Autorizava por `os`, com a ressalva de que a decisão voltaria à mesa quando
 * a emissão virasse integração. Voltou antes, e por um motivo melhor: com
 * `os:editar` valendo para tudo, **cancelar documento fiscal** — ato com peso
 * legal, que o fisco cobra e que não se desfaz — exigia a mesma permissão que
 * o técnico usa o dia inteiro para mexer numa OS. Não havia como tirar uma
 * sem tirar a outra.
 *
 * Agora o módulo é `fiscal`, com três níveis:
 *
 *  - `fiscal:visualizar` — ver, baixar XML/PDF, gerar DANFSe;
 *  - `fiscal:criar` — montar rascunho, registrar emissão, importar XML, anexar;
 *  - `fiscal:excluir` — cancelar.
 *
 * A migration que cria o módulo semeia as permissões espelhando o que cada
 * grupo já tinha em `os`, então ninguém perde acesso ao subir. O que muda é que
 * apertar passou a ser possível.
 */
class DocumentoFiscalController extends BaseApiController
{
    public function __construct(
        private readonly DocumentoFiscalService $documentos,
        private readonly NfseXmlImporter $importador
    ) {}

    /**
     * Listagem das notas registradas — a tela "Notas emitidas".
     *
     * `status` aceita lista separada por vírgula (`emitido,cancelado`) porque a
     * tela precisa de "tudo que existe no fisco" numa consulta só: cancelada
     * continua sendo documento, e escondê-la deixaria o operador sem o
     * histórico que a fiscalização pede.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('fiscal:visualizar');

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', 'max:20'],
            'os_id' => ['nullable', 'integer'],
            'busca' => ['nullable', 'string', 'max:120'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [], [
            'busca' => 'busca',
            'de' => 'data inicial',
            'ate' => 'data final',
        ]);

        $paginator = $this->documentos
            ->consultar($validated)
            ->paginate(min((int) ($validated['per_page'] ?? 20), 100));

        return $this->success(
            ['documentos' => array_map($this->mapear(...), $paginator->items())],
            meta: array_merge(
                $this->paginationMeta($paginator),
                ['totais' => $this->documentos->totaisDaConsulta($validated)]
            ),
            request: $request
        );
    }

    public function pendentes(Request $request): JsonResponse
    {
        $this->authorize('fiscal:visualizar');

        $ordens = $this->documentos->ordensPendentesDeNota(
            min((int) $request->query('limite', 100), 500)
        );

        return $this->success([
            'ordens' => $ordens->map(static fn (object $ordem): array => [
                'os_id' => (int) $ordem->id,
                'numero_os' => (string) ($ordem->numero_os ?? ''),
                'valor_final' => (float) ($ordem->valor_final ?? 0),
                'entregue_em' => $ordem->data_entrega_efetiva,
                'cliente_id' => $ordem->cliente_id !== null ? (int) $ordem->cliente_id : null,
                'cliente_nome' => (string) ($ordem->cliente_nome ?? ''),
                'cliente_documento' => (string) ($ordem->cliente_documento ?? ''),
            ])->all(),
            'total' => $ordens->count(),
        ], request: $request);
    }

    /**
     * Monta o rascunho da NFS-e da OS — idempotente: chamar de novo devolve o
     * mesmo rascunho, nunca um segundo.
     */
    public function rascunhoDeOrdem(Request $request, int $order): JsonResponse
    {
        $this->authorize('fiscal:criar');

        $ordem = Order::query()->findOrFail($order);

        $documento = $this->documentos->rascunhoDeOrdem(
            $ordem,
            $this->authenticatedUser($request)?->id
        );

        return $this->success(['documento' => $this->mapear($documento)], request: $request);
    }

    public function registrarEmissao(Request $request, int $documento): JsonResponse
    {
        $this->authorize('fiscal:criar');

        $validated = $request->validate([
            'numero' => ['required', 'string', 'max:30'],
            'serie' => ['nullable', 'string', 'max:10'],
            'chave' => ['nullable', 'string', 'max:60'],
            'emitido_em' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string'],
        ], [], [
            'numero' => 'número da nota',
            'serie' => 'série',
            'chave' => 'chave de acesso',
            'emitido_em' => 'data de emissão',
        ]);

        $registro = $this->documentos->registrarEmissao(
            DocumentoFiscal::query()->findOrFail($documento),
            $validated,
            $this->authenticatedUser($request)?->id
        );

        return $this->success(['documento' => $this->mapear($registro)], request: $request);
    }

    /**
     * Importa o XML da NFS-e e registra a emissão a partir dele.
     */
    public function importarXml(Request $request, int $documento): JsonResponse
    {
        $this->authorize('fiscal:criar');

        $request->validate([
            'arquivo' => ['required', 'file', 'max:10240'],
        ], [], ['arquivo' => 'arquivo XML']);

        $resultado = $this->documentos->registrarPorXml(
            DocumentoFiscal::query()->findOrFail($documento),
            $request->file('arquivo'),
            $this->importador,
            $this->authenticatedUser($request)?->id
        );

        return $this->success([
            'documento' => $this->mapear($resultado['documento']),
            // O que foi lido volta para a tela poder MOSTRAR antes de confiar:
            // importacao silenciosa esconde o XML errado.
            'lido' => $resultado['lido'],
        ], request: $request);
    }

    public function registrarRejeicao(Request $request, int $documento): JsonResponse
    {
        $this->authorize('fiscal:criar');

        $validated = $request->validate([
            'motivo_rejeicao' => ['required', 'string', 'max:500'],
        ], [], ['motivo_rejeicao' => 'motivo da rejeição']);

        $registro = $this->documentos->registrarRejeicao(
            DocumentoFiscal::query()->findOrFail($documento),
            (string) $validated['motivo_rejeicao']
        );

        return $this->success(['documento' => $this->mapear($registro)], request: $request);
    }

    public function cancelar(Request $request, int $documento): JsonResponse
    {
        // Nivel proprio: cancelar nao e' "editar mais um pouco".
        $this->authorize('fiscal:excluir');

        $validated = $request->validate([
            'motivo_cancelamento' => ['required', 'string', 'max:500'],
        ], [], ['motivo_cancelamento' => 'motivo do cancelamento']);

        $registro = $this->documentos->cancelar(
            DocumentoFiscal::query()->findOrFail($documento),
            (string) $validated['motivo_cancelamento'],
            $this->authenticatedUser($request)?->id
        );

        return $this->success(['documento' => $this->mapear($registro)], request: $request);
    }

    public function anexarArquivo(Request $request, int $documento): JsonResponse
    {
        $this->authorize('fiscal:criar');

        $validated = $request->validate([
            'formato' => ['required', 'string', 'in:xml,pdf'],
            'arquivo' => [
                'required',
                'file',
                'max:20480',
                // `mimes` confere a extensao E o conteudo detectado. XML chega
                // como text/xml ou application/xml conforme o navegador.
                'mimetypes:application/xml,text/xml,application/pdf',
            ],
        ], [], ['arquivo' => 'arquivo', 'formato' => 'formato']);

        $registro = $this->documentos->anexarArquivo(
            DocumentoFiscal::query()->findOrFail($documento),
            $request->file('arquivo'),
            (string) $validated['formato']
        );

        return $this->success(['documento' => $this->mapear($registro)], request: $request);
    }

    /**
     * DANFSe gerado a partir do XML guardado.
     *
     * Segue a Nota Tecnica no 008 (SE/CGNFS-e), que e' justamente a norma que
     * passa a geracao do DANFSe para os ERPs — a API publica que fazia isso e'
     * sobrestada em 01/07/2026. Renderizado de um Blade fixo, e **nao** pelo
     * motor de modelos editaveis: o DANFSe tem forma definida em norma, e
     * deixa-lo editavel pela tela de Modelos convidaria alguem a alterar um
     * documento fiscal.
     *
     * O PDF anexado do portal continua com precedencia na tela — nao porque
     * este aqui valha menos, mas porque e' o arquivo que o operador ja' baixou.
     *
     * Nada do cadastro da empresa entra no documento: o item 2.1 da NT-008
     * proibe imprimir informacao que nao conste do arquivo da NFS-e.
     */
    public function danfse(
        Request $request,
        int $documento,
        NfseDanfseRenderer $renderer
    ): JsonResponse|Response {
        $this->authorize('fiscal:visualizar');

        $registro = DocumentoFiscal::query()->findOrFail($documento);

        try {
            $dados = $this->documentos->dadosDoXml($registro, $this->importador);
        } catch (\Throwable $excecao) {
            return $this->error($excecao->getMessage(), 422, 'DANFSE_SEM_XML', request: $request);
        }

        // A geracao mora em `App\Services\Pdf`: e' o unico namespace
        // autorizado a chamar dompdf (PdfEngineGuardTest).
        $pdf = $renderer->render(
            $dados,
            // Item 2.5.1: nota cancelada sai com marca d'agua.
            $registro->status === DocumentoFiscal::STATUS_CANCELADO ? 'CANCELADA' : null
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="danfse-'.($dados['numero'] ?? $registro->id).'.pdf"',
        ]);
    }

    /**
     * Serve o XML ou o PDF guardado, para a tela poder exibir o DANFSe oficial.
     */
    public function baixarArquivo(Request $request, int $documento, string $formato): StreamedResponse|JsonResponse
    {
        $this->authorize('fiscal:visualizar');

        $formato = strtolower($formato);

        if (! in_array($formato, ['xml', 'pdf'], true)) {
            return $this->error('Formato inválido.', 422, 'FORMATO_INVALIDO', request: $request);
        }

        $registro = DocumentoFiscal::query()->findOrFail($documento);
        $caminho = (string) ($registro->{$formato.'_arquivo'} ?? '');

        if ($caminho === '' || ! Storage::disk('local')->exists($caminho)) {
            return $this->error('Arquivo não encontrado.', 404, 'ARQUIVO_AUSENTE', request: $request);
        }

        return Storage::disk('local')->response(
            $caminho,
            sprintf('nfse-%s.%s', $registro->numero ?: $registro->id, $formato),
            // PDF abre embutido (a tela mostra o DANFSe); XML baixa.
            ['Content-Type' => $formato === 'pdf' ? 'application/pdf' : 'application/xml'],
            $formato === 'pdf' ? 'inline' : 'attachment'
        );
    }

    /**
     * Abre documento novo para a OS depois que a nota anterior foi cancelada.
     *
     * Separado do `rascunhoDeOrdem`, que e' idempotente: abrir nota nova tem de
     * ser ato explicito do operador, e nao efeito de abrir a tela.
     */
    public function novoDocumento(Request $request, int $order): JsonResponse
    {
        $this->authorize('fiscal:criar');

        $documento = $this->documentos->novoRascunhoAposCancelamento(
            Order::query()->findOrFail($order),
            $request->user()?->id
        );

        return $this->success(['documento' => $this->mapear($documento)], request: $request);
    }

    /**
     * Envia a nota ao cliente por e-mail ou WhatsApp.
     *
     * O destino chega do cliente por padrao, mas e' aceito digitado: nota de
     * cliente que trocou de e-mail nao pode depender de alguem lembrar de
     * atualizar o cadastro antes.
     */
    public function enviar(Request $request, int $documento, NotaFiscalEnvioService $envio): JsonResponse
    {
        $this->authorize('fiscal:visualizar');

        $registro = DocumentoFiscal::query()->findOrFail($documento);

        $validated = $request->validate([
            'canal' => ['required', 'string', 'in:'.implode(',', NotaFiscalEnvioService::CANAIS)],
            'destino' => ['required', 'string', 'max:190'],
            'mensagem' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'canal' => 'canal',
            'destino' => 'destino',
            'mensagem' => 'mensagem',
        ]);

        $resultado = $envio->enviar(
            $registro,
            (string) $validated['canal'],
            (string) $validated['destino'],
            $validated['mensagem'] ?? null,
            $request->user()?->id
        );

        return $this->success([
            'envio' => $resultado,
            'documento' => $this->mapear($registro->refresh()),
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapear(DocumentoFiscal $documento): array
    {
        return [
            'id' => (int) $documento->id,
            'tipo' => (string) $documento->tipo,
            'status' => (string) $documento->status,
            'os_id' => $documento->os_id !== null ? (int) $documento->os_id : null,
            // Numero da OS vem do join da listagem. Nas rotas de um documento
            // so' ele nao esta selecionado, e ai' fica vazio de proposito: nao
            // vale uma consulta por documento para preencher um campo que
            // aquelas telas nao mostram.
            'numero_os' => (string) ($documento->numero_os ?? ''),
            'venda_id' => $documento->venda_id !== null ? (int) $documento->venda_id : null,
            'cliente_id' => $documento->cliente_id !== null ? (int) $documento->cliente_id : null,
            'tomador_nome' => (string) ($documento->tomador_nome ?? ''),
            'tomador_documento' => (string) ($documento->tomador_documento ?? ''),
            'discriminacao' => (string) ($documento->discriminacao ?? ''),
            'valor_servicos' => (float) $documento->valor_servicos,
            'valor_pecas' => (float) $documento->valor_pecas,
            'valor_total' => (float) $documento->valor_total,
            'numero' => (string) ($documento->numero ?? ''),
            'serie' => (string) ($documento->serie ?? ''),
            'chave' => (string) ($documento->chave ?? ''),
            'emitido_em' => $documento->emitido_em?->toIso8601String(),
            'cancelado_em' => $documento->cancelado_em?->toIso8601String(),
            'motivo_cancelamento' => (string) ($documento->motivo_cancelamento ?? ''),
            'motivo_rejeicao' => (string) ($documento->motivo_rejeicao ?? ''),
            'tem_xml' => ($documento->xml_arquivo ?? '') !== '',
            'tem_pdf' => ($documento->pdf_arquivo ?? '') !== '',
            // O valor declarado no XML e' o que vale; quando ele diverge do que
            // a OS calculou, a listagem precisa dizer — o numero certo de uma
            // nota com outro valor e' pior que nenhum numero.
            'valor_xml' => $documento->valor_xml !== null ? (float) $documento->valor_xml : null,
            'valor_diverge' => $documento->valorDivergeDoXml(),
            // Contatos cadastrados do cliente: a tela de envio ja' abre com
            // eles preenchidos, e o operador so' digita quando for outro.
            'contatos' => app(NotaFiscalEnvioService::class)->destinos($documento),
        ];
    }
}
