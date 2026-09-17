@php
    $budget = is_array($budget ?? null) ? $budget : [];
    // O resultado do POST (aprovar/rejeitar) chega por querystring, nao por
    // flash de sessao: no celular do cliente (link aberto pelo WhatsApp/
    // e-mail, navegador in-app, rede movel) o cookie de sessao nem sempre
    // sobrevive ao redirect pos-POST, e a faixa de sucesso + o confete
    // ficavam mudos mesmo com a aprovacao gravada. Cai pro flash de sessao
    // so como fallback (ex.: redirect do PDF, que ainda usa session()).
    $queryResultado = (string) request()->query('resultado', '');
    $queryMensagem = trim((string) request()->query('mensagem', ''));
    $flashSuccess = $queryResultado === 'sucesso' && $queryMensagem !== '' ? $queryMensagem : session('success');
    $flashWarning = $queryResultado === 'aviso' && $queryMensagem !== '' ? $queryMensagem : session('warning');
    $formatMoney = static fn (float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
    $statusClass = match ((string) ($budget['status'] ?? '')) {
        'aprovado', 'pendente_abertura_os' => 'status-approved',
        'rejeitado' => 'status-rejected',
        default => 'status-pending',
    };
    // O flash 'success' tambem cobre rejeicao bem-sucedida (redirectWithResult
    // usa o mesmo tipo pra ambas as acoes). So dispara confete quando o flash
    // veio acompanhado do status ja aprovado.
    $showConfetti = is_string($flashSuccess) && $flashSuccess !== '' && $statusClass === 'status-approved';
    // Orçamento com níveis de manutenção, ainda sem decisão: passo 1 é a
    // escolha da opção (partial opcoes); passo 2 (?opcao=N) é o corpo normal
    // com itens/totais daquela opção. Orçamento comum nunca entra aqui.
    $selectedOption = (int) ($budget['opcao_selecionada'] ?? 0);
    $showOptions = ! empty($budget['has_tiers']) && ! empty($budget['can_respond']) && $selectedOption <= 0;
    $publicToken = (string) request()->route('token');
    // Ícones em SVG inline (a página é autocontida e glifo de fonte já falhou
    // uma vez aqui). Strings constantes, impressas com {!! !!}; os partials
    // incluídos enxergam esta closure.
    $icon = static function (string $name, int $size = 18): string {
        $paths = [
            'whatsapp' => '<path d="M20.5 3.5A11.8 11.8 0 0 0 12 0C5.5 0 .2 5.3.2 11.8c0 2.1.5 4.1 1.6 5.9L0 24l6.5-1.7a11.8 11.8 0 0 0 5.5 1.4c6.5 0 11.8-5.3 11.8-11.8 0-3.2-1.2-6.1-3.3-8.4zM12 21.7c-1.8 0-3.5-.5-5-1.4l-.4-.2-3.8 1 1-3.7-.2-.4A9.7 9.7 0 0 1 2.2 11.8C2.2 6.4 6.6 2 12 2c2.6 0 5.1 1 6.9 2.9a9.7 9.7 0 0 1 2.9 6.9c0 5.4-4.4 9.9-9.8 9.9zm5.4-7.3c-.3-.1-1.8-.9-2-1-.3-.1-.5-.1-.7.1l-.9 1.2c-.2.2-.3.2-.6.1-.3-.1-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5.3-.5c.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.2-.3-.2-.6-.4z" fill="currentColor" stroke="none"/>',
            'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9l-3.8 3.8z"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'sparkles' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/><circle cx="12" cy="12" r="3"/>',
            'truck' => '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'card' => '<rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/>',
            'wallet' => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
            'gift' => '<path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'receipt' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        ];

        return sprintf(
            '<svg class="ico ico-%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            $name,
            $size,
            $size,
            $paths[$name] ?? ''
        );
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
            /* Identidade do nível mais alto (Completa): mesmo roxo do gradiente
               do hero, reaproveitado aqui para o card não ganhar uma cor
               "de fora da família" quando não é o recomendado/sugerido. */
            --premium: #6f5afc;
            --premium-soft: rgba(111, 90, 252, 0.14);
            /* Identidade do nível de entrada (Básica): verde-azulado, longe do
               laranja de aviso (.flash.warning) e do verde de sucesso/aprovado
               (--success) já usados nesta mesma página. */
            --basic: #0d9488;
            --basic-soft: rgba(13, 148, 136, 0.14);
            /* Identidade do nível do meio (Avançada): cinza neutro — o azul
               fica reservado para marca/CTA/selo de recomendado, sem virar
               também "a cor do nível 2". */
            --mid: #55627a;
            --mid-soft: rgba(85, 98, 122, 0.14);
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
            grid-template-columns: 1fr;
        }
        .card {
            padding: 22px;
        }
        .meta-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
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
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }
        /* Decidir vem primeiro: aprovar e rejeitar na mesma linha. Rejeitar
           abre um modal com o campo de motivo — nao afasta o cliente que so
           quer aprovar. Baixar o PDF e' acao secundaria e fecha o bloco,
           separada por um respiro. */
        .decision-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }
        .decision-actions .btn { flex: 1 1 auto; }
        .pdf-row { margin-top: 22px; }
        .modal-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1000;
        }
        .modal-overlay[hidden] { display: none; }
        .modal-box {
            width: min(480px, 100%);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            padding: 22px;
            border-radius: 24px;
            border: 1px solid var(--border);
            background: var(--card);
            box-shadow: 0 22px 44px rgba(15, 23, 42, 0.18);
        }
        .modal-box textarea { margin-top: 10px; }
        .modal-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
        }
        .modal-actions .btn { flex: 1 1 auto; }
        .danger-text { color: var(--danger); margin-bottom: 0; }
        /* Condicoes comerciais: o cliente precisa achar em 2 segundos como
           paga e por quanto tempo tem garantia. Cartoes curtos em vez de
           paragrafo corrido. */
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
        .term-benefits { margin-top: 12px; }
        .term-benefits .option-list { margin-top: 8px; }
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
        /* Texto só para leitor de tela: mantém o botão curto na tela ("Escolher
           esta opção" nos 3 cartões) sem virar ambíguo para quem navega por
           teclado/leitor de tela e ouve os botões fora do contexto visual. */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        /* Níveis de manutenção: landing com as opções (passo 1). Cartão de
           preço limpo: nome, preço, uma linha, botão, e só então a lista —
           o cliente decide sem rolar a lista inteira antes. */
        .attendance-line {
            margin: 18px 0 0;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.4;
        }
        .attendance-details { margin-top: 6px; }
        .attendance-details .meta-grid { margin-top: 12px; }
        /* Os <summary> são alvo de toque no celular: área generosa, não só o
           texto sublinhado. */
        .attendance-details summary,
        .option-more summary,
        .options-reject summary {
            display: inline-block;
            padding: 8px 0;
            cursor: pointer;
            color: var(--primary);
            font-weight: 700;
            -webkit-user-select: none;
            user-select: none;
        }
        .attendance-details summary::-webkit-details-marker,
        .option-more summary::-webkit-details-marker,
        .options-reject summary::-webkit-details-marker { display: none; }
        /* Seta desenhada em CSS (borda), não caractere Unicode: alguns
           navegadores/fontes não têm o glifo ▾ e mostravam um ponto solto no
           lugar — o que apagava a única pista visual de "isto expande". */
        .attendance-details summary::after,
        .option-more summary::after,
        .options-reject summary::after {
            content: "";
            display: inline-block;
            margin-left: 6px;
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 5px solid currentColor;
            vertical-align: middle;
            transition: transform .15s ease;
        }
        .attendance-details[open] summary::after,
        .option-more[open] summary::after,
        .options-reject[open] summary::after { transform: rotate(180deg); }
        /* ---- Landing dos níveis: hero humano ---- */
        .hero-landing { padding: 24px 28px 20px; }
        /* Conteúdo à esquerda, marca à direita: o olhar começa na saudação e
           a metade direita do cartão vira composição em vez de sobra. No
           celular vira uma coluna, com a marca acima da saudação. */
        .hero-landing-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px 32px;
            align-items: start;
        }
        .hero-main { grid-column: 1; grid-row: 1; min-width: 0; }
        .brand-lockup {
            grid-column: 2;
            grid-row: 1;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            max-width: 200px;
            text-align: right;
        }
        .brand-logo { display: block; max-height: 72px; max-width: 160px; width: auto; height: auto; }
        .brand-name { font-size: 15px; font-weight: 800; color: var(--primary); letter-spacing: .01em; line-height: 1.3; }
        /* Segunda linha do nome fantasia (ex.: "Celulares e Informática"),
           quando o nome tem 3+ palavras — ver $heroCompanySecondary acima.
           Menor e mais leve para não competir em peso com o nome em si. */
        .brand-name-sub { display: block; margin-top: 1px; font-size: 12px; font-weight: 600; color: var(--muted); letter-spacing: 0; }
        .hero-greeting {
            margin: 0;
            font-size: clamp(28px, 4.4vw, 40px);
            line-height: 1.05;
            letter-spacing: -0.02em;
        }
        .lead {
            margin: 10px 0 0;
            max-width: 68ch;
            font-size: clamp(16px, 1.9vw, 19px);
            line-height: 1.55;
            color: var(--text);
        }
        .lead strong { color: var(--primary); }
        .hero-pills {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 10px;
            margin-top: 16px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(56, 104, 176, 0.07);
            /* --text em vez de --muted: --muted sobre este fundo claro fica
               em ~4,6:1, no limite do AA para 13px bold; estes chips carregam
               nº do orçamento e validade, então a margem de segurança importa. */
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
        }
        /* Verde do WhatsApp: reconhecimento de marca do canal, só aqui. */
        .btn-whatsapp {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 10px 16px;
            border-radius: 999px;
            background: #25d366;
            color: #0b3d1f;
            font-weight: 800;
            font-size: 14px;
            text-decoration: none;
            box-shadow: 0 10px 22px rgba(37, 211, 102, 0.28);
        }
        .btn-whatsapp:hover { background: #1fc15b; }
        /* Ações secundárias numa linha só: detalhes do atendimento à esquerda,
           ajuda pelo WhatsApp à direita. O botão verde fica só no rodapé das
           opções — aqui em cima ele roubava a cena da escolha da manutenção,
           que é a ação principal da página. */
        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px 20px;
            margin-top: 10px;
        }
        .hero-actions .attendance-details { flex: 1 1 320px; min-width: 0; margin-top: 0; }
        .hero-actions .attendance-details summary { font-size: 15px; }
        /* Aberto, o cadastro divide a linha com o botão de ajuda: duas colunas
           folgadas em vez de quatro espremidas. */
        .hero-actions .attendance-details .meta-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        /* Mesmo selo verde do CTA de WhatsApp do rodapé das opções, só com
           texto mais curto aqui em cima — reconhecível como o mesmo canal. */
        .hero-actions .btn-whatsapp { flex-shrink: 0; }

        /* ---- Cartões das opções ---- */
        .options-intro { margin: 6px 0 18px; padding: 0 4px; }
        .options-title {
            margin: 0 0 8px;
            font-size: clamp(22px, 3vw, 30px);
            line-height: 1.15;
            letter-spacing: -0.01em;
        }
        .options-intro .helper { max-width: 70ch; }
        .options-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: stretch;
            margin-bottom: 18px;
            padding-top: 20px;
        }
        .option-card {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding-top: 0;
            /* Borda sempre com a mesma espessura: o destaque só muda a cor,
               sem os outros dois "pularem" 1px na largura. */
            border-width: 2px;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .option-card.is-recommended,
        .option-card.is-suggested {
            border-color: var(--primary);
            box-shadow: 0 24px 48px rgba(56, 104, 176, 0.22);
        }
        /* Selo flutuante de destaque: sinal à parte da identidade do nível —
           por isso não muda a cor do cabeçalho, só "flutua" sobre a borda de
           cima do cartão. */
        .option-badge {
            position: absolute;
            top: -13px;
            left: 50%;
            transform: translateX(-50%);
            display: inline-flex;
            align-items: center;
            padding: 5px 16px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            white-space: nowrap;
            background: var(--primary);
            color: #fff;
            box-shadow: 0 8px 18px rgba(56, 104, 176, 0.32);
            z-index: 2;
        }
        /* "Mais escolhida": palpite do sistema quando ninguém marcou uma
           recomendada — selo mais suave, para nunca parecer curadoria humana. */
        .option-badge-auto {
            background: #fff;
            color: var(--primary);
            border: 2px solid var(--primary);
            padding: 3px 14px;
        }
        /* Cabeçalho colorido: identidade do nível (ícone + nome). Sempre
           presente, independente de ser o recomendado/mais escolhida —
           por isso não tem seletor de precedência com .is-recommended. */
        .option-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 -22px 14px;
            padding: 16px 22px 14px;
            border-radius: 22px 22px 0 0;
            background: var(--primary-soft);
            color: var(--text);
        }
        .option-card.is-basic .option-header { background: var(--basic); color: #fff; }
        .option-card.is-mid .option-header { background: var(--mid); color: #fff; }
        .option-card.is-premium .option-header { background: var(--premium); color: #fff; }
        .option-header .option-icon { background: rgba(255, 255, 255, 0.24); color: inherit; }
        .option-header .option-name { color: inherit; }
        .option-header .option-tagline { color: inherit; opacity: .82; }
        .option-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            border-radius: 14px;
            background: var(--primary-soft);
            color: var(--primary);
        }
        .option-name { margin: 0; font-size: 20px; line-height: 1.2; }
        .option-tagline { margin: 2px 0 0; color: var(--muted); font-size: 14px; }
        .coverage { display: flex; gap: 4px; margin: 10px 0 2px; }
        .coverage-segment {
            flex: 1;
            height: 6px;
            border-radius: 999px;
            background: rgba(56, 104, 176, 0.14);
        }
        .coverage-segment.is-filled { background: var(--primary); }
        .option-price { margin-top: 6px; }
        .option-total {
            font-size: clamp(28px, 3.4vw, 36px);
            font-weight: 800;
            color: var(--text);
            letter-spacing: -0.02em;
            line-height: 1.1;
        }
        .option-installment {
            margin: 4px 0 0;
            color: var(--primary);
            font-weight: 700;
            font-size: 15px;
        }
        .option-delta { margin: 4px 0 0; color: var(--muted); font-size: 13px; }
        .option-cta { margin-top: 12px; width: 100%; }
        .btn-outline-primary {
            background: #fff;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        .btn-outline-primary:hover { background: var(--primary-soft); }
        .option-divider {
            margin: 12px 0 2px;
            border: 0;
            border-top: 1px solid rgba(148, 163, 184, 0.24);
        }
        .option-list-heading {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .option-list {
            margin: 6px 0 0;
            padding: 0;
            list-style: none;
            color: var(--text);
            line-height: 1.5;
        }
        .option-list li {
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .option-list li + li { margin-top: 6px; }
        .option-list li::before {
            content: "✓";
            flex-shrink: 0;
            color: var(--primary);
            font-weight: 800;
            line-height: 1.5;
        }
        .option-list-empty { color: var(--muted); }
        .option-list-empty::before { content: "—"; }
        /* Vantagens da opção (entrega, diferenciais, condições que variam):
           mesmo ritmo da lista de itens, ícone em vez de "✓" — é o que vem a
           mais, não o escopo do reparo. */
        .option-perks-heading { margin-top: 14px; }
        .option-perks li::before { content: none; }
        .perk-icon {
            display: inline-flex;
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: var(--primary-soft);
            color: var(--primary);
        }
        .option-card .option-perks-heading { color: var(--primary); }
        .option-card.is-basic .perk-icon { background: var(--basic-soft); color: var(--basic); }
        .option-card.is-mid .perk-icon { background: var(--mid-soft); color: var(--mid); }
        .option-card.is-premium .perk-icon { background: var(--premium-soft); color: var(--premium); }
        .option-card.is-basic .option-perks-heading { color: var(--basic); }
        .option-card.is-mid .option-perks-heading { color: var(--mid); }
        .option-card.is-premium .option-perks-heading { color: var(--premium); }

        /* ---- Faixa de confiança ---- */
        .trust-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin: 0 0 18px;
        }
        .trust-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.7);
            color: var(--text);
            font-size: 14px;
            line-height: 1.4;
        }
        .trust-icon {
            display: inline-flex;
            flex-shrink: 0;
            width: 38px;
            height: 38px;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--success-soft);
            color: var(--success);
        }
        .footer-cta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px 16px;
            margin-top: 6px;
        }
        .footer-cta-title { margin: 0 0 2px; font-weight: 800; font-size: 17px; }
        .options-footer .helper + .footer-cta { margin-top: 14px; }
        .option-more { margin-top: 2px; }
        .option-more summary { font-size: 14px; }
        .option-more .option-list { margin-top: 4px; }
        .options-validity {
            margin: 0 0 18px;
            text-align: center;
            color: var(--muted);
            font-size: 14px;
        }
        .options-footer .helper { margin: 0; }
        .options-reject { margin-top: 10px; }
        .options-reject summary { color: var(--muted); }
        .options-reject form { margin-top: 12px; }
        /* Passo 2: chip da opção escolhida + link para trocar. */
        .option-selected {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: var(--primary-soft);
            margin-bottom: 14px;
        }
        .option-selected strong { color: var(--primary); }
        .option-selected a { color: var(--primary); font-weight: 700; }
        @media (min-width: 961px) {
            .option-card.is-recommended,
            .option-card.is-suggested { transform: translateY(-10px); }
            /* Os três cartões passam a compartilhar uma grade de linhas
               (subgrid): CTA, "Inclui", "Ver os itens" e "Vantagens" alinham
               na mesma altura mesmo quando o conteúdo de cada nível difere
               em tamanho. No celular (1 coluna) isso não se aplica — cada
               cartão segue como flex simples, empilhado. */
            .options-grid { grid-template-rows: repeat(8, auto); }
            .option-card {
                display: grid;
                grid-template-rows: subgrid;
                grid-row: span 8;
                row-gap: 6px;
            }
        }
        @media (max-width: 960px) {
            .options-grid { grid-template-columns: 1fr; padding-top: 0; }
            .hero-landing { padding: 20px 20px 16px; }
            .hero-landing-grid { grid-template-columns: 1fr; gap: 14px; }
            .hero-main { grid-row: 2; }
            .brand-lockup {
                grid-column: 1;
                grid-row: 1;
                flex-direction: row;
                align-items: center;
                gap: 10px;
                max-width: none;
                text-align: left;
            }
            .brand-logo { max-height: 36px; max-width: 120px; }
            .hero-actions .btn-whatsapp,
            .footer-cta .btn-whatsapp { width: 100%; }
            .meta-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .terms-grid { grid-template-columns: 1fr; }
            /* No celular a chave inteira precisa caber sem cortar. */
            .pix-valor { font-size: 16px; }
        }
        @media (max-width: 560px) {
            .meta-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero {{ $showOptions ? 'hero-landing' : '' }}">
            @if ($showOptions)
                {{-- Landing dos níveis: a página fala com uma pessoa e é assinada
                     por uma pessoa. O número do orçamento vira metadado; o status
                     ("aguardando resposta") é jargão de sistema e fica de fora —
                     o cliente só chega aqui podendo responder. --}}
                @php
                    $heroEquipment = trim((string) ($budget['equipment_name'] ?? ''));
                    $heroEquipmentParts = array_values(array_filter(
                        array_map('trim', explode('|', $heroEquipment)),
                        static fn (string $part): bool => $part !== ''
                    ));
                    $heroEquipmentShort = count($heroEquipmentParts) >= 3
                        ? implode(' ', array_slice($heroEquipmentParts, 1))
                        : implode(' ', $heroEquipmentParts);
                    $heroFirstName = trim((string) ($budget['client_first_name'] ?? ''));
                    $heroLogo = (string) ($budget['company_logo_data_uri'] ?? '');
                    $heroWhatsapp = (string) ($budget['company_whatsapp_url'] ?? '');
                    // Nome fantasia é um campo só ("Jovem Tech Celulares e
                    // Informática"); nomes de 3+ palavras costumam seguir o
                    // padrão marca (1-2 palavras) + ramo, então a 3ª em diante
                    // vira subtítulo menor. Nomes de até 2 palavras ficam numa
                    // linha só — sem isso, um nome curto ganharia uma segunda
                    // linha vazia.
                    $heroCompanyName = trim((string) ($budget['company_name'] ?? 'Sistema ERP'));
                    $heroCompanyWords = $heroCompanyName !== '' ? preg_split('/\s+/u', $heroCompanyName) : [];
                    $heroCompanyPrimary = count($heroCompanyWords) > 2
                        ? implode(' ', array_slice($heroCompanyWords, 0, 2))
                        : $heroCompanyName;
                    $heroCompanySecondary = count($heroCompanyWords) > 2
                        ? implode(' ', array_slice($heroCompanyWords, 2))
                        : '';
                @endphp
                <div class="hero-landing-grid">
                    {{-- O nome vem sempre em texto; a imagem, quando existe, é
                         decorativa (alt vazio) para o leitor de tela não ler a
                         marca duas vezes. --}}
                    <div class="brand-lockup">
                        @if ($heroLogo !== '')
                            <img class="brand-logo" src="{{ $heroLogo }}" alt="">
                        @endif
                        <span class="brand-name">{{ $heroCompanyPrimary }}@if ($heroCompanySecondary !== '')<span class="brand-name-sub">{{ $heroCompanySecondary }}</span>@endif</span>
                    </div>

                    <div class="hero-main">
                        <h1 class="hero-greeting">{{ $heroFirstName !== '' ? 'Olá, '.$heroFirstName.'.' : 'Olá!' }}</h1>
                        <p class="lead">
                            @if ($heroEquipmentShort !== '')
                                Seu <strong>{{ $heroEquipmentShort }}</strong> já foi avaliado. Agora é só escolher como cuidar dele.
                            @else
                                Seu aparelho já foi avaliado. Agora é só escolher como cuidar dele.
                            @endif
                        </p>

                        <div class="hero-pills">
                            @if (($budget['numero'] ?? '') !== '')
                                <span class="pill">Orçamento {{ $budget['numero'] }}</span>
                            @endif
                            @if (($budget['validade_data'] ?? '') !== '')
                                <span class="pill">Válido até {{ $budget['validade_data'] }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @else
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
            @endif

            @if (is_string($flashSuccess) && $flashSuccess !== '')
                <div class="flash success">{{ $flashSuccess }}</div>
            @endif
            @if (is_string($flashWarning) && $flashWarning !== '')
                <div class="flash warning">{{ $flashWarning }}</div>
            @endif

            @if ($showOptions)
                {{-- Landing dos níveis: o cliente veio escolher uma opção, não
                     conferir cadastro. A saudação acima já diz quem e qual
                     aparelho; o cadastro completo fica a um toque. --}}
                <div class="hero-actions">
                    <details class="attendance-details">
                        <summary>Ver detalhes do atendimento</summary>
                        @include('budgets.public.partials.meta-grid')
                    </details>
                    @if ($heroWhatsapp !== '')
                        <a class="btn-whatsapp" href="{{ $heroWhatsapp }}" target="_blank" rel="noopener">{!! $icon('whatsapp', 18) !!}Precisa de ajuda?</a>
                    @endif
                </div>
            @else
                @include('budgets.public.partials.meta-grid')
            @endif
        </section>

        @if ($showOptions)
            @include('budgets.public.partials.opcoes')
        @else
        <section class="grid">
            <article class="card">
                <h2 class="section-title">Itens da proposta</h2>

                @if ($selectedOption > 0)
                    <div class="option-selected">
                        <span>Opção escolhida: <strong>{{ $budget['opcao_selecionada_label'] ?? '' }}</strong></span>
                        <a href="{{ route('budgets.public.show', ['token' => $publicToken]) }}">Trocar de opção</a>
                    </div>
                @elseif (! empty($budget['nivel_aprovado_label']))
                    <div class="option-selected">
                        <span>Opção aprovada: <strong>{{ $budget['nivel_aprovado_label'] }}</strong></span>
                    </div>
                @endif

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
            </article>

            <article class="card">
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
            </article>

            @php
                $terms = is_array($budget['condicoes_comerciais'] ?? null) ? $budget['condicoes_comerciais'] : [];
                $termPixKeys = is_array($terms['chaves_pix'] ?? null) ? $terms['chaves_pix'] : [];
                $termPaymentMethods = is_array($terms['formas_pagamento'] ?? null) ? $terms['formas_pagamento'] : [];
            @endphp

            @if ($terms['tem_conteudo'] ?? false)
                <article class="card">
                    <h2 class="section-title">Condições comerciais</h2>

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

                        @if (! empty($terms['entrega_domicilio']))
                            <div class="term-card">
                                <span class="meta-label">Entrega</span>
                                <div class="term-highlight">No seu endereço</div>
                                <p class="term-note">Entrega do equipamento reparado no seu endereço, sem custo adicional.</p>
                            </div>
                        @endif
                    </div>

                    @php $termBenefits = is_array($terms['beneficios'] ?? null) ? $terms['beneficios'] : []; @endphp
                    @if ($termBenefits !== [])
                        <div class="term-card term-benefits">
                            <span class="meta-label">Diferenciais desta opção</span>
                            <ul class="option-list option-perks">
                                @foreach ($termBenefits as $beneficio)
                                    <li>{{ $beneficio }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

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
                </article>
            @endif

            <article class="card">
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
                            @if ($selectedOption > 0)
                                <input type="hidden" name="nivel" value="{{ $selectedOption }}">
                            @endif
                        </form>

                        {{-- Form sem controles visiveis: o textarea e os
                             botoes de confirmar/cancelar moram no modal, mas
                             se ligam a este form pelo atributo HTML `form`
                             (funciona mesmo fora da arvore do <form>). --}}
                        <form
                            id="formRejeitarProposta"
                            method="post"
                            action="{{ route('budgets.public.reject', ['token' => request()->route('token')]) }}"
                        >
                            @csrf
                        </form>

                        <div class="decision-actions">
                            <button type="submit" form="formAprovarProposta" class="btn btn-primary">Aprovar proposta</button>
                            <button type="button" class="btn btn-danger" data-open-modal="modalRejeitarProposta">Rejeitar proposta</button>
                        </div>

                        <div
                            class="modal-overlay"
                            id="modalRejeitarProposta"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="modalRejeitarTitulo"
                            @if (! $errors->has('motivo_rejeicao')) hidden @endif
                        >
                            <div class="modal-box">
                                <h3 id="modalRejeitarTitulo" class="section-title">Rejeitar proposta</h3>
                                <label class="meta-label" for="motivoRejeicao">Se desejar, informe o motivo da rejeição</label>
                                <textarea id="motivoRejeicao" name="motivo_rejeicao" form="formRejeitarProposta" placeholder="Ex.: vou avaliar outra alternativa, preciso rever o valor, não autorizo neste momento...">{{ old('motivo_rejeicao') }}</textarea>
                                @error('motivo_rejeicao')
                                    <p class="helper danger-text">{{ $message }}</p>
                                @enderror

                                <div class="modal-actions">
                                    <button type="button" class="btn btn-secondary" data-close-modal>Cancelar</button>
                                    <button type="submit" form="formRejeitarProposta" class="btn btn-danger">Confirmar rejeição</button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="pdf-row">
                        <a href="{{ route('budgets.public.pdf', $selectedOption > 0 ? ['token' => $publicToken, 'opcao' => $selectedOption] : ['token' => $publicToken]) }}" class="btn btn-secondary">Baixar PDF</a>
                    </div>
                </div>
            </article>
        </section>
        @endif
    </main>
    @if ($showConfetti)
        <canvas id="confetti-canvas" aria-hidden="true" style="position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9999;"></canvas>
        <script>
            (function () {
                if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    return;
                }

                function iniciar() {
                    var canvas = document.getElementById('confetti-canvas');
                    if (!canvas) {
                        return;
                    }
                    var ctx = canvas.getContext('2d');
                    var cores = ['#3868b0', '#15803d', '#f59e0b', '#dc2626', '#6f5afc'];
                    var duracaoMs = 3200;
                    var inicio = null;
                    var particulas = [];

                    function redimensionar() {
                        canvas.width = window.innerWidth;
                        canvas.height = window.innerHeight;
                    }

                    function criarParticulas(qtd) {
                        for (var i = 0; i < qtd; i++) {
                            particulas.push({
                                x: Math.random() * canvas.width,
                                y: -20 - Math.random() * canvas.height * 0.3,
                                largura: 6 + Math.random() * 6,
                                altura: 8 + Math.random() * 8,
                                cor: cores[Math.floor(Math.random() * cores.length)],
                                velocidadeY: 2 + Math.random() * 3,
                                velocidadeX: -1.5 + Math.random() * 3,
                                angulo: Math.random() * Math.PI,
                                velocidadeAngulo: -0.2 + Math.random() * 0.4,
                            });
                        }
                    }

                    function passo(agora) {
                        if (inicio === null) {
                            inicio = agora;
                        }
                        var decorrido = agora - inicio;

                        ctx.clearRect(0, 0, canvas.width, canvas.height);

                        for (var i = 0; i < particulas.length; i++) {
                            var p = particulas[i];
                            p.x += p.velocidadeX;
                            p.y += p.velocidadeY;
                            p.angulo += p.velocidadeAngulo;

                            ctx.save();
                            ctx.translate(p.x, p.y);
                            ctx.rotate(p.angulo);
                            ctx.fillStyle = p.cor;
                            ctx.fillRect(-p.largura / 2, -p.altura / 2, p.largura, p.altura);
                            ctx.restore();
                        }

                        if (decorrido < duracaoMs) {
                            window.requestAnimationFrame(passo);
                        } else {
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            canvas.remove();
                        }
                    }

                    redimensionar();
                    window.addEventListener('resize', redimensionar);
                    criarParticulas(160);
                    window.requestAnimationFrame(passo);
                }

                // No Safari/iOS o rAF de um script inline pode ficar em
                // segundo plano enquanto a pagina ainda esta carregando
                // (fontes, imagens) logo apos o redirect da aprovacao —
                // so dispara com a pagina 100% carregada, que e o ponto
                // onde o navegador prioriza rAF normalmente.
                if (document.readyState === 'complete') {
                    iniciar();
                } else {
                    window.addEventListener('load', iniciar, { once: true });
                }
            })();
        </script>
    @endif
    @if ($queryResultado !== '' || $queryMensagem !== '')
        <script>
            (function () {
                if (!window.history || !window.history.replaceState) {
                    return;
                }

                var url = new URL(window.location.href);
                url.searchParams.delete('resultado');
                url.searchParams.delete('mensagem');
                window.history.replaceState(window.history.state, document.title, url.toString());
            })();
        </script>
    @endif
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
    <script>
        (function () {
            function abrirModal(modal) {
                modal.hidden = false;
                var campo = modal.querySelector('textarea, button, input');
                if (campo) {
                    campo.focus();
                }
            }

            function fecharModal(modal) {
                modal.hidden = true;
            }

            document.querySelectorAll('[data-open-modal]').forEach(function (botao) {
                var modal = document.getElementById(botao.getAttribute('data-open-modal'));
                if (!modal) {
                    return;
                }
                botao.addEventListener('click', function () {
                    abrirModal(modal);
                });
            });

            document.querySelectorAll('.modal-overlay').forEach(function (modal) {
                modal.querySelectorAll('[data-close-modal]').forEach(function (botao) {
                    botao.addEventListener('click', function () {
                        fecharModal(modal);
                    });
                });

                // Clique no fundo escurecido fecha o modal; clique dentro
                // da caixa branca (modal-box) nao deve fechar.
                modal.addEventListener('click', function (evento) {
                    if (evento.target === modal) {
                        fecharModal(modal);
                    }
                });
            });

            document.addEventListener('keydown', function (evento) {
                if (evento.key !== 'Escape') {
                    return;
                }
                document.querySelectorAll('.modal-overlay:not([hidden])').forEach(fecharModal);
            });
        })();
    </script>
</body>
</html>
