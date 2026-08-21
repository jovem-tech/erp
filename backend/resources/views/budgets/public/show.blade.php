@php
    $budget = is_array($budget ?? null) ? $budget : [];
    $flashSuccess = session('success');
    $flashWarning = session('warning');
    $formatMoney = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
    $statusClass = match ((string) ($budget['status'] ?? '')) {
        'aprovado', 'pendente_abertura_os' => 'status-approved',
        'rejeitado' => 'status-rejected',
        default => 'status-pending',
    };
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orçamento {{ $budget['numero'] ?? '' }}</title>
    @include('partials.favicon')
    <style>
        :root {
            color-scheme: light;
            --bg: #eef4ff;
            --card: rgba(255,255,255,.96);
            --border: rgba(56, 104, 176, 0.14);
            --text: #12233f;
            --muted: #5c6f8d;
            --primary: #3868b0;
            --primary-soft: rgba(56, 104, 176, 0.12);
            --success: #15803d;
            --success-soft: rgba(21, 128, 61, 0.12);
            --danger: #dc2626;
            --danger-soft: rgba(220, 38, 38, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(111, 90, 252, 0.12), transparent 34%),
                linear-gradient(180deg, #f7fbff 0%, var(--bg) 100%);
            color: var(--text);
        }
        .shell {
            width: min(1080px, calc(100% - 32px));
            margin: 24px auto 40px;
        }
        .hero,
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 22px 44px rgba(15, 23, 42, 0.08);
        }
        .hero {
            padding: 24px;
            margin-bottom: 18px;
        }
        .eyebrow {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .hero-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
        }
        .title {
            margin: 0;
            font-size: clamp(28px, 4vw, 40px);
            line-height: 1.05;
        }
        .subtitle {
            margin: 10px 0 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .status-pending { background: var(--primary-soft); color: var(--primary); }
        .status-approved { background: var(--success-soft); color: var(--success); }
        .status-rejected { background: var(--danger-soft); color: var(--danger); }
        .flash {
            padding: 14px 16px;
            border-radius: 16px;
            margin-top: 16px;
            font-weight: 600;
        }
        .flash.success { background: var(--success-soft); color: var(--success); }
        .flash.warning { background: rgba(245, 158, 11, 0.14); color: #9a6700; }
        .grid {
            display: grid;
            gap: 18px;
            grid-template-columns: 1.2fr .8fr;
        }
        .card {
            padding: 22px;
        }
        .meta-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin-top: 18px;
        }
        .meta-item {
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(248, 250, 252, 0.9);
            border: 1px solid rgba(148, 163, 184, 0.16);
        }
        .meta-label {
            display: block;
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .meta-value {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.4;
        }
        .section-title {
            margin: 0 0 12px;
            font-size: 20px;
        }
        .items {
            display: grid;
            gap: 12px;
        }
        .item {
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 18px;
            padding: 14px 16px;
            background: rgba(255,255,255,.9);
        }
        .item-top,
        .money-row,
        .action-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
        }
        .item-top strong { font-size: 16px; }
        .item-top span,
        .item-notes,
        .helper {
            color: var(--muted);
            line-height: 1.5;
        }
        .money-row {
            margin-top: 10px;
            font-weight: 700;
        }
        .totals {
            display: grid;
            gap: 12px;
        }
        .total-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.16);
            background: rgba(248, 250, 252, 0.92);
        }
        .total-box strong { font-size: 16px; }
        .total-box.grand {
            background: linear-gradient(135deg, rgba(111, 90, 252, 0.14), rgba(56, 104, 176, 0.12));
            border-color: rgba(111, 90, 252, 0.22);
        }
        .total-box.grand strong:last-child { font-size: 22px; }
        .decision-box {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
        }
        /* Decidir vem primeiro: aprovar e rejeitar na mesma linha, com o campo
           de motivo logo abaixo. Baixar o PDF e' acao secundaria e fecha o
           bloco, separada por um respiro. */
        .decision-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }
        .decision-actions .btn { flex: 1 1 auto; }
        .pdf-row { margin-top: 22px; }
        .danger-text { color: var(--danger); margin-bottom: 0; }
        /* Condicoes comerciais: o cliente precisa achar em 2 segundos como
           paga e por quanto tempo tem garantia. Cartoes curtos em vez de
           paragrafo corrido. */
        .terms-heading {
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
        }
        .terms-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .term-card {
            padding: 14px 16px;
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 18px;
            background: rgba(248, 250, 252, 0.9);
        }
        .term-card-wide { margin-top: 12px; }
        .term-highlight {
            font-size: 20px;
            font-weight: 800;
            line-height: 1.3;
            color: var(--primary);
        }
        .term-note {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
        }
        /* A chave e' o dado que o cliente copia: fica grande, monoespacada e
           quebra sem estourar o cartao no celular. */
        .pix-box {
            margin-top: 12px;
            padding: 16px;
            border: 1px solid rgba(21, 128, 61, 0.22);
            border-radius: 18px;
            background: var(--success-soft);
        }
        .pix-key {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 10px;
            margin-top: 4px;
        }
        .pix-tipo {
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.75);
            color: var(--success);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .pix-valor {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            word-break: break-all;
            -webkit-user-select: all;
            user-select: all;
        }
        .pix-titular {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
        }
        .btn-copy {
            flex-shrink: 0;
            padding: 7px 14px;
            border: 1px solid rgba(21, 128, 61, 0.32);
            border-radius: 999px;
            background: #fff;
            color: var(--success);
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.2;
            cursor: pointer;
        }
        .btn-copy:hover { background: rgba(21, 128, 61, 0.08); }
        .btn-copy.is-done {
            border-color: var(--success);
            background: var(--success);
            color: #fff;
        }
        textarea {
            width: 100%;
            min-height: 110px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            resize: vertical;
            font: inherit;
            color: var(--text);
            background: #fff;
        }
        .buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 14px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid transparent;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
            font: inherit;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-danger { background: #fff; color: var(--danger); border-color: rgba(220, 38, 38, 0.28); }
        .btn-secondary { background: #fff; color: var(--primary); border-color: rgba(56, 104, 176, 0.22); }
        .empty {
            padding: 18px;
            border-radius: 16px;
            background: rgba(248, 250, 252, 0.92);
            color: var(--muted);
        }
        @media (max-width: 960px) {
            .grid { grid-template-columns: 1fr; }
            .meta-grid { grid-template-columns: 1fr; }
            .terms-grid { grid-template-columns: 1fr; }
            /* No celular a chave inteira precisa caber sem cortar. */
            .pix-valor { font-size: 16px; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <p class="eyebrow">{{ $budget['company_name'] ?? 'Sistema ERP' }}</p>
                    <h1 class="title">Orçamento {{ $budget['numero'] ?? '' }}</h1>
                    <p class="subtitle">
                        @if (($budget['titulo'] ?? '') !== '')
                            {{ $budget['titulo'] }} ·
                        @endif
                        Versão {{ $budget['versao'] ?? 1 }}
                        @if (($budget['validade_data'] ?? '') !== '')
                            · válido até {{ $budget['validade_data'] }}
                        @endif
                    </p>
                </div>
                <span class="status-badge {{ $statusClass }}">{{ $budget['status_label'] ?? 'Sem status' }}</span>
            </div>

            @if (is_string($flashSuccess) && $flashSuccess !== '')
                <div class="flash success">{{ $flashSuccess }}</div>
            @endif
            @if (is_string($flashWarning) && $flashWarning !== '')
                <div class="flash warning">{{ $flashWarning }}</div>
            @endif

            <div class="meta-grid">
                <div class="meta-item">
                    <span class="meta-label">Cliente</span>
                    <div class="meta-value">{{ $budget['client_name'] !== '' ? $budget['client_name'] : 'Não informado' }}</div>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Contato</span>
                    <div class="meta-value">{{ $budget['phone'] !== '' ? $budget['phone'] : 'Não informado' }}</div>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Equipamento</span>
                    <div class="meta-value">{{ $budget['equipment_name'] !== '' ? $budget['equipment_name'] : 'Não informado' }}</div>
                </div>
                <div class="meta-item">
                    <span class="meta-label">OS vinculada</span>
                    <div class="meta-value">{{ $budget['order_number'] !== '' ? $budget['order_number'] : 'Sem vínculo' }}</div>
                </div>
            </div>
        </section>

        <section class="grid">
            <article class="card">
                <h2 class="section-title">Itens da proposta</h2>

                <div class="items">
                    @forelse ($budget['items'] ?? [] as $item)
                        <div class="item">
                            <div class="item-top">
                                <strong>{{ $item['descricao'] !== '' ? $item['descricao'] : 'Item sem descrição' }}</strong>
                                <span>{{ ucfirst($item['tipo_item'] ?? 'item') }}</span>
                            </div>
                            <div class="money-row">
                                <span>Qtd: {{ number_format((float) ($item['quantidade'] ?? 0), 2, ',', '.') }}</span>
                                <span>Valor unit.: {{ $formatMoney((float) ($item['valor_unitario'] ?? 0)) }}</span>
                                <span>Total: {{ $formatMoney((float) ($item['total'] ?? 0)) }}</span>
                            </div>
                            @if (($item['observacoes'] ?? '') !== '')
                                <div class="item-notes">Observações: {{ $item['observacoes'] }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="empty">Nenhum item disponível nesta proposta.</div>
                    @endforelse
                </div>

                @php
                    $terms = is_array($budget['condicoes_comerciais'] ?? null) ? $budget['condicoes_comerciais'] : [];
                    $termPixKeys = is_array($terms['chaves_pix'] ?? null) ? $terms['chaves_pix'] : [];
                    $termPaymentMethods = is_array($terms['formas_pagamento'] ?? null) ? $terms['formas_pagamento'] : [];
                @endphp

                @if ($terms['tem_conteudo'] ?? false)
                    <h2 class="section-title terms-heading">Condições comerciais</h2>

                    <div class="terms-grid">
                        @if (($terms['formas_pagamento_texto'] ?? '') !== '')
                            <div class="term-card">
                                <span class="meta-label">Formas de pagamento aceitas</span>
                                <div class="chips">
                                    @foreach ($termPaymentMethods as $forma)
                                        <span class="chip">{{ $forma['nome'] }}</span>
                                    @endforeach
                                </div>
                                @if (($terms['parcelamento_texto'] ?? '') !== '')
                                    <p class="term-note">{{ $terms['parcelamento_texto'] }}</p>
                                @endif
                            </div>
                        @endif

                        @if (($terms['garantia_label'] ?? '') !== '')
                            <div class="term-card">
                                <span class="meta-label">Garantia</span>
                                <div class="term-highlight">{{ $terms['garantia_label'] }}</div>
                                <p class="term-note">
                                    Sobre os serviços executados e as peças substituídas, contada a partir da entrega do equipamento.
                                </p>
                            </div>
                        @endif
                    </div>

                    @if ($termPixKeys !== [])
                        <div class="pix-box">
                            <span class="meta-label">{{ count($termPixKeys) > 1 ? 'Chaves Pix para pagamento' : 'Chave Pix para pagamento' }}</span>

                            @foreach ($termPixKeys as $chave)
                                <div class="pix-key">
                                    <span class="pix-tipo">{{ $chave['tipo_label'] ?? 'Chave' }}</span>
                                    <span class="pix-valor" id="pix-chave-{{ $loop->index }}">{{ $chave['chave'] }}</span>
                                    <button
                                        type="button"
                                        class="btn-copy"
                                        data-copy="{{ $chave['chave'] }}"
                                        data-copy-target="pix-chave-{{ $loop->index }}"
                                        aria-label="Copiar chave Pix {{ $chave['chave'] }}"
                                    >Copiar</button>
                                </div>
                                @php
                                    $pixTitular = trim(implode(' · ', array_filter([
                                        $chave['titular'] ?? '',
                                        $chave['instituicao'] ?? '',
                                    ])));
                                @endphp
                                @if ($pixTitular !== '')
                                    <p class="pix-titular">{{ $pixTitular }}</p>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if (($terms['complemento'] ?? '') !== '')
                        <div class="term-card term-card-wide">
                            <span class="meta-label">Observações</span>
                            <p class="term-note">{{ $terms['complemento'] }}</p>
                        </div>
                    @endif
                @endif
            </article>

            <aside class="card">
                <h2 class="section-title">Resultado final</h2>

                <div class="totals">
                    <div class="total-box">
                        <strong>Subtotal</strong>
                        <strong>{{ $formatMoney((float) ($budget['subtotal'] ?? 0)) }}</strong>
                    </div>
                    <div class="total-box">
                        <strong>Desconto</strong>
                        <strong>{{ $formatMoney((float) ($budget['desconto'] ?? 0)) }}</strong>
                    </div>
                    <div class="total-box">
                        <strong>Acréscimo</strong>
                        <strong>{{ $formatMoney((float) ($budget['acrescimo'] ?? 0)) }}</strong>
                    </div>
                    <div class="total-box grand">
                        <strong>Total final</strong>
                        <strong>{{ $formatMoney((float) ($budget['total'] ?? 0)) }}</strong>
                    </div>
                </div>

                <div class="decision-box">
                    <p class="helper">
                        @if (!empty($budget['expired']))
                            Este link expirou em {{ $budget['token_expira_em'] ?? 'data não informada' }}. Solicite um novo envio ao estabelecimento.
                        @elseif (!empty($budget['can_respond']))
                            Revise a proposta e escolha abaixo se deseja aprovar ou rejeitar este orçamento.
                        @elseif (($budget['status'] ?? '') === 'rejeitado' && ($budget['motivo_rejeicao'] ?? '') !== '')
                            Rejeição registrada: {{ $budget['motivo_rejeicao'] }}
                        @else
                            Esta proposta já possui uma decisão registrada e permanece disponível apenas para consulta.
                        @endif
                    </p>

                    @if (!empty($budget['can_respond']))
                        {{-- Os dois botoes ficam na MESMA linha, acima do campo
                             de motivo. Como aninhar <form> e' invalido, cada
                             form fica sem botao dentro e os botoes se ligam a
                             eles pelo atributo HTML `form` — sem isso, mover o
                             "Rejeitar" para cima do textarea faria o motivo
                             digitado deixar de ser enviado. --}}
                        <form
                            id="formAprovarProposta"
                            method="post"
                            action="{{ route('budgets.public.approve', ['token' => request()->route('token')]) }}"
                        >
                            @csrf
                            <input type="hidden" name="resposta_cliente" value="Aprovado pelo cliente.">
                        </form>

                        <div class="decision-actions">
                            <button type="submit" form="formAprovarProposta" class="btn btn-primary">Aprovar proposta</button>
                            <button type="submit" form="formRejeitarProposta" class="btn btn-danger">Rejeitar proposta</button>
                        </div>

                        <form
                            id="formRejeitarProposta"
                            method="post"
                            action="{{ route('budgets.public.reject', ['token' => request()->route('token')]) }}"
                        >
                            @csrf
                            <label class="meta-label" for="motivoRejeicao">Se desejar, informe o motivo da rejeição</label>
                            <textarea id="motivoRejeicao" name="motivo_rejeicao" placeholder="Ex.: vou avaliar outra alternativa, preciso rever o valor, não autorizo neste momento..."></textarea>
                            @error('motivo_rejeicao')
                                <p class="helper danger-text">{{ $message }}</p>
                            @enderror
                        </form>
                    @endif

                    <div class="pdf-row">
                        <a href="{{ route('budgets.public.pdf', ['token' => request()->route('token')]) }}" class="btn btn-secondary">Baixar PDF</a>
                    </div>
                </div>
            </aside>
        </section>
    </main>
    <script>
        (function () {
            var botoes = document.querySelectorAll('[data-copy]');

            if (botoes.length === 0) {
                return;
            }

            // Dois caminhos, nesta ordem:
            //  1) navigator.clipboard — so existe em contexto seguro, e mesmo
            //     ali o navegador pode negar a permissao;
            //  2) selecao + execCommand — funciona no link aberto por IP com
            //     certificado proprio (origem "nao segura"), onde o caminho 1
            //     nem existe.
            // O 2 tambem cobre a NEGACAO do 1: sem esse encadeamento, o cliente
            // via "erro" com a chave nem selecionada.
            function copiar(texto, alvo) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(texto).catch(function () {
                        return copiarPorSelecao(texto, alvo);
                    });
                }

                return copiarPorSelecao(texto, alvo);
            }

            function copiarPorSelecao(texto, alvo) {
                return new Promise(function (resolve, reject) {
                    var selecao = window.getSelection();
                    var range = document.createRange();
                    var provisorio = null;

                    if (alvo) {
                        range.selectNodeContents(alvo);
                    } else {
                        provisorio = document.createElement('span');
                        provisorio.textContent = texto;
                        document.body.appendChild(provisorio);
                        range.selectNodeContents(provisorio);
                    }

                    selecao.removeAllRanges();
                    selecao.addRange(range);

                    var ok = false;
                    try {
                        ok = document.execCommand('copy');
                    } catch (erro) {
                        ok = false;
                    }

                    if (provisorio) {
                        document.body.removeChild(provisorio);
                    }

                    // Mantem a chave selecionada quando a copia automatica
                    // falhou: o cliente ainda consegue copiar manualmente.
                    if (ok) {
                        selecao.removeAllRanges();
                        resolve();
                    } else {
                        reject(new Error('copy-failed'));
                    }
                });
            }


            botoes.forEach(function (botao) {
                var rotuloOriginal = botao.textContent;
                var timer = null;

                botao.addEventListener('click', function () {
                    var alvo = document.getElementById(botao.getAttribute('data-copy-target'));

                    copiar(botao.getAttribute('data-copy'), alvo).then(function () {
                        botao.textContent = 'Copiado!';
                        botao.classList.add('is-done');
                    }).catch(function () {
                        botao.textContent = 'Selecione e copie';
                    }).then(function () {
                        window.clearTimeout(timer);
                        timer = window.setTimeout(function () {
                            botao.textContent = rotuloOriginal;
                            botao.classList.remove('is-done');
                        }, 2500);
                    });
                });
            });
        })();
    </script>
</body>
</html>
