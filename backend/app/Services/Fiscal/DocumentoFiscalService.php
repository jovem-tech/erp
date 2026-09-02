<?php

namespace App\Services\Fiscal;

use App\Models\Client;
use App\Models\DocumentoFiscal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Documento;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Illuminate\Validation\ValidationException;

/**
 * Modo assistido de emissão (spec 041, fase 042).
 *
 * O ERP não fala com o governo nesta fase. Ele monta o rascunho — tomador,
 * discriminação e valores prontos para colar no Emissor Nacional — e guarda o
 * retorno que o operador traz de volta: número, chave e data.
 *
 * A escolha é deliberada: isso cumpre a obrigação de 01/01/2027 sem depender de
 * certificado digital, de contrato com gateway nem da regra municipal sobre
 * material aplicado em serviço — três coisas que ainda não estão resolvidas. E
 * não é trabalho jogado fora: a integração da `043` reusa exatamente este
 * rascunho, trocando "operador copia" por "sistema envia".
 */
class DocumentoFiscalService
{
    /**
     * Monta (ou recupera) o rascunho de NFS-e de uma OS.
     *
     * Idempotente de propósito: chamar duas vezes devolve o mesmo rascunho em
     * vez de criar um segundo. Dois rascunhos da mesma OS levariam a duas notas
     * para o mesmo serviço, que é o erro caro deste fluxo.
     */
    public function rascunhoDeOrdem(Order $order, ?int $usuarioId = null): DocumentoFiscal
    {
        // Nota JA' EMITIDA vence tudo: e' o documento que existe no fisco.
        // Antes esta busca so' olhava rascunho/rejeitado, entao cada visita a
        // tela depois de emitir criava um rascunho NOVO — a pagina dizia
        // "Rascunho" para uma OS que ja' tinha nota, e reimportar o mesmo XML
        // batia na trava de numero duplicado sem explicar o porque.
        $emitido = DocumentoFiscal::query()
            ->where('os_id', $order->id)
            ->where('tipo', DocumentoFiscal::TIPO_NFSE)
            ->whereIn('status', [DocumentoFiscal::STATUS_EMITIDO, DocumentoFiscal::STATUS_CANCELADO])
            ->orderByDesc('id')
            ->first();

        if ($emitido instanceof DocumentoFiscal) {
            // Devolvido como esta': reescrever tomador ou valores de um
            // documento ja' declarado ao fisco seria falsear o registro.
            return $emitido;
        }

        $existente = DocumentoFiscal::query()
            ->where('os_id', $order->id)
            ->where('tipo', DocumentoFiscal::TIPO_NFSE)
            ->whereIn('status', [DocumentoFiscal::STATUS_RASCUNHO, DocumentoFiscal::STATUS_REJEITADO])
            ->orderByDesc('id')
            ->first();

        $cliente = $order->cliente_id !== null
            ? Client::query()->find($order->cliente_id)
            : null;

        $valores = $this->valoresLiquidos($order);

        $dados = [
            'cliente_id' => $order->cliente_id,
            'tomador_nome' => $cliente?->nome_razao,
            'tomador_documento' => Documento::normalizar((string) ($cliente?->cpf_cnpj ?? '')),
            'discriminacao' => $this->discriminacao($order),
            'valor_servicos' => $valores['servicos'],
            'valor_pecas' => $valores['pecas'],
            // `valor_final` é o que o cliente pagou (já com desconto), e é ele
            // que vai na nota — não `valor_total`, que ignora o ajuste.
            'valor_total' => $valores['total'],
        ];

        if ($existente !== null) {
            $existente->fill($dados)->save();

            return $existente->refresh();
        }

        return DocumentoFiscal::query()->create($dados + [
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_RASCUNHO,
            'os_id' => $order->id,
            'criado_por' => $usuarioId,
        ]);
    }

    /**
     * Abre um documento novo para a OS depois que a nota anterior foi cancelada.
     *
     * `rascunhoDeOrdem()` é idempotente de propósito e devolve sempre o último
     * documento — inclusive o cancelado. Isso protege contra duas notas para o
     * mesmo serviço, mas prendia quem cancelou: a OS voltava para a fila de
     * pendentes e a tela continuava mostrando a nota cancelada, sem caminho para
     * emitir a substituta.
     *
     * A porta é separada, e não um parâmetro do método idempotente, porque abrir
     * documento novo tem de ser um **ato explícito** do operador — é o clique
     * dele que declara "esta nota não vale mais, vou emitir outra".
     *
     * **Só depois de cancelar.** Com nota emitida e válida, o caminho é cancelar
     * primeiro; deixar criar outra ao lado produziria duas notas vivas para o
     * mesmo serviço, que é o erro caro deste fluxo.
     */
    public function novoRascunhoAposCancelamento(Order $order, ?int $usuarioId = null): DocumentoFiscal
    {
        $ultimo = DocumentoFiscal::query()
            ->where('os_id', $order->id)
            ->where('tipo', DocumentoFiscal::TIPO_NFSE)
            ->orderByDesc('id')
            ->first();

        if ($ultimo === null) {
            // Nunca houve documento: o fluxo normal resolve.
            return $this->rascunhoDeOrdem($order, $usuarioId);
        }

        if ((string) $ultimo->status === DocumentoFiscal::STATUS_EMITIDO) {
            throw ValidationException::withMessages([
                'documento' => 'Esta OS já tem nota emitida. Cancele-a antes de emitir outra.',
            ]);
        }

        if ((string) $ultimo->status !== DocumentoFiscal::STATUS_CANCELADO) {
            // Rascunho ou rejeitado: ja' da' para emitir, nao ha o que abrir.
            return $ultimo;
        }

        $cliente = $order->cliente_id !== null
            ? Client::query()->find($order->cliente_id)
            : null;

        $valores = $this->valoresLiquidos($order);

        return DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_RASCUNHO,
            'os_id' => $order->id,
            'criado_por' => $usuarioId,
            'cliente_id' => $order->cliente_id,
            'tomador_nome' => $cliente?->nome_razao,
            'tomador_documento' => Documento::normalizar((string) ($cliente?->cpf_cnpj ?? '')),
            'discriminacao' => $this->discriminacao($order),
            'valor_servicos' => $valores['servicos'],
            'valor_pecas' => $valores['pecas'],
            'valor_total' => $valores['total'],
        ]);
    }

    /**
     * Registra o retorno do portal: o documento passa a existir de verdade.
     *
     * @param  array<string, mixed>  $dados
     */
    public function registrarEmissao(DocumentoFiscal $documento, array $dados, ?int $usuarioId = null): DocumentoFiscal
    {
        if ($documento->foiEmitido()) {
            throw ValidationException::withMessages([
                'numero' => 'Este documento já foi emitido. Cancele-o antes de registrar outro número.',
            ]);
        }

        $numero = trim((string) ($dados['numero'] ?? ''));
        $serie = trim((string) ($dados['serie'] ?? ''));
        $chave = strtoupper(trim((string) ($dados['chave'] ?? '')));

        if ($numero === '') {
            throw ValidationException::withMessages([
                'numero' => 'Informe o número da nota emitida no portal.',
            ]);
        }

        // A conferencia de duplicidade roda DENTRO da transacao. Fora dela,
        // como estava, dois pedidos simultaneos passavam os dois pela busca e o
        // indice unico estourava como 500 — exatamente o que a busca manual
        // existia para evitar. `try/catch` na violacao fecha a janela que
        // sobra: em MySQL o indice tem NULLs distintos, entao serie vazia nao
        // e' protegida pelo banco de qualquer jeito.
        return DB::transaction(function () use ($documento, $numero, $serie, $chave, $dados, $usuarioId): DocumentoFiscal {
            $duplicado = DocumentoFiscal::query()
                ->where('tipo', $documento->tipo)
                ->where('numero', $numero)
                ->where('serie', $serie === '' ? null : $serie)
                ->where('id', '!=', $documento->id)
                ->lockForUpdate()
                ->first();

            if ($duplicado instanceof DocumentoFiscal) {
                throw ValidationException::withMessages([
                    'numero' => sprintf(
                        'A NFS-e nº %s da série %s já está registrada%s. '
                        .'Se o XML é o mesmo, a nota já foi importada — não precisa importar de novo.',
                        $numero,
                        $serie === '' ? '—' : $serie,
                        $duplicado->os_id !== null ? ' na OS #'.$duplicado->os_id : ''
                    ),
                ]);
            }

            $documento->fill([
                'numero' => $numero,
                'serie' => $serie === '' ? null : $serie,
                'chave' => $chave === '' ? null : $chave,
                'status' => DocumentoFiscal::STATUS_EMITIDO,
                'emitido_em' => $dados['emitido_em'] ?? now(),
                'emitido_por' => $usuarioId ?? $documento->emitido_por,
                'motivo_rejeicao' => null,
                'observacoes' => $dados['observacoes'] ?? $documento->observacoes,
            ] + $this->camposDoXml($dados))->save();

            return $documento->refresh();
        });
    }

    /**
     * O que o XML disse, guardado ao lado do que a OS calculou.
     *
     * Estes campos eram LIDOS e jogados fora. Sem eles, um XML emitido no
     * portal com valor diferente do rascunho entrava em silêncio: a tela
     * mostrava o número certo de uma nota com outro valor. Guardar os dois
     * lados é o que permite `DocumentoFiscal::valorDivergeDoXml()` avisar.
     *
     * Só grava o que veio: chave ausente não vira `null` por cima de um valor
     * que já estava lá.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function camposDoXml(array $dados): array
    {
        $campos = [];

        foreach (['numero_dps', 'situacao_codigo', 'competencia', 'valor_xml', 'assinatura_conferida'] as $campo) {
            if (array_key_exists($campo, $dados) && $dados[$campo] !== null && $dados[$campo] !== '') {
                $campos[$campo] = $dados[$campo];
            }
        }

        return $campos;
    }

    public function registrarRejeicao(DocumentoFiscal $documento, string $motivo): DocumentoFiscal
    {
        if ($documento->foiEmitido()) {
            throw ValidationException::withMessages([
                'motivo_rejeicao' => 'Documento já emitido não pode ser marcado como rejeitado.',
            ]);
        }

        $documento->fill([
            'status' => DocumentoFiscal::STATUS_REJEITADO,
            'motivo_rejeicao' => trim($motivo),
        ])->save();

        return $documento->refresh();
    }

    public function cancelar(DocumentoFiscal $documento, string $motivo, ?int $usuarioId = null): DocumentoFiscal
    {
        if ((string) $documento->status !== DocumentoFiscal::STATUS_EMITIDO) {
            throw ValidationException::withMessages([
                'motivo_cancelamento' => 'Só um documento emitido pode ser cancelado.',
            ]);
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo_cancelamento' => 'Informe o motivo do cancelamento.',
            ]);
        }

        // O documento cancelado NAO e' apagado nem tem o numero liberado: ele
        // continua existindo no fisco, e o historico e' o que a fiscalizacao
        // pede. A nota substituta e' um registro novo.
        $documento->fill([
            'status' => DocumentoFiscal::STATUS_CANCELADO,
            'cancelado_em' => now(),
            // Cancelar documento fiscal e' o ato mais grave desta tela e era o
            // unico sem autor registrado.
            'cancelado_por' => $usuarioId ?? $documento->cancelado_por,
            'motivo_cancelamento' => $motivo,
        ])->save();

        return $documento->refresh();
    }

    /**
     * Dados da nota lidos do XML guardado, para montar o DANFSe.
     *
     * Relê o arquivo em vez de guardar uma cópia dos campos: o XML é o
     * documento, e uma cópia paralela poderia divergir dele sem ninguém notar.
     *
     * @return array<string, mixed>
     */
    public function dadosDoXml(DocumentoFiscal $documento, NfseXmlImporter $importador): array
    {
        $caminho = (string) ($documento->xml_arquivo ?? '');

        if ($caminho === '' || ! Storage::disk('local')->exists($caminho)) {
            throw new RuntimeException(
                'Esta nota não tem XML guardado. Importe o XML do portal para gerar o DANFSe.'
            );
        }

        return $importador->ler((string) Storage::disk('local')->get($caminho));
    }

    /**
     * OS que já podem gerar nota e ainda não geraram.
     *
     * O critério é status final **com valor cobrado**: OS finalizada sem valor
     * (descartada, devolvida sem cobrança) não gera nota nenhuma, e listá-la
     * faria o operador caçar pendência que não existe.
     *
     * Documento cancelado não conta como emitido de propósito: cancelou,
     * voltou para a fila.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function ordensPendentesDeNota(int $limite = 100): \Illuminate\Support\Collection
    {
        return DB::table('os')
            ->join('os_status', 'os_status.codigo', '=', 'os.status')
            ->leftJoin('clientes', 'clientes.id', '=', 'os.cliente_id')
            ->leftJoin('documentos_fiscais', function ($join): void {
                $join->on('documentos_fiscais.os_id', '=', 'os.id')
                    ->where('documentos_fiscais.status', '=', DocumentoFiscal::STATUS_EMITIDO);
            })
            ->whereNull('documentos_fiscais.id')
            ->where('os_status.status_final', true)
            ->where('os.valor_final', '>', 0)
            ->orderByDesc('os.data_entrega_efetiva')
            ->limit($limite)
            ->get([
                'os.id',
                'os.numero_os',
                'os.valor_final',
                'os.data_entrega_efetiva',
                // `cliente_id` vai junto para a tela poder LEVAR ao cadastro:
                // apontar a pendencia sem oferecer o caminho de correcao
                // transforma o relatorio em reclamacao.
                'os.cliente_id',
                'clientes.nome_razao as cliente_nome',
                'clientes.cpf_cnpj as cliente_documento',
            ]);
    }

    /**
     * Consulta das notas já registradas — a listagem de "Notas emitidas".
     *
     * Separada de `ordensPendentesDeNota()` porque as duas telas olham lados
     * opostos do mesmo fluxo: lá o eixo é a OS **sem** nota, aqui é o
     * documento. Quem procura uma nota emitida procura por número, chave ou
     * cliente — não pela OS.
     *
     * O `leftJoin` com `os` traz o número da OS junto: sem ele a listagem
     * mostraria `os_id`, que é chave interna e não bate com nada que o operador
     * vê na tela ou no papel.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function consultar(array $filtros = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = DocumentoFiscal::query()
            ->leftJoin('os', 'os.id', '=', 'documentos_fiscais.os_id')
            ->select('documentos_fiscais.*', 'os.numero_os');

        $status = $this->statusFiltrados($filtros['status'] ?? null);

        if ($status !== []) {
            $query->whereIn('documentos_fiscais.status', $status);
        }

        if (($filtros['tipo'] ?? '') !== '') {
            $query->where('documentos_fiscais.tipo', (string) $filtros['tipo']);
        }

        if (($filtros['os_id'] ?? null) !== null) {
            $query->where('documentos_fiscais.os_id', (int) $filtros['os_id']);
        }

        // Período sobre `emitido_em`, e não sobre `created_at`: a data que
        // importa numa nota é a da emissão, que é a que o contador cobra e a
        // que aparece no DANFSe. Rascunho não tem essa data, então filtrar por
        // período o exclui — o que é o comportamento certo para esta tela.
        if (($filtros['de'] ?? '') !== '') {
            $query->whereDate('documentos_fiscais.emitido_em', '>=', (string) $filtros['de']);
        }

        if (($filtros['ate'] ?? '') !== '') {
            $query->whereDate('documentos_fiscais.emitido_em', '<=', (string) $filtros['ate']);
        }

        $busca = trim((string) ($filtros['busca'] ?? ''));

        if ($busca !== '') {
            // O documento do tomador é guardado normalizado, então buscar
            // "111.444.777-35" com a pontuação não acharia nada. A chave tem o
            // mesmo problema quando colada com espaços do PDF.
            $normalizado = Documento::normalizar($busca);

            $query->where(function ($sub) use ($busca, $normalizado): void {
                $sub->where('documentos_fiscais.numero', 'like', '%'.$busca.'%')
                    ->orWhere('documentos_fiscais.tomador_nome', 'like', '%'.$busca.'%')
                    ->orWhere('os.numero_os', 'like', '%'.$busca.'%');

                if ($normalizado !== '') {
                    $sub->orWhere('documentos_fiscais.chave', 'like', '%'.$normalizado.'%')
                        ->orWhere('documentos_fiscais.tomador_documento', 'like', '%'.$normalizado.'%');
                }
            });
        }

        // Emitida mais recente primeiro; rascunho (sem data) cai no fim, que é
        // onde ele interessa menos nesta tela.
        return $query
            ->orderByDesc('documentos_fiscais.emitido_em')
            ->orderByDesc('documentos_fiscais.id');
    }

    /**
     * Contagem e valor do que o filtro selecionou.
     *
     * **A soma conta só o que está `emitido`.** Cancelada e rejeitada aparecem
     * na lista de propósito — o histórico é parte da guarda —, mas somá-las
     * inventaria receita: nota cancelada não valeu nada, e rascunho não chegou
     * a existir. O valor sai de `valor_xml` quando ele existe, porque o que
     * vale é o que foi declarado, não o que o ERP calculou.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{quantidade: int, emitidas: int, valor: float}
     */
    public function totaisDaConsulta(array $filtros = []): array
    {
        $emitidas = $this->consultar(array_merge($filtros, [
            'status' => [DocumentoFiscal::STATUS_EMITIDO],
        ]));

        return [
            'quantidade' => $this->consultar($filtros)->toBase()->count(),
            'emitidas' => (clone $emitidas)->toBase()->count(),
            'valor' => (float) $emitidas->toBase()->sum(
                DB::raw('COALESCE(documentos_fiscais.valor_xml, documentos_fiscais.valor_total)')
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function statusFiltrados(mixed $status): array
    {
        $lista = is_array($status)
            ? $status
            : array_filter(array_map('trim', explode(',', (string) $status)));

        // Status desconhecido não vira `whereIn` vazio (que devolveria nada e
        // pareceria "não há notas"): é descartado, e o filtro some.
        return array_values(array_intersect(
            array_map('strval', $lista),
            DocumentoFiscal::statuses()
        ));
    }

    /**
     * Registra a emissão a partir do XML baixado do portal.
     *
     * Substitui a redigitação: número, série, chave e data saem do arquivo, e o
     * próprio XML fica guardado no mesmo ato — que é a guarda que a lei pede.
     *
     * @return array{documento: DocumentoFiscal, lido: array<string, mixed>}
     */
    public function registrarPorXml(
        DocumentoFiscal $documento,
        UploadedFile $arquivo,
        NfseXmlImporter $importador,
        ?int $usuarioId = null
    ): array {
        $lido = $importador->ler((string) file_get_contents($arquivo->getRealPath()));

        $this->conferirTomador($documento, $lido);

        $registro = $this->registrarEmissao($documento, [
            'numero' => (string) ($lido['numero'] ?? ''),
            'serie' => (string) ($lido['serie'] ?? ''),
            'chave' => (string) ($lido['chave'] ?? ''),
            'emitido_em' => isset($lido['emitido_em']) ? Carbon::parse((string) $lido['emitido_em']) : now(),
            'numero_dps' => $lido['numero_dps'] ?? null,
            'situacao_codigo' => $lido['situacao_codigo'] ?? null,
            'competencia' => $lido['competencia'] ?? null,
            'valor_xml' => $lido['valor'] ?? null,
            'assinatura_conferida' => $lido['assinatura_conferida'] ?? null,
        ], $usuarioId);

        // O anexo vem DEPOIS do registro de propósito: `anexarArquivo()` recusa
        // rascunho, e o XML é justamente a prova de que a nota existe.
        $registro = $this->anexarArquivo($registro, $arquivo, 'xml');

        return ['documento' => $registro, 'lido' => $lido];
    }

    /**
     * O tomador do XML tem de ser o mesmo da OS.
     *
     * É a checagem que impede anexar a nota da OS errada — o número e a chave
     * parecem legítimos porque são, só que de outro atendimento. Quando a OS
     * ainda não tem documento do cliente não há o que comparar, e aí passa.
     *
     * @param  array<string, mixed>  $lido
     */
    private function conferirTomador(DocumentoFiscal $documento, array $lido): void
    {
        $naOs = Documento::normalizar((string) $documento->tomador_documento);
        $noXml = Documento::normalizar((string) ($lido['tomador_documento'] ?? ''));

        if ($naOs === null || $noXml === null || $naOs === $noXml) {
            return;
        }

        throw ValidationException::withMessages([
            'arquivo' => sprintf(
                'O tomador desta nota (%s) não é o cliente desta OS (%s). Confira se o XML é o certo.',
                Documento::formatar($noXml),
                Documento::formatar($naOs)
            ),
        ]);
    }

    /**
     * Guarda o XML ou o DANFSe que o operador baixou do portal.
     *
     * Grava em caminho legado (`private/fiscal/...`) e registra caminho, hash e
     * tamanho — mesma forma de `os_documento_arquivos`, e **não** um
     * `managed_file_id`: o Gerenciador Central roda em modo `shadow` com escrita
     * central desligada, então no momento do upload não existe `ManagedFile`
     * para apontar. Ele cataloga depois, pela varredura automática, que é o
     * caminho de todo upload deste sistema.
     *
     * O XML é o que a lei manda guardar por 5 anos; o PDF é o que se manda ao
     * cliente.
     */
    public function anexarArquivo(DocumentoFiscal $documento, UploadedFile $arquivo, string $formato): DocumentoFiscal
    {
        $formato = strtolower(trim($formato));

        if (! in_array($formato, ['xml', 'pdf'], true)) {
            throw ValidationException::withMessages([
                'formato' => 'Formato inválido: aceita apenas XML ou PDF.',
            ]);
        }

        if (! $documento->foiEmitido()) {
            // Rascunho não tem XML: o arquivo vem do portal, e o portal só
            // devolve depois de emitir. Aceitar aqui guardaria arquivo de outro
            // documento sem ninguém perceber.
            throw ValidationException::withMessages([
                'arquivo' => 'Registre a emissão antes de anexar o arquivo do portal.',
            ]);
        }

        $conteudo = (string) file_get_contents($arquivo->getRealPath());

        $identificador = $documento->numero !== null && $documento->numero !== ''
            ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $documento->numero)
            : 'documento';

        // Com OS, o arquivo vai para a arvore de documentos DELA. O root
        // `order_files` do Gerenciador ja' cataloga `private/os_documentos` e
        // ja' vincula a OS, entao XML e PDF aparecem em "Documentos de OS" sem
        // uma linha de codigo de vinculo. `private/fiscal` fica para o que nao
        // tem OS (venda avulsa).
        $caminho = $documento->os_id !== null
            ? sprintf(
                'private/os_documentos/%d/fiscal/%s_%s.%s',
                (int) $documento->os_id,
                $formato,
                $identificador,
                $formato
            )
            : sprintf('private/fiscal/%d/%s_%s.%s', (int) $documento->id, $formato, $identificador, $formato);

        Storage::disk('local')->put($caminho, $conteudo);

        $documento->fill([
            $formato.'_arquivo' => $caminho,
            $formato.'_hash_sha256' => hash('sha256', $conteudo),
            $formato.'_tamanho_bytes' => strlen($conteudo),
        ])->save();

        return $documento->refresh();
    }

    /**
     * Serviço e peça já líquidos de desconto.
     *
     * O defeito que isto corrige: `valor_servicos` recebia `valor_mao_obra`
     * CRU enquanto `valor_total` recebia `valor_final` (já com desconto). Numa
     * OS com desconto, a nota declarava base de serviço maior que o
     * efetivamente cobrado — ISS a maior, e nota que não fecha com o
     * recebimento.
     *
     * O desconto é rateado na proporção de cada parcela porque é assim que ele
     * foi concedido: um abatimento sobre o total, não uma liberalidade sobre a
     * mão de obra. Jogar tudo no serviço distorceria a peça, que ainda vai
     * precisar sair em NF-e própria com o valor certo.
     *
     * O resíduo de arredondamento fica no serviço — a parcela maior (79% do
     * faturamento desta base) —, e a soma fecha em `valor_final` por
     * construção, não por sorte.
     *
     * @return array{servicos: float, pecas: float, total: float}
     */
    private function valoresLiquidos(Order $order): array
    {
        $servicos = (float) ($order->valor_mao_obra ?? 0);
        $pecas = (float) ($order->valor_pecas ?? 0);
        $bruto = $servicos + $pecas;
        $total = (float) ($order->valor_final ?? $order->valor_total ?? 0);

        // Sem parcela para ratear (OS sem itens, ou valor final ausente) não há
        // rateio a fazer: devolver o que veio é melhor que dividir por zero.
        if ($bruto <= 0.0 || $total <= 0.0) {
            return ['servicos' => $servicos, 'pecas' => $pecas, 'total' => $total];
        }

        $pecasLiquido = round($pecas * $total / $bruto, 2);

        return [
            'servicos' => round($total - $pecasLiquido, 2),
            'pecas' => $pecasLiquido,
            'total' => round($total, 2),
        ];
    }

    /**
     * Texto que o operador cola no campo "Discriminação dos serviços".
     *
     * Só serviço entra. Peça é mercadoria: sai por NF-e/NFC-e na SEFAZ
     * estadual, e misturar as duas na NFS-e é justamente o erro que a
     * separação `os_itens.tipo` existe para evitar. A peça aparece à parte,
     * como informação, porque dependendo da regra do município ela pode ser
     * deduzida da base do ISS — e isso ainda precisa da confirmação do contador.
     */
    private function discriminacao(Order $order): string
    {
        $itens = OrderItem::query()
            ->where('os_id', $order->id)
            ->where('tipo', 'servico')
            ->orderBy('id')
            ->get();

        $linhas = [];

        foreach ($itens as $item) {
            $quantidade = (float) ($item->quantidade ?? 1);
            $descricao = trim((string) ($item->descricao ?? ''));

            if ($descricao === '') {
                continue;
            }

            $linhas[] = $quantidade > 1
                ? sprintf('%s (%dx) — %s', $descricao, (int) $quantidade, $this->moeda((float) $item->valor_total))
                : sprintf('%s — %s', $descricao, $this->moeda((float) $item->valor_total));
        }

        $numero = (string) ($order->numero_os ?? $order->id);

        if ($linhas === []) {
            // OS sem item de servico detalhado ainda tem mao de obra somada.
            // Melhor uma linha generica que um campo vazio no portal.
            //
            // Sem repetir o numero: a primeira linha ja' o traz, e
            // `numero_os` ja' vem com o prefixo "OS" — concatenar outro
            // produzia "OS OS26070030".
            $linhas[] = 'Servicos de assistencia tecnica.';
        }

        array_unshift($linhas, sprintf('Ordem de servico %s', $numero));

        return implode("\n", $linhas);
    }

    private function moeda(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
