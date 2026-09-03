@extends('layouts.app')

@section('content')
    @php
        $moeda = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
        $status = $documento['status'] ?? 'rascunho';
        $emitido = in_array($status, ['emitido', 'cancelado'], true);
        $documentoId = (int) ($documento['id'] ?? 0);
        $rotulos = [
            'rascunho' => ['Rascunho', 'secondary'],
            'emitido' => ['Emitido', 'success'],
            'cancelado' => ['Cancelado', 'dark'],
            'rejeitado' => ['Rejeitado', 'danger'],
        ];
        [$rotulo, $cor] = $rotulos[$status] ?? ['Rascunho', 'secondary'];

        $chave = (string) ($documento['chave'] ?? '');
        $temPdf = (bool) ($documento['tem_pdf'] ?? false);
        $temXml = (bool) ($documento['tem_xml'] ?? false);
        // Mesmo endereco do QR Code do DANFSe (NT-008, item 2.4.3): a consulta
        // publica e' a via oficial de verificacao da nota.
        $consultaPublica = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . $chave;
        // O PDF anexado do portal tem precedencia; sem ele, o gerado aqui.
        $urlDanfse = $temPdf
            ? route('fiscal.documentos.arquivo.download', [$documentoId, 'pdf'])
            : route('fiscal.documentos.danfse', $documentoId);
        $contatos = (array) ($documento['contatos'] ?? []);
    @endphp

    <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
            <p class="desktop-eyebrow">Fiscal</p>
            <h2 class="surface-title fs-3 mb-2">
                Emitir nota da OS <span class="badge bg-{{ $cor }} align-middle">{{ $rotulo }}</span>
            </h2>
            <p class="surface-subtitle mb-0">
                O sistema monta os dados; você emite no
                <a href="https://www.nfse.gov.br/EmissorNacional" target="_blank" rel="noreferrer">Emissor Nacional</a>
                e volta aqui para registrar o retorno.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            @if ($status === 'cancelado' && \App\Support\DesktopSession::can('os', 'editar'))
                {{-- So' depois de cancelar: com nota valida, duas notas vivas
                     para o mesmo servico e' o erro caro deste fluxo. --}}
                <form method="POST" action="{{ route('fiscal.documentos.novo', $osId) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-file-earmark-plus me-1"></i>Emitir nova nota
                    </button>
                </form>
            @endif

            @if ($emitido)
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-three-dots me-1"></i>Mais ações
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="{{ $urlDanfse }}" target="_blank" rel="noreferrer">
                                <i class="bi bi-file-earmark-pdf me-2"></i>Baixar DANFSe
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item {{ $temXml ? '' : 'disabled' }}"
                               href="{{ $temXml ? route('fiscal.documentos.arquivo.download', [$documentoId, 'xml']) : '#' }}">
                                <i class="bi bi-filetype-xml me-2"></i>Baixar XML
                                @unless ($temXml)
                                    <span class="small text-muted d-block ms-4">nenhum XML guardado</span>
                                @endunless
                            </a>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item" data-imprimir-danfse
                                    data-url="{{ $urlDanfse }}">
                                <i class="bi bi-printer me-2"></i>Imprimir DANFSe
                            </button>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button type="button" class="dropdown-item {{ $chave === '' ? 'disabled' : '' }}"
                                    data-copiar-chave data-chave="{{ $chave }}">
                                <i class="bi bi-clipboard me-2"></i>Copiar chave de acesso
                            </button>
                        </li>
                        <li>
                            <a class="dropdown-item {{ $chave === '' ? 'disabled' : '' }}"
                               href="{{ $chave === '' ? '#' : $consultaPublica }}" target="_blank" rel="noreferrer">
                                <i class="bi bi-search me-2"></i>Consultar no portal nacional
                            </a>
                        </li>
                    </ul>
                </div>
            @endif

            <a href="{{ route('fiscal.pendentes') }}" class="btn btn-outline-primary">Voltar aos pendentes</a>
        </div>
    </div>

    <div class="surface-card p-3 mb-3">
        <h3 class="surface-title fs-6 mb-2">Como emitir, passo a passo</h3>
        <ol class="mb-0 ps-3 surface-subtitle small">
            <li>Confira o tomador e o valor abaixo. Copie a <strong>discriminação</strong>.</li>
            <li>Abra o <a href="https://www.nfse.gov.br/EmissorNacional" target="_blank" rel="noreferrer">Emissor Nacional</a>
                e entre com sua conta gov.br.</li>
            <li>Emita a NFS-e colando os dados. Ao final o portal gera o <strong>DANFSe</strong> (PDF) e o XML.</li>
            <li>Volte aqui e preencha os campos ao lado com o que está no DANFSe. Depois anexe o XML e o PDF.</li>
        </ol>
    </div>

    @if (($documento['tomador_documento'] ?? '') === '')
        <div class="alert alert-danger d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>
                <strong>Este cliente não tem CPF/CNPJ cadastrado.</strong> A NFS-e exige identificar o
                tomador — preencha o cadastro antes de emitir.
            </span>
            @if (($documento['cliente_id'] ?? null) && \App\Support\DesktopSession::can('clientes', 'editar'))
                {{-- O aviso leva ao conserto. Sem isto o operador teria de sair
                     daqui e caçar o cliente na listagem. --}}
                <button type="button" class="btn btn-sm btn-danger text-nowrap"
                        data-editar-cliente
                        data-show-url="{{ route('clients.quick.show', $documento['cliente_id']) }}"
                        data-update-url="{{ route('clients.quick.update', $documento['cliente_id']) }}"
                        data-full-url="{{ route('clients.edit', $documento['cliente_id']) }}">
                    <i class="bi bi-pencil me-1"></i>Completar cadastro
                </button>
            @endif
        </div>
    @endif

    {{-- Duas colunas com papeis distintos: a da esquerda e' onde se OPERA a
         nota (dados para copiar, registro, guarda de arquivos, cancelamento) e
         a da direita e' o VISOR do documento. Enquanto a nota nao foi emitida
         nao ha documento para ver, entao a direita recebe os formularios de
         registro e a esquerda fica mais larga — dai as larguras dependerem de
         `$emitido`. --}}
    <div class="row g-3">
        <div class="col-12 col-lg-{{ $emitido ? '5' : '7' }}">
            <div class="surface-card p-3 mb-3">
                <h3 class="surface-title fs-5 mb-3">Dados para o portal</h3>
                <dl class="row mb-3">
                    <dt class="col-sm-4 surface-subtitle">Tomador</dt>
                    <dd class="col-sm-8">{{ $documento['tomador_nome'] ?? '—' }}</dd>
                    <dt class="col-sm-4 surface-subtitle">CPF/CNPJ</dt>
                    <dd class="col-sm-8">
                        {{ $documento['tomador_documento'] ?: '—' }}
                        @if (($documento['cliente_id'] ?? null) && \App\Support\DesktopSession::can('clientes', 'editar'))
                            <button type="button" class="btn btn-link btn-sm p-0 ms-2 align-baseline"
                                    data-editar-cliente
                                    data-show-url="{{ route('clients.quick.show', $documento['cliente_id']) }}"
                                    data-update-url="{{ route('clients.quick.update', $documento['cliente_id']) }}"
                                    data-full-url="{{ route('clients.edit', $documento['cliente_id']) }}">editar</button>
                        @endif
                    </dd>
                    <dt class="col-sm-4 surface-subtitle">Serviço</dt>
                    <dd class="col-sm-8">{{ $moeda($documento['valor_servicos'] ?? 0) }}</dd>
                    <dt class="col-sm-4 surface-subtitle">Peça</dt>
                    <dd class="col-sm-8">
                        {{ $moeda($documento['valor_pecas'] ?? 0) }}
                        <span class="surface-subtitle small d-block">Não entra na NFS-e: peça é mercadoria.</span>
                    </dd>
                </dl>

                <label for="discriminacao" class="form-label fw-semibold">Discriminação dos serviços</label>
                <textarea id="discriminacao" class="form-control mb-2" rows="5" readonly>{{ $documento['discriminacao'] ?? '' }}</textarea>
                <button type="button" class="btn btn-soft btn-sm" data-copiar-discriminacao>
                    <i class="bi bi-clipboard me-1"></i>Copiar discriminação
                </button>
            </div>

            @if ($emitido)
                {{-- Envio ao cliente. Vai aqui, logo abaixo dos dados da nota,
                     porque e' o passo seguinte natural de quem acabou de
                     registrar a emissao. --}}
                @php
                    // Mesma preferencia do Centro de Documentos da OS: WhatsApp
                    // quando ha telefone, senao e-mail.
                    $canalPadrao = ($contatos['whatsapp'] ?? '') !== '' ? 'whatsapp' : 'email';
                @endphp
                <div class="surface-card p-3 mb-3">
                    <h3 class="surface-title fs-6 mb-2">Enviar a nota ao cliente</h3>
                    @php
                        // Pessoa juridica recebe o XML junto; pessoa fisica, nao —
                        // sem contador para quem repassar, o arquivo so' atrapalha.
                        $tomadorPj = strlen((string) preg_replace('/\D/', '', (string) ($documento['tomador_documento'] ?? ''))) === 14;
                    @endphp
                    <p class="surface-subtitle small mb-3">
                        @if ($tomadorPj)
                            Vão anexados o <strong>DANFSe</strong>, que é a via de leitura, e o
                            <strong>XML</strong>, que é o documento fiscal de que o contador do
                            cliente precisa.
                        @else
                            Vai anexado o <strong>DANFSe</strong>, que é a via de leitura. O XML
                            só acompanha nota de cliente com CNPJ, que tem contador para recebê-lo.
                        @endif
                    </p>

                    <form method="POST" action="{{ route('fiscal.documentos.envio', $documentoId) }}"
                          data-envio-nota>
                        @csrf
                        <input type="hidden" name="os_id" value="{{ $osId }}">

                        <div class="btn-group w-100 mb-2" role="group" aria-label="Canal de envio">
                            @foreach (['whatsapp' => ['WhatsApp', 'bi-whatsapp'], 'email' => ['E-mail', 'bi-envelope']] as $canal => [$legenda, $icone])
                                <input type="radio" class="btn-check" name="canal" id="canal-{{ $canal }}"
                                       value="{{ $canal }}" autocomplete="off" data-canal
                                       data-contato="{{ $contatos[$canal] ?? '' }}"
                                       @checked($canalPadrao === $canal)>
                                <label class="btn btn-outline-secondary btn-sm" for="canal-{{ $canal }}">
                                    <i class="bi {{ $icone }} me-1"></i>{{ $legenda }}
                                </label>
                            @endforeach
                        </div>

                        <label for="destino-envio" class="form-label small fw-semibold mb-1">Enviar para</label>
                        <input type="text" id="destino-envio" name="destino" data-destino
                               class="form-control form-control-sm mb-1"
                               value="{{ old('destino', $contatos[$canalPadrao] ?? '') }}"
                               placeholder="{{ $canalPadrao === 'email' ? 'cliente@exemplo.com' : '(22) 99999-8888' }}"
                               required>
                        <p class="surface-subtitle small mb-2">
                            @if (($contatos['whatsapp'] ?? '') === '' && ($contatos['email'] ?? '') === '')
                                Este cliente não tem e-mail nem telefone no cadastro — digite o destino.
                            @else
                                Vem do cadastro do cliente. Dá para trocar por outro só neste envio.
                            @endif
                        </p>

                        <label for="mensagem-envio" class="form-label small fw-semibold mb-1">
                            Mensagem <span class="fw-normal text-muted">(opcional)</span>
                        </label>
                        <textarea id="mensagem-envio" name="mensagem" rows="2"
                                  class="form-control form-control-sm mb-2"
                                  placeholder="Deixe em branco para usar a mensagem padrão.">{{ old('mensagem') }}</textarea>

                        <button type="submit" class="btn btn-soft btn-sm w-100">
                            <i class="bi bi-send me-1"></i>Enviar nota
                        </button>
                    </form>
                </div>

                <div class="surface-card p-3 mb-3">
                    <h3 class="surface-title fs-5 mb-3">Nota registrada</h3>
                    <dl class="row mb-0">
                        <dt class="col-5 surface-subtitle">Número</dt>
                        <dd class="col-7">{{ $documento['numero'] ?: '—' }}</dd>
                        <dt class="col-5 surface-subtitle">Série</dt>
                        <dd class="col-7">{{ $documento['serie'] ?: '—' }}</dd>
                        <dt class="col-5 surface-subtitle">Chave</dt>
                        <dd class="col-7 text-break">{{ $documento['chave'] ?: '—' }}</dd>
                    </dl>
                    @if ($status === 'cancelado')
                        <div class="alert alert-dark mt-3 mb-0">
                            Cancelada: {{ $documento['motivo_cancelamento'] ?: '—' }}.
                            O número continua registrado — a substituta é uma nota nova.
                        </div>
                    @endif
                </div>

                <div class="surface-card p-3 mb-3">
                    <h3 class="surface-title fs-6 mb-2">Guardar arquivos do portal</h3>
                    <p class="surface-subtitle small">
                        O XML é o que a lei manda guardar por 5 anos. O PDF é o que se manda ao cliente.
                    </p>
                    @foreach (['xml' => 'XML', 'pdf' => 'PDF (DANFSe)'] as $formato => $legenda)
                        @php $jaGuardado = $documento['tem_' . $formato] ?? false; @endphp
                        <form method="POST" action="{{ route('fiscal.documentos.arquivo', $documentoId) }}"
                              enctype="multipart/form-data" class="mb-2" data-no-page-loader="true"
                              data-form-arquivo-fiscal>
                            @csrf
                            <input type="hidden" name="os_id" value="{{ $osId }}">
                            <input type="hidden" name="formato" value="{{ $formato }}">

                            @if ($jaGuardado)
                                <span class="small text-success d-block mb-1">
                                    <i class="bi bi-check-circle me-1"></i>{{ $legenda }} já guardado —
                                    escolha um arquivo abaixo só para substituir.
                                </span>
                            @endif

                            <div class="d-flex gap-2 align-items-center">
                                <input type="file" name="arquivo" class="form-control form-control-sm"
                                       accept=".{{ $formato }}" required data-input-arquivo-fiscal>
                                {{-- Comeca desabilitado: so' vira uma acao clicavel depois que o
                                     operador escolhe o arquivo. Antes disto, o unico jeito de saber
                                     que "XML" era o botao de confirmar era o proprio rotulo do campo —
                                     nada aqui dizia "clique para enviar". --}}
                                <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap" disabled
                                        data-botao-arquivo-fiscal>
                                    <i class="bi bi-upload me-1"></i>Enviar {{ $formato === 'xml' ? 'XML' : 'PDF' }}
                                </button>
                            </div>
                        </form>
                    @endforeach
                </div>

                @if ($status === 'emitido')
                    <div class="surface-card p-3">
                        <h3 class="surface-title fs-6 mb-2">Cancelar a nota</h3>
                        <form method="POST" action="{{ route('fiscal.documentos.cancelamento', $documentoId) }}">
                            @csrf
                            <input type="hidden" name="os_id" value="{{ $osId }}">
                            <input type="text" name="motivo_cancelamento" class="form-control mb-2" placeholder="Motivo do cancelamento" required>
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">Cancelar documento</button>
                        </form>
                    </div>
                @endif
            @endif

            @if (($documento['motivo_rejeicao'] ?? '') !== '')
                <div class="alert alert-danger mt-3">
                    Rejeitada pelo portal: {{ $documento['motivo_rejeicao'] }}
                </div>
            @endif
        </div>

        {{-- Fora dos cards de proposito: ver o comentario no bloco de importacao. --}}
        {{-- `data-no-page-loader` tira este envio da interceptacao global do
             `desktop.js`, que cancela o submit nativo e reenvia com
             `form.submit()`. Para upload isso e' arriscado: `form.submit()`
             pula a validacao nativa do navegador, entao um clique sem arquivo
             escolhido vira POST vazio e o erro so' aparece no servidor. --}}
        <form method="POST" action="{{ route('fiscal.documentos.importar-xml', $documentoId) }}"
              id="importarXmlForm" enctype="multipart/form-data" class="d-none" data-no-page-loader="true">
            @csrf
            <input type="hidden" name="os_id" value="{{ $osId }}">
        </form>


        <div class="col-12 col-lg-{{ $emitido ? '7' : '5' }}">
            @if (! $emitido)
                <div class="surface-card p-3 mb-3">
                    <h3 class="surface-title fs-5 mb-2">Importar o XML da nota</h3>
                    <p class="surface-subtitle small mb-3">
                        O caminho rápido: baixe o XML no portal e envie aqui. Número, série,
                        chave e data saem do arquivo, e ele já fica guardado — que é a guarda
                        que a lei pede. Digitar à mão continua abaixo.
                    </p>
                    {{-- Os controles apontam para um <form> declarado FORA dos
                         cards, pelo atributo `form=` — mesmo padrao dos
                         formularios do Google Agenda e do certificado A1.
                         Assim o vinculo controle-formulario e' explicito e nao
                         depende de o parser HTML aninhar do jeito esperado:
                         um <form> aberto antes no documento faria o navegador
                         descartar este e jogar os inputs no formulario errado
                         — que e' exatamente o sintoma observado (clicar em
                         Importar disparava a validacao do campo Numero). --}}
                    <div class="d-flex gap-2">
                        <input type="file" name="arquivo_xml" form="importarXmlForm"
                               class="form-control form-control-sm @error('arquivo_xml') is-invalid @enderror"
                               accept=".xml,text/xml,application/xml" required>
                        <button type="submit" form="importarXmlForm" class="btn btn-primary btn-sm text-nowrap">
                            <i class="bi bi-filetype-xml me-1"></i>Importar
                        </button>
                    </div>

                    @error('arquivo_xml')
                        {{-- Ancorado aqui de proposito: o banner do topo diz que
                             ha pendencia, mas nao diz em qual dos tres blocos
                             desta tela. --}}
                        <div class="alert alert-danger mt-2 mb-0 py-2 small">{{ $message }}</div>
                    @enderror
                </div>

                <div class="surface-card p-3 mb-3">
                    <h3 class="surface-title fs-5 mb-3">Ou registrar à mão</h3>
                    <form method="POST" action="{{ route('fiscal.documentos.emissao', $documentoId) }}">
                        @csrf
                        <input type="hidden" name="os_id" value="{{ $osId }}">
                        <p class="surface-subtitle small mb-3">
                            Os três valores estão no alto do DANFSe. Cuidado: o documento traz
                            <em>dois</em> números — o da NFS-e e o da DPS. Aqui vai o
                            <strong>da NFS-e</strong>.
                        </p>

                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label for="numero" class="form-label">Número da NFS-e *</label>
                                <input type="text" id="numero" name="numero" class="form-control @error('numero') is-invalid @enderror"
                                       value="{{ old('numero') }}" placeholder="ex.: 2" required>
                                <div class="form-text">No DANFSe: <strong>NÚMERO DA NFS-e</strong>. Não é o "número da DPS".</div>
                                @error('numero')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-5">
                                <label for="serie" class="form-label">Série da DPS</label>
                                <input type="text" id="serie" name="serie" class="form-control"
                                       value="{{ old('serie') }}" placeholder="ex.: 70000">
                                <div class="form-text">Opcional. No DANFSe: <strong>SÉRIE DA DPS</strong>.</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="chave" class="form-label">Chave de acesso</label>
                            <input type="text" id="chave" name="chave" class="form-control"
                                   value="{{ old('chave') }}" placeholder="50 dígitos" maxlength="60">
                            <div class="form-text">
                                No DANFSe: <strong>CHAVE DE ACESSO DA NFS-e</strong>. É o número longo do topo,
                                o mesmo do QR Code — serve para consultar a nota no portal nacional depois.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Registrar emissão</button>
                    </form>
                </div>

                <div class="surface-card p-3">
                    <h3 class="surface-title fs-6 mb-2">O portal recusou?</h3>
                    <form method="POST" action="{{ route('fiscal.documentos.rejeicao', $documentoId) }}">
                        @csrf
                        <input type="hidden" name="os_id" value="{{ $osId }}">
                        <input type="text" name="motivo_rejeicao" class="form-control mb-2" placeholder="Motivo informado pelo portal" required>
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">Registrar rejeição</button>
                    </form>
                </div>
            @else
                {{-- Os tres casos sao excludentes: ou o operador anexou o PDF do
                     portal, ou o sistema desenha o DANFSe a partir do XML, ou
                     ainda nao ha arquivo nenhum de onde tirar o documento. --}}
                @if ($documento['tem_pdf'] ?? false)
                    {{-- Quando o operador anexou o PDF do portal, e' ele que se
                         mostra: nao por valer mais que o gerado aqui, mas por
                         ser o arquivo que ele ja' tem em maos. --}}
                    <div class="surface-card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="surface-title fs-6 mb-0">DANFSe (baixado do portal)</h3>
                            <a href="{{ route('fiscal.documentos.arquivo.download', [$documentoId, 'pdf']) }}"
                               target="_blank" rel="noreferrer" class="btn btn-soft btn-sm">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Abrir
                            </a>
                        </div>
                        <iframe src="{{ route('fiscal.documentos.arquivo.download', [$documentoId, 'pdf']) }}"
                                title="DANFSe da NFS-e" style="width:100%;aspect-ratio:1/1.414;min-height:520px;border:0;" loading="lazy"></iframe>
                    </div>
                @elseif ($documento['tem_xml'] ?? false)
                    {{-- Sem o PDF do portal, mas com XML: o sistema gera o DANFSe
                         ele mesmo, conforme a Nota Tecnica no 008 — que e'
                         justamente a norma que passa essa geracao para os ERPs
                         (a API publica de DANFSe foi sobrestada em 01/07/2026).
                         Nao e' um sucedaneo do documento: e' o documento. --}}
                    <div class="surface-card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="surface-title fs-6 mb-0">DANFSe (gerado do XML)</h3>
                            <a href="{{ route('fiscal.documentos.danfse', $documentoId) }}"
                               target="_blank" rel="noreferrer" class="btn btn-soft btn-sm">
                                <i class="bi bi-download me-1"></i>Baixar
                            </a>
                        </div>
                        <p class="surface-subtitle small mb-2">
                            Gerado pelo sistema a partir do XML assinado, no layout da NT-008.
                            Se você preferir usar o arquivo que baixou do portal, anexe-o abaixo
                            — aí é ele que aparece aqui.
                        </p>
                        <iframe src="{{ route('fiscal.documentos.danfse', $documentoId) }}"
                                title="DANFSe gerado do XML" style="width:100%;aspect-ratio:1/1.414;min-height:520px;border:0;" loading="lazy"></iframe>
                    </div>
                @else
                    <div class="surface-card p-3 mb-3">
                        <h3 class="surface-title fs-6 mb-2">DANFSe</h3>
                        <p class="surface-subtitle small mb-0">
                            Ainda não há arquivo desta nota. Anexe o XML ao lado e o
                            sistema desenha o DANFSe a partir dele; se preferir, anexe o
                            PDF que você baixou do portal.
                        </p>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endsection

@if (($documento['cliente_id'] ?? null) && \App\Support\DesktopSession::can('clientes', 'editar'))
    @include('clients.quick-edit-modal')
@endif

@section('scripts')
    <script src="{{ asset('assets/js/cliente-quick-edit.js') }}?v={{ filemtime(public_path('assets/js/cliente-quick-edit.js')) }}"></script>
<script>
    document.querySelector('[data-copiar-discriminacao]')?.addEventListener('click', function () {
        const campo = document.getElementById('discriminacao');
        campo.select();
        // `navigator.clipboard` exige contexto seguro; `execCommand` cobre o
        // acesso por http em rede local, que e' como o desktop roda na oficina.
        try {
            navigator.clipboard.writeText(campo.value);
        } catch (erro) {
            document.execCommand('copy');
        }
        this.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado';
    });

    // Copia texto sem depender de contexto seguro: o desktop roda por http na
    // rede da oficina, e ali `navigator.clipboard` nao existe.
    function copiarTexto(texto) {
        try {
            navigator.clipboard.writeText(texto);
            return;
        } catch (erro) {
            const campo = document.createElement('textarea');
            campo.value = texto;
            campo.setAttribute('readonly', '');
            campo.style.position = 'fixed';
            campo.style.opacity = '0';
            document.body.appendChild(campo);
            campo.select();
            document.execCommand('copy');
            document.body.removeChild(campo);
        }
    }

    document.querySelector('[data-copiar-chave]')?.addEventListener('click', function () {
        copiarTexto(this.dataset.chave || '');
        const original = this.innerHTML;
        this.innerHTML = '<i class="bi bi-check2 me-2"></i>Chave copiada';
        setTimeout(() => { this.innerHTML = original; }, 2000);
    });

    document.querySelector('[data-imprimir-danfse]')?.addEventListener('click', function () {
        // Quando o DANFSe ja' esta' na tela, imprime o proprio iframe: abrir
        // outra janela so' para o navegador rebaixar o mesmo PDF e' desperdicio,
        // e bloqueador de pop-up derruba o caminho da janela nova.
        const visor = document.querySelector('iframe[title^="DANFSe"]');

        if (visor && visor.contentWindow) {
            try {
                visor.contentWindow.focus();
                visor.contentWindow.print();
                return;
            } catch (erro) {
                // PDF ainda carregando ou visor sem suporte: cai para a janela.
            }
        }

        window.open(this.dataset.url, '_blank', 'noreferrer');
    });

    // O botao de anexar XML/PDF so' vira acao clicavel depois que um arquivo
    // e' escolhido — antes disto o unico sinal de que "XML" era o botao de
    // confirmar era o proprio rotulo do campo de arquivo.
    document.querySelectorAll('[data-form-arquivo-fiscal]').forEach((form) => {
        const campo = form.querySelector('[data-input-arquivo-fiscal]');
        const botao = form.querySelector('[data-botao-arquivo-fiscal]');

        campo?.addEventListener('change', function () {
            botao.disabled = this.files.length === 0;
        });
    });

    // O destino acompanha o canal escolhido — mas so' enquanto o operador nao
    // digitou nada proprio: sobrescrever o que ele acabou de escrever, porque
    // ele clicou no outro canal para conferir, e' perder o que ele digitou.
    (function () {
        const destino = document.querySelector('[data-destino]');

        if (! destino) {
            return;
        }

        let editadoAMao = false;
        destino.addEventListener('input', () => { editadoAMao = true; });

        document.querySelectorAll('[data-canal]').forEach((opcao) => {
            opcao.addEventListener('change', function () {
                if (! this.checked || editadoAMao) {
                    return;
                }

                destino.value = this.dataset.contato || '';
                destino.placeholder = this.value === 'email'
                    ? 'cliente@exemplo.com'
                    : '(22) 99999-8888';
            });
        });
    })();
</script>
@endsection
