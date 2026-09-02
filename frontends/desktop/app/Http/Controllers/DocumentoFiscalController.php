<?php

namespace App\Http\Controllers;

use App\Services\DocumentoFiscalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Emissão assistida de NFS-e (spec 041, fase 042).
 *
 * O sistema não fala com o governo: ele monta o que o operador precisa colar no
 * Emissor Nacional e guarda o retorno — número, chave e os arquivos baixados do
 * portal.
 */
class DocumentoFiscalController extends DesktopController
{
    public function __construct(
        private readonly DocumentoFiscalService $documentoFiscalService
    ) {
    }

    public function pendentes(): View
    {
        return view('fiscal.pendentes', [
            'pageTitle' => 'Notas pendentes',
            'ordens' => $this->documentoFiscalService->pendentes(),
        ]);
    }

    /**
     * Notas já registradas.
     *
     * Contraparte de `pendentes()`: lá o eixo é a OS que ainda não tem nota,
     * aqui é o documento que já existe. O padrão da tela é `emitido,cancelado`
     * — o que existe no fisco. Cancelada entra porque some da lista de
     * pendentes (a OS volta para a fila) e sumiria de todo lugar se não
     * aparecesse aqui; o histórico é parte da guarda de 5 anos.
     */
    public function emitidas(Request $request): View
    {
        $filtros = [
            'busca' => trim((string) $request->query('busca', '')),
            'status' => (string) $request->query('status', 'emitido,cancelado'),
            'de' => (string) $request->query('de', ''),
            'ate' => (string) $request->query('ate', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
        ];

        $resultado = $this->documentoFiscalService->listar(array_filter(
            $filtros,
            static fn ($valor): bool => $valor !== '' && $valor !== 0
        ));

        return view('fiscal.emitidas', [
            'pageTitle' => 'Notas emitidas',
            'documentos' => $resultado['items'],
            'pagination' => $resultado['pagination'],
            'totais' => $resultado['totais'],
            'filtros' => $filtros,
        ]);
    }

    public function nota(int $order): View
    {
        return view('fiscal.nota', [
            'pageTitle' => 'Emitir nota da OS',
            'osId' => $order,
            'documento' => $this->documentoFiscalService->rascunhoDeOrdem($order),
        ]);
    }

    public function registrarEmissao(Request $request, int $documento): RedirectResponse
    {
        $validated = $request->validate([
            'os_id' => ['required', 'integer'],
            'numero' => ['required', 'string', 'max:30'],
            'serie' => ['nullable', 'string', 'max:10'],
            'chave' => ['nullable', 'string', 'max:60'],
            'emitido_em' => ['nullable', 'date'],
        ], [
            'numero.required' => 'Informe o número da NFS-e em "Ou registrar à mão". '
                .'Ele está no alto do DANFSe, no bloco NÚMERO DA NFS-e — não é o número da DPS.',
        ], ['numero' => 'número da nota', 'chave' => 'chave de acesso']);

        $this->documentoFiscalService->registrarEmissao($documento, collect($validated)->except('os_id')->all());

        return redirect()
            ->route('fiscal.nota', $validated['os_id'])
            ->with('success', 'Emissão registrada. Guarde o XML e o PDF baixados do portal.');
    }

    public function registrarRejeicao(Request $request, int $documento): RedirectResponse
    {
        $validated = $request->validate([
            'os_id' => ['required', 'integer'],
            'motivo_rejeicao' => ['required', 'string', 'max:500'],
        ], [], ['motivo_rejeicao' => 'motivo da rejeição']);

        $this->documentoFiscalService->registrarRejeicao($documento, (string) $validated['motivo_rejeicao']);

        return redirect()
            ->route('fiscal.nota', $validated['os_id'])
            ->with('success', 'Rejeição registrada. Corrija o cadastro e tente de novo.');
    }

    public function cancelar(Request $request, int $documento): RedirectResponse
    {
        $validated = $request->validate([
            'os_id' => ['required', 'integer'],
            'motivo_cancelamento' => ['required', 'string', 'max:500'],
        ], [], ['motivo_cancelamento' => 'motivo do cancelamento']);

        $this->documentoFiscalService->cancelar($documento, (string) $validated['motivo_cancelamento']);

        return redirect()
            ->route('fiscal.nota', $validated['os_id'])
            ->with('success', 'Documento cancelado. O número continua registrado — a substituta é uma nota nova.');
    }

    /**
     * Abre nota nova para a OS depois que a anterior foi cancelada.
     */
    public function novoDocumento(Request $request, int $order): RedirectResponse
    {
        $this->documentoFiscalService->novoDocumento($order);

        return redirect()
            ->route('fiscal.nota', $order)
            ->with('success', 'Nota nova aberta. Emita no portal e registre o retorno aqui.');
    }

    /**
     * Envia a nota ao cliente por e-mail ou WhatsApp.
     *
     * O destino chega preenchido com o contato do cadastro, mas e' um campo de
     * texto: cliente que trocou de e-mail nao pode travar o envio ate' alguem
     * lembrar de atualizar o cadastro.
     */
    public function enviar(Request $request, int $documento): RedirectResponse
    {
        $validated = $request->validate([
            'os_id' => ['required', 'integer'],
            'canal' => ['required', 'string', 'in:email,whatsapp'],
            'destino' => ['required', 'string', 'max:190'],
            'mensagem' => ['nullable', 'string', 'max:2000'],
        ], [
            'destino.required' => 'Informe para onde enviar a nota.',
        ], [
            'canal' => 'canal',
            'destino' => 'destino',
            'mensagem' => 'mensagem',
        ]);

        $envio = $this->documentoFiscalService->enviar(
            $documento,
            (string) $validated['canal'],
            (string) $validated['destino'],
            $validated['mensagem'] ?? null
        );

        return redirect()
            ->route('fiscal.nota', $validated['os_id'])
            ->with('success', sprintf(
                'Nota enviada por %s para %s.',
                $validated['canal'] === 'email' ? 'e-mail' : 'WhatsApp',
                (string) ($envio['destino'] ?? $validated['destino'])
            ));
    }

    public function importarXml(Request $request, int $documento): RedirectResponse
    {
        $this->recusarUploadQuebrado($request, 'arquivo_xml');

        // Mensagens proprias: "o campo arquivo XML e obrigatorio" nao diz ONDE
        // nem O QUE — o operador fica procurando um campo que ele acha que
        // preencheu. Cada mensagem diz o que fazer e onde achar o arquivo.
        $validated = $request->validate([
            'os_id' => ['required', 'integer'],
            'arquivo_xml' => ['required', 'file', 'mimes:xml,txt', 'max:10240'],
        ], [
            'arquivo_xml.required' => 'Escolha o arquivo XML da nota em "Importar o XML da nota" '
                .'antes de clicar em Importar. O XML é baixado no Emissor Nacional, na mesma tela '
                .'onde a NFS-e foi emitida.',
            'arquivo_xml.file' => 'O que foi enviado não chegou como arquivo. Escolha o XML de novo.',
            'arquivo_xml.mimes' => 'O arquivo precisa ser o XML da nota (.xml). O PDF do DANFSe '
                .'se anexa depois, em "Guardar arquivos do portal".',
            'arquivo_xml.max' => 'O XML da nota passa de 10 MB, o que não é esperado — confira se '
                .'o arquivo é mesmo o da nota.',
        ], ['arquivo_xml' => 'arquivo XML']);

        $resultado = $this->documentoFiscalService->importarXml($documento, $request->file('arquivo_xml'));
        $lido = $resultado['lido'] ?? [];

        return redirect()
            ->route('fiscal.nota', $validated['os_id'])
            ->with('success', sprintf(
                'XML importado: NFS-e nº %s, série %s. Confira os dados antes de enviar ao cliente.',
                $lido['numero'] ?? '—',
                $lido['serie'] ?? '—'
            ));
    }

    /**
     * Serve o arquivo guardado para a tela exibir o DANFSe oficial embutido.
     */
    /**
     * Distingue "não escolheu arquivo" de "o upload falhou no servidor".
     *
     * A regra `required` sozinha diz "obrigatório" nos dois casos, e o operador
     * fica olhando para um campo preenchido sendo chamado de vazio. Quando o
     * PHP recebe o arquivo mas não consegue guardá-lo (tmp indisponível, limite
     * do pool, disco cheio), `UploadedFile::isValid()` é falso e o Laravel
     * trata como ausente — daí a mensagem enganosa.
     */
    private function recusarUploadQuebrado(Request $request, string $campo): void
    {
        $arquivo = $request->file($campo);

        if ($arquivo === null || $arquivo->isValid()) {
            return;
        }

        throw ValidationException::withMessages([
            $campo => sprintf(
                'O arquivo chegou ao servidor, mas o envio falhou: %s (código %d). '
                .'Não é problema do arquivo — é configuração do servidor.',
                $arquivo->getErrorMessage(),
                $arquivo->getError()
            ),
        ]);
    }

    public function baixarArquivo(int $documento, string $formato): Response
    {
        $arquivo = $this->documentoFiscalService->baixarArquivo($documento, $formato);

        return response($arquivo['body'], $arquivo['status'])->withHeaders($arquivo['headers']);
    }

    public function danfse(int $documento): Response
    {
        $arquivo = $this->documentoFiscalService->danfse($documento);

        return response($arquivo['body'], $arquivo['status'])->withHeaders($arquivo['headers']);
    }

    public function anexarArquivo(Request $request, int $documento): RedirectResponse
    {
        $this->recusarUploadQuebrado($request, 'arquivo');

        $validated = $request->validate([
            'os_id' => ['required', 'integer'],
            'formato' => ['required', 'string', 'in:xml,pdf'],
            'arquivo' => ['required', 'file', 'max:20480'],
        ], [
            'arquivo.required' => 'Escolha o arquivo em "Guardar arquivos do portal" antes de enviar.',
        ], ['arquivo' => 'arquivo']);

        $this->documentoFiscalService->anexarArquivo(
            $documento,
            $request->file('arquivo'),
            (string) $validated['formato']
        );

        return redirect()
            ->route('fiscal.nota', $validated['os_id'])
            ->with('success', 'Arquivo guardado.');
    }
}
