{{-- Passo 1 do orçamento com níveis de manutenção: o cliente compara as
     opções e escolhe uma. Só então (?opcao=N) vê o orçamento daquela opção,
     com o PDF montado para ela, e decide. Nada é gravado até a aprovação.

     Cartão no espírito de tabela de preços: nome, preço, uma linha, botão e
     a lista de itens. A lista é sempre completa e literal — cada item pode
     estar em qualquer subconjunto de níveis (não é mais "a partir de X"), e
     mostrar tudo o que realmente compõe cada opção é o que deixa um erro de
     composição (ex.: duas versões de uma mesma peça na mesma opção) visível
     antes de o orçamento ser enviado, em vez de escondido atrás de "inclui
     tudo da anterior". --}}
@php
    // Modo consulta (pós-decisão): a mesma landing, alimentada pelo snapshot
    // da aprovação (`offeredOptions`/`offeredLayout`), sem botão de escolher
    // nem de recusar; a opção aprovada leva o selo e as outras ficam só
    // como registro do que foi apresentado.
    $readOnly = ! empty($readOnly ?? false);
    $approvedLevel = $readOnly ? (int) ($budget['nivel_aprovado'] ?? 0) : 0;
    $rawOptions = $readOnly && is_array($offeredOptions ?? null)
        ? $offeredOptions
        : (is_array($budget['niveis'] ?? null) ? $budget['niveis'] : []);

    // Opção idêntica à vizinha de baixo (mesmos itens, mesmo total) fica de
    // fora — mostrar as duas só gera a pergunta "por que pagar mais por uma
    // opção igual?". Comparação pela lista+total já filtrados, não por um
    // proxy de "item novo" (deixou de fazer sentido: um item pode estar em
    // Básica e Completa sem estar em Avançada).
    $options = [];
    foreach ($rawOptions as $option) {
        $previous = $options === [] ? null : end($options);
        $isRedundant = $previous !== null
            && ($option['itens'] ?? []) === ($previous['itens'] ?? [])
            && abs((float) ($option['total'] ?? 0) - (float) ($previous['total'] ?? 0)) < 0.01;

        if (! $isRedundant) {
            $options[] = $option;
        }
    }
    if ($options === []) {
        $options = $rawOptions; // nunca deveria zerar, mas não deixa a landing em branco.
    }

    // Sem recomendação manual (ou a recomendada era justamente a que sumiu no
    // filtro acima): sugere o nível do meio como pista visual, com um selo
    // mais discreto ("Mais escolhida") — pra não parecer que um humano
    // decidiu aquilo, já que foi só um palpite do sistema.
    $hasManualRecommendation = collect($options)->contains(static fn (array $option): bool => ! empty($option['recomendado']));
    // Na consulta o palpite do sistema não faz sentido: o selo que importa
    // é o da opção aprovada (a recomendação manual continua, era parte do
    // que o cliente viu).
    if (! $readOnly && ! $hasManualRecommendation && count($options) > 1) {
        $suggestedIndex = (int) round((count($options) - 1) / 2);
        $options[$suggestedIndex]['recomendado'] = true;
        $options[$suggestedIndex]['sugerido_automaticamente'] = true;
    }

    // Condições comerciais: o serviço já decidiu, campo a campo, se o valor é
    // igual em todas as opções (dito uma vez no rodapé) ou varia (dito dentro
    // de cada cartão, para a comparação entre colunas ser honesta).
    $termsLayout = $readOnly && is_array($offeredLayout ?? null)
        ? $offeredLayout
        : (is_array($budget['condicoes_comerciais_layout'] ?? null) ? $budget['condicoes_comerciais_layout'] : []);
    $termShared = static fn (string $campo): string => ($termsLayout[$campo]['modo'] ?? 'compartilhado') === 'compartilhado'
        ? trim((string) ($termsLayout[$campo]['valor'] ?? ''))
        : '';
    $termPerOption = static fn (string $campo): bool => ($termsLayout[$campo]['modo'] ?? 'compartilhado') === 'por_opcao';
    $optionValidity = trim((string) ($budget['validade_data'] ?? ''));
    $optionToken = (string) request()->route('token');
    // Nos cartões, "Manutenção" já está implícito pelo título da seção — o
    // nome próprio (Básica/Avançada/Completa) basta e cabe melhor no mobile.
    // Fora daqui (passo 2, WhatsApp, auditoria, PDF) o rótulo completo
    // continua, pois lá ele aparece sem esse contexto ao redor.
    $shortLabel = static fn (string $label): string => str_starts_with($label, 'Manutenção ')
        ? substr($label, strlen('Manutenção '))
        : $label;
    // Medidor de cobertura: segmentos = maior nível oferecido (2 ou 3).
    $optionLevels = array_map(static fn (array $option): int => (int) ($option['nivel'] ?? 1), $options ?: [['nivel' => 1]]);
    $optionCoverageMax = max(1, (int) max($optionLevels));
    $optionCoverageMin = min($optionLevels);
    $whatsappUrl = (string) ($budget['company_whatsapp_url'] ?? '');
@endphp
<section class="options-intro">
    @if ($readOnly)
        <p class="eyebrow">Opções apresentadas</p>
        <h2 class="options-title">O que foi oferecido para o seu aparelho</h2>
        <p class="helper">
            Estas são as {{ count($options) }} opções que você comparou, com os itens e as condições de cada uma.
            A sua escolha está marcada; as demais ficam aqui só para consulta.
        </p>
    @else
        <p class="eyebrow">Escolha a opção de manutenção</p>
        <h2 class="options-title">Qual cuidado faz mais sentido para você?</h2>
        <p class="helper">
            Preparamos {{ count($options) }} opções com cobertura crescente. Compare o que está incluído em cada
            uma e escolha com calma: você vê o orçamento detalhado antes de aprovar.
        </p>
    @endif
</section>

<section class="options-grid">
    @foreach ($options as $index => $option)
        @php
            $optionLevel = (int) ($option['nivel'] ?? 0);
            $optionLabel = (string) ($option['label'] ?? '');
            $optionAll = is_array($option['itens'] ?? null) ? $option['itens'] : [];
            $previousLabel = $index > 0 ? (string) ($options[$index - 1]['label'] ?? '') : '';
            $optionUrl = route('budgets.public.show', ['token' => $optionToken, 'opcao' => $optionLevel]);
        @endphp
        @php
            $isAutoSuggested = ! empty($option['sugerido_automaticamente']);
            $isRecommended = ! empty($option['recomendado']) && ! $isAutoSuggested;
            $isApproved = $readOnly && $approvedLevel > 0 && $optionLevel === $approvedLevel;
            $isNotChosen = $readOnly && ! $isApproved;
            $isFeatured = $readOnly ? $isApproved : ($isRecommended || $isAutoSuggested);
            // Identidade visual de cada degrau da escada — sinal à parte do
            // selo de recomendado/mais escolhida (que agora é um badge
            // flutuante, não a cor do cabeçalho; ver CSS .option-badge).
            $isPremiumTier = $optionLevel === $optionCoverageMax && count($options) > 1;
            $isBasicTier = $optionLevel === $optionCoverageMin && count($options) > 1;
            $isMidTier = ! $isPremiumTier && ! $isBasicTier && count($options) > 1;
            $optionTerms = is_array($option['condicoes_comerciais'] ?? null) ? $option['condicoes_comerciais'] : [];
            $optionTotal = (float) ($option['total'] ?? 0);
            // "ou 7x de R$ 163,71 sem juros": a parcela daquela opção (o
            // parcelamento pode variar por nível) — o número grande deixa de
            // assustar. Convenção de loja: sem "aprox.".
            $optionInstallments = (int) ($optionTerms['parcelas_sem_juros'] ?? 0);
            $optionInstallmentValue = $optionInstallments > 1 && $optionTotal > 0 ? round($optionTotal / $optionInstallments, 2) : null;
            // Âncora de upsell: quanto a mais em relação ao cartão anterior visível.
            $previousTotal = $index > 0 ? (float) ($options[$index - 1]['total'] ?? 0) : 0.0;
            $optionDelta = $index > 0 && $optionTotal - $previousTotal > 0.009 ? $optionTotal - $previousTotal : null;
            $optionIcon = match ($optionLevel) { 1 => 'wrench', 2 => 'shield', default => 'sparkles' };
        @endphp
        <article class="card option-card {{ $isApproved ? 'is-recommended is-approved' : ($isNotChosen ? 'is-not-chosen' : ($isRecommended ? 'is-recommended' : ($isAutoSuggested ? 'is-suggested' : ''))) }} {{ $isPremiumTier ? 'is-premium' : '' }} {{ $isBasicTier ? 'is-basic' : '' }} {{ $isMidTier ? 'is-mid' : '' }}">
            @if ($isApproved)
                <span class="option-badge option-badge-approved">Sua escolha</span>
            @elseif ($isRecommended)
                <span class="option-badge">Recomendado</span>
            @elseif ($isAutoSuggested)
                <span class="option-badge option-badge-auto">Mais escolhida</span>
            @endif
            {{-- Cada bloco abaixo é um "slot" de linha do subgrid (telas largas):
                 todo cartão precisa ter os mesmos 8 filhos diretos, na mesma
                 ordem, mesmo quando o conteúdo de um deles fica vazio —
                 senão a linha de um nível deixa de alinhar com a dos outros. --}}
            <div class="option-header">
                <span class="option-icon" aria-hidden="true">{!! $icon($optionIcon, 20) !!}</span>
                <div>
                    <h3 class="option-name">{{ $shortLabel($optionLabel) }}</h3>
                    @if (($option['subtitle'] ?? '') !== '')
                        <p class="option-tagline">{{ $option['subtitle'] }}</p>
                    @endif
                </div>
            </div>
            <div class="coverage" role="img" aria-label="Cobertura {{ $optionLevel }} de {{ $optionCoverageMax }}">
                @for ($segment = 1; $segment <= $optionCoverageMax; $segment++)
                    <span class="coverage-segment {{ $segment <= $optionLevel ? 'is-filled' : '' }}"></span>
                @endfor
            </div>
            <div class="option-price">
                <div class="option-total">{{ $formatMoney($optionTotal) }}</div>
                @if ($optionInstallmentValue !== null)
                    <p class="option-installment">ou {{ $optionInstallments }}x de {{ $formatMoney($optionInstallmentValue) }} sem juros</p>
                @endif
                @if ($optionDelta !== null && $previousLabel !== '')
                    <p class="option-delta">+ {{ $formatMoney($optionDelta) }} em relação à {{ $shortLabel($previousLabel) }}</p>
                @endif
            </div>
            @if ($isApproved)
                <div class="option-cta-static">{!! $icon('check', 18) !!}Opção aprovada</div>
            @elseif ($isNotChosen)
                <div class="option-cta-static">Não escolhida</div>
            @else
                <a class="btn {{ $isFeatured ? 'btn-primary' : 'btn-outline-primary' }} option-cta" href="{{ $optionUrl }}">Escolher esta opção<span class="visually-hidden"> — {{ $optionLabel !== '' ? $optionLabel : ('opção ' . $optionLevel) }}</span></a>
            @endif

            <div class="option-list-block">
                <hr class="option-divider">
                <p class="option-list-heading">Itens desta opção</p>
            </div>
            <ul class="option-list">
                @forelse ($optionAll as $descricao)
                    <li>{{ $descricao }}</li>
                @empty
                    <li class="option-list-empty">Nenhum item nesta opção.</li>
                @endforelse
            </ul>
            {{-- Sem "ver mais" — a lista acima já é completa e literal. O slot
                 fica vazio só pra manter os mesmos 8 filhos diretos do subgrid
                 (ver comentário no topo do cartão). --}}
            <div class="option-more-slot"></div>

            @php
                $optionPerks = [];
                if ($termPerOption('entrega_domicilio') && ! empty($optionTerms['entrega_domicilio'])) {
                    $optionPerks[] = ['truck', (string) ($optionTerms['entrega_domicilio_label'] ?? 'Entrega no seu endereço')];
                }
                foreach (is_array($optionTerms['beneficios'] ?? null) ? $optionTerms['beneficios'] : [] as $beneficio) {
                    $optionPerks[] = ['gift', (string) $beneficio];
                }
                if ($termPerOption('garantia') && ($optionTerms['garantia_label'] ?? '') !== '') {
                    $optionPerks[] = ['shield', 'Garantia de '.$optionTerms['garantia_label']];
                }
                if ($termPerOption('parcelamento') && ($optionTerms['parcelamento_texto'] ?? '') !== '') {
                    $optionPerks[] = ['card', rtrim((string) $optionTerms['parcelamento_texto'], '.')];
                }
                if ($termPerOption('formas_pagamento') && ($optionTerms['formas_pagamento_texto'] ?? '') !== '') {
                    $optionPerks[] = ['wallet', 'Pagamento: '.$optionTerms['formas_pagamento_texto']];
                }
            @endphp
            <div class="option-perks-slot">
                @if ($optionPerks !== [])
                    <p class="option-list-heading option-perks-heading">Vantagens desta opção</p>
                    <ul class="option-list option-perks">
                        @foreach ($optionPerks as [$perkIcon, $perkText])
                            <li><span class="perk-icon" aria-hidden="true">{!! $icon($perkIcon, 16) !!}</span><span>{{ $perkText }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </article>
    @endforeach
</section>

@if ($readOnly)
    <div class="options-back">
        <a class="btn btn-primary" href="{{ route('budgets.public.show', ['token' => $optionToken]) }}">Voltar ao orçamento aprovado</a>
    </div>
@elseif ($optionValidity !== '')
    <p class="helper options-validity">
        Este orçamento é válido até {{ $optionValidity }}. Depois dessa data, os valores podem ser reajustados.
    </p>
@endif

@php
    $sharedGarantia = $termShared('garantia');
    $sharedFormas = $termShared('formas_pagamento');
    $sharedParcelamento = $termShared('parcelamento');
    $sharedEntrega = $termShared('entrega_domicilio');
    $anyGarantia = collect($options)->contains(static fn (array $option): bool => (($option['condicoes_comerciais']['garantia_label'] ?? '') !== ''));

    // Faixa de confiança: o que vale para todas as opções (dito uma vez) +
    // duas garantias do próprio processo. Frases das condições compartilhadas
    // são as mesmas de antes — só mudam de lugar.
    $trustItems = [];
    if ($sharedGarantia !== '') {
        $trustItems[] = ['shield', 'Garantia de '.$sharedGarantia.' em qualquer opção escolhida.'];
    } elseif ($anyGarantia) {
        $trustItems[] = ['shield', 'Garantia em todas as opções — veja o prazo em cada uma.'];
    }
    if ($sharedEntrega !== '') {
        $trustItems[] = ['truck', 'Entrega do equipamento no seu endereço em qualquer opção.'];
    }
    if ($sharedParcelamento !== '') {
        $trustItems[] = ['card', $sharedParcelamento];
    }
    // Selo de NFS-e: computado no backend (BudgetApprovalService), já
    // combinando a marcação do orçamento com o teto de faturamento do MEI —
    // aqui só lê o resultado, sem repetir a regra.
    if (($budget['mostrar_selo_nota_fiscal'] ?? false) === true) {
        $trustItems[] = ['receipt', 'Emissão de nota fiscal de serviço (NFS-e) em qualquer opção escolhida.'];
    }
    $trustItems[] = ['lock', 'Peças e mão de obra já incluídas no valor de cada opção.'];
    if (! $readOnly) {
        $trustItems[] = ['eye', 'Você aprova só depois de ver o orçamento completo.'];
    }
@endphp
<section class="trust-strip" aria-label="Por que escolher com tranquilidade">
    @foreach ($trustItems as [$trustIcon, $trustText])
        <div class="trust-item">
            <span class="trust-icon" aria-hidden="true">{!! $icon($trustIcon, 20) !!}</span>
            <span>{{ $trustText }}</span>
        </div>
    @endforeach
</section>

<section class="card options-footer">
    @if ($sharedFormas !== '')
        <p class="helper">Formas de pagamento: {{ $sharedFormas }}.</p>
    @endif

    <div class="footer-cta">
        <div>
            <p class="footer-cta-title">Ficou com alguma dúvida?</p>
            <p class="helper">{{ $readOnly ? 'Quer mudar de opção ou entender alguma diferença? É só falar com a gente.' : 'Fale com a gente antes de decidir — sem compromisso.' }}</p>
        </div>
        @if ($whatsappUrl !== '')
            <a class="btn-whatsapp" href="{{ $whatsappUrl }}" target="_blank" rel="noopener">{!! $icon('whatsapp', 18) !!}Falar com a gente no WhatsApp</a>
        @endif
    </div>

    @if (! $readOnly)
    <details class="options-reject">
        <summary>Nenhuma das opções serve? Recusar proposta</summary>
        <form
            id="formRejeitarProposta"
            method="post"
            action="{{ route('budgets.public.reject', ['token' => $optionToken]) }}"
        >
            @csrf
            <label class="meta-label" for="motivoRejeicao">Se desejar, informe o motivo da rejeição</label>
            <textarea id="motivoRejeicao" name="motivo_rejeicao" placeholder="Ex.: vou avaliar outra alternativa, preciso rever o valor, não autorizo neste momento..."></textarea>
            @error('motivo_rejeicao')
                <p class="helper danger-text">{{ $message }}</p>
            @enderror
            <div class="decision-actions">
                <button type="submit" class="btn btn-danger">Rejeitar proposta</button>
            </div>
        </form>
    </details>
    @endif
</section>
