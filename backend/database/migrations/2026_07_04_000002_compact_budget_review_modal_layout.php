<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const BROKEN_MIGRATION_FILE = 'database/migrations/2026_07_04_000001_compact_budget_review_modal_layout.php';

    private const BROKEN_TAIL = <<<'TXT'
        }\n+    }\n+};\n*** End Patch
TXT;

    private const FIXED_TAIL = <<<'TXT'
        }
    }
};
TXT;

    private const FORM_HEADER_SEARCH = <<<'HTML'
                        <p class="surface-subtitle mb-0">Revise os dados abaixo, veja as pendencias e escolha se deseja apenas salvar ou enviar o PDF para aprovacao do cliente.</p>
HTML;

    private const FORM_HEADER_REPLACE = <<<'HTML'
                        <p class="surface-subtitle mb-0">Revise os dados, confira as pendencias e escolha entre salvar ou enviar para aprovacao.</p>
HTML;

    private const FORM_PENDING_SEARCH = <<<'HTML'
                                <p class="mb-0">Voce ainda pode salvar o orcamento sem enviar o PDF neste momento.</p>
HTML;

    private const FORM_PENDING_REPLACE = <<<'HTML'
                                <p class="mb-0">Voce ainda pode salvar sem enviar o PDF agora.</p>
HTML;

    private const FORM_FOOTER_SEARCH = <<<'HTML'
                    <div class="budget-review-footer-copy">
                        Salvar sem envio mantem o orcamento registrado internamente. Enviar para aprovacao gera o PDF e tenta disparar a proposta para o cliente.
                    </div>
HTML;

    private const FORM_FOOTER_REPLACE = <<<'HTML'
                    <div class="budget-review-footer-copy">
                        Salvar sem envio mantem o orcamento interno. Enviar para aprovacao gera o PDF e a proposta do cliente.
                    </div>
HTML;

    private const CSS_MARKER = '/* Compact budget review modal */';

    private const CSS_APPEND = <<<'CSS'
/* Compact budget review modal */
.budget-review-modal .modal-header,
.budget-review-modal .modal-body,
.budget-review-modal .modal-footer {
    padding-left: 1.35rem;
    padding-right: 1.35rem;
}

.budget-review-modal .modal-body {
    padding-top: 1rem;
    padding-bottom: 1rem;
}

.budget-review-pendencies {
    gap: 0.7rem;
    padding: 0.85rem 0.95rem;
    margin-bottom: 0.85rem;
}

.budget-review-pendencies-head {
    gap: 0.65rem;
}

.budget-review-pendencies-head strong {
    display: block;
    line-height: 1.35;
}

.budget-review-pendencies-head p {
    font-size: 0.88rem;
    line-height: 1.45;
}

.budget-review-grid {
    gap: 0.85rem;
    margin-bottom: 0.85rem;
}

.budget-review-grid-bottom {
    grid-template-columns: minmax(0, 1.08fr) minmax(0, 0.92fr);
    align-items: start;
}

.budget-review-card {
    gap: 0.75rem;
    padding: 0.9rem 0.95rem;
}

.budget-review-card-head {
    align-items: flex-start;
    gap: 0.5rem 0.65rem;
}

.budget-review-card-head h5 {
    font-size: 0.96rem;
}

.budget-review-list,
.budget-review-totals,
.budget-review-notes,
.budget-review-items {
    gap: 0.6rem;
}

.budget-review-list-item,
.budget-review-note-block {
    padding: 0.72rem 0.82rem;
    border-radius: 14px;
}

.budget-review-list-item strong,
.budget-review-note-block strong {
    font-size: 0.94rem;
    line-height: 1.45;
}

.budget-review-totals {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.budget-review-totals .budget-review-list-item {
    min-height: 4.1rem;
}

.budget-review-item {
    gap: 0.6rem;
    padding: 0.8rem 0.9rem;
    border-radius: 16px;
}

.budget-review-item-head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
    gap: 0.3rem 0.7rem;
}

.budget-review-item-head > div {
    min-width: 0;
}

.budget-review-item-head strong:last-child {
    text-align: right;
    font-size: 0.98rem;
}

.budget-review-item-head span {
    display: block;
    margin-top: 0.18rem;
}

.budget-review-item-meta {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.45rem 0.6rem;
    padding-top: 0.4rem;
}

.budget-review-item-meta span {
    min-width: 0;
    line-height: 1.35;
}

.budget-review-item-notes {
    font-size: 0.92rem;
}

.budget-review-empty {
    padding: 0.85rem 0.95rem;
    border-radius: 14px;
}

.budget-review-footer-copy {
    max-width: 360px;
    font-size: 0.84rem;
    line-height: 1.42;
}

.budget-review-modal .modal-footer {
    gap: 0.6rem;
}

@media (max-width: 1280px) {
    .budget-review-grid-bottom {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 992px) {
    .budget-review-grid {
        grid-template-columns: 1fr;
    }

    .budget-review-totals {
        grid-template-columns: 1fr;
    }

    .budget-review-item-meta {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .budget-review-modal .modal-header,
    .budget-review-modal .modal-body,
    .budget-review-modal .modal-footer {
        padding-left: 1rem;
        padding-right: 1rem;
    }
}

@media (max-width: 768px) {
    .budget-review-item-head {
        grid-template-columns: 1fr;
    }

    .budget-review-item-head strong:last-child {
        text-align: left;
    }

    .budget-review-item-meta {
        grid-template-columns: 1fr;
    }

    .budget-review-footer-copy {
        max-width: none;
    }
}
CSS;

    public function up(): void
    {
        $this->repairBrokenMigration();
        $this->compactBudgetReviewModal();
    }

    public function down(): void
    {
        $this->restoreBudgetReviewModal();
    }

    private function repairBrokenMigration(): void
    {
        $path = $this->repoPath(self::BROKEN_MIGRATION_FILE);

        if (!is_file($path)) {
            return;
        }

        $content = file_get_contents($path);

        if ($content === false || !str_contains($content, self::BROKEN_TAIL)) {
            return;
        }

        $fixed = str_replace(self::BROKEN_TAIL, self::FIXED_TAIL, $content, $count);

        if ($count > 0) {
            file_put_contents($path, $fixed);
        }
    }

    private function compactBudgetReviewModal(): void
    {
        $formPath = $this->repoPath('frontends/desktop/resources/views/orcamentos/form.blade.php');
        $cssPath = $this->repoPath('frontends/desktop/public/assets/css/desktop.css');

        $form = file_get_contents($formPath);
        if ($form !== false) {
            $updatedForm = str_replace(
                [self::FORM_HEADER_SEARCH, self::FORM_PENDING_SEARCH, self::FORM_FOOTER_SEARCH],
                [self::FORM_HEADER_REPLACE, self::FORM_PENDING_REPLACE, self::FORM_FOOTER_REPLACE],
                $form,
                $count
            );

            if ($count > 0) {
                file_put_contents($formPath, $updatedForm);
            }
        }

        $css = file_get_contents($cssPath);
        if ($css !== false && !str_contains($css, self::CSS_MARKER)) {
            $css = rtrim($css) . "\n\n" . self::CSS_APPEND . "\n";
            file_put_contents($cssPath, $css);
        }
    }

    private function restoreBudgetReviewModal(): void
    {
        $formPath = $this->repoPath('frontends/desktop/resources/views/orcamentos/form.blade.php');
        $cssPath = $this->repoPath('frontends/desktop/public/assets/css/desktop.css');

        $form = file_get_contents($formPath);
        if ($form !== false) {
            $restoredForm = str_replace(
                [self::FORM_HEADER_REPLACE, self::FORM_PENDING_REPLACE, self::FORM_FOOTER_REPLACE],
                [self::FORM_HEADER_SEARCH, self::FORM_PENDING_SEARCH, self::FORM_FOOTER_SEARCH],
                $form,
                $count
            );

            if ($count > 0) {
                file_put_contents($formPath, $restoredForm);
            }
        }

        $css = file_get_contents($cssPath);
        if ($css !== false) {
            $css = str_replace("\n\n" . self::CSS_APPEND . "\n", "\n", $css, $count);
            if ($count > 0) {
                file_put_contents($cssPath, $css);
            }
        }
    }

    private function repoPath(string $relativePath): string
    {
        return dirname(base_path()) . '/' . ltrim($relativePath, '/');
    }
};
