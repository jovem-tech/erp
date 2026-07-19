@extends('layouts.app')

@section('content')
    @php
        $requestId = (int) ($pending['id'] ?? 0);
        $orderId = (int) ($pending['order_id'] ?? 0);
    @endphp

    <div class="signature-review-page">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <span class="summary-card-eyebrow">Revisão obrigatória</span>
                <h1 class="page-title mb-1">Visualize e analise antes de assinar</h1>
                <p class="text-secondary mb-0">
                    {{ $pending['order_number'] ?? 'OS' }} · {{ $pending['document_type'] ?? 'Documento' }}
                </p>
            </div>
            <a href="{{ route('orders.documents.center', $orderId) }}#assinaturas-pendentes" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Voltar aos documentos
            </a>
        </div>

        <div class="signature-review-steps" aria-label="Etapas da assinatura">
            <span class="is-current"><strong>1</strong> Visualizar</span>
            <span class="is-current"><strong>2</strong> Analisar</span>
            <span><strong>3</strong> Assinar e emitir</span>
        </div>

        <article class="surface-card signature-review-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="surface-title fs-5 mb-1">Prévia completa do documento</h2>
                    <p class="surface-subtitle mb-0">A prévia não contém a assinatura. Confira todas as páginas e informações.</p>
                </div>
                <span class="desktop-chip" data-review-status>Carregando prévia…</span>
            </div>

            <iframe
                class="signature-review-frame"
                src="{{ route('document-signatures.preview', $requestId) }}"
                title="Prévia completa do documento aguardando assinatura"
                data-signature-review-frame
            ></iframe>
        </article>

        <article class="surface-card signature-review-confirmation">
            <div>
                <h2 class="surface-title fs-5 mb-1">Confirmar análise e assinar</h2>
                <p class="surface-subtitle mb-0">Responsável: {{ $pending['responsible_user'] ?? 'Não informado' }}. A data da assinatura será registrada no momento da emissão.</p>
            </div>

            <form method="post" action="{{ route('document-signatures.sign', $requestId) }}" class="d-grid gap-3" data-signature-review-form>
                @csrf
                <input type="hidden" name="order_id" value="{{ $orderId }}">

                @unless ($isResponsible)
                    <div class="desktop-grid desktop-grid-two">
                        <div>
                            <label for="signatureReviewEmail">E-mail do responsável</label>
                            <input id="signatureReviewEmail" type="email" name="signature_email" class="form-control" value="{{ $pending['responsible_email'] ?? '' }}" autocomplete="username" readonly required>
                        </div>
                        <div>
                            <label for="signatureReviewPassword">Senha do responsável</label>
                            <input id="signatureReviewPassword" type="password" name="signature_password" class="form-control" maxlength="200" autocomplete="current-password" required>
                        </div>
                    </div>
                @endunless

                <label class="signature-review-consent">
                    <input type="checkbox" name="review_confirmed" value="1" required disabled data-review-confirmation>
                    <span>Li e analisei todas as páginas deste documento e confirmo que as informações estão corretas para assinatura.</span>
                </label>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary" disabled data-review-submit>
                        <i class="bi bi-pen me-2"></i>Assinar e emitir documento
                    </button>
                </div>
            </form>
        </article>
    </div>

    <style>
        .signature-review-page { max-width: 1500px; margin: 0 auto; }
        .signature-review-steps { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; }
        .signature-review-steps span { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border: 1px solid #d7e2f2; border-radius: 999px; color: #6b7b92; background: #fff; font-weight: 700; }
        .signature-review-steps strong { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: #eef4fc; color: #376fbd; }
        .signature-review-steps .is-current { color: #15355c; border-color: #8fb4e7; background: #f6f9ff; }
        .signature-review-card { padding: 18px; }
        .signature-review-frame { display: block; width: 100%; height: 72vh; min-height: 620px; border: 1px solid #cbd9ec; border-radius: 16px; background: #eef2f7; }
        .signature-review-confirmation { display: grid; gap: 18px; margin-top: 18px; }
        .signature-review-consent { display: flex; align-items: flex-start; gap: 12px; padding: 16px; border: 1px solid #cbd9ec; border-radius: 14px; background: #f8fbff; cursor: pointer; }
        .signature-review-consent input { margin-top: 3px; width: 18px; height: 18px; flex: 0 0 auto; }
        @media (max-width: 767px) {
            .signature-review-frame { height: 62vh; min-height: 480px; }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const frame = document.querySelector('[data-signature-review-frame]');
            const confirmation = document.querySelector('[data-review-confirmation]');
            const submit = document.querySelector('[data-review-submit]');
            const status = document.querySelector('[data-review-status]');
            if (!frame || !confirmation || !submit) return;

            const synchronize = () => { submit.disabled = !confirmation.checked || confirmation.disabled; };
            frame.addEventListener('load', () => {
                confirmation.disabled = false;
                if (status) status.textContent = 'Prévia carregada';
                synchronize();
            }, { once: true });
            confirmation.addEventListener('change', synchronize);
        });
    </script>
@endsection
