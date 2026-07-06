<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairBrokenMigrationFile();
        $this->applySourceUpdates();
    }

    public function down(): void
    {
        $this->revertSourceUpdates();
    }

    private function repairBrokenMigrationFile(): void
    {
        $migrationPath = base_path('database/migrations/2026_07_04_000001_compact_budget_review_modal_layout.php');

        $content = file_get_contents($migrationPath);
        if ($content === false) {
            return;
        }

        $content = str_replace(
            '        }\n+    }\n+};\n*** End Patch',
            "        }\n    }\n};",
            $content
        );

        file_put_contents($migrationPath, $content);
    }

    private function applySourceUpdates(): void
    {
        $formPath = base_path('frontends/desktop/resources/views/orcamentos/form.blade.php');
        $cssPath = base_path('frontends/desktop/public/assets/css/desktop.css');

        $form = file_get_contents($formPath);
        if ($form !== false) {
            $form = str_replace(
                '                        Salvar sem envio mantem o orcamento registrado internamente. Enviar para aprovacao gera o PDF e tenta disparar a proposta para o cliente.',
                '                        <strong>Salvar sem envio</strong>' . PHP_EOL
                . '                        <span>mantém o orçamento interno.</span>' . PHP_EOL
                . '                        <span>Enviar para aprovação gera o PDF do cliente.</span>',
                $form
            );

            file_put_contents($formPath, $form);
        }

        $css = file_get_contents($cssPath);
        if ($css !== false) {
            $css = str_replace(
                ".budget-review-modal .modal-header,\n.budget-review-modal .modal-body,\n.budget-review-modal .modal-footer {\n    padding-left: 1.5rem;\n    padding-right: 1.5rem;\n}\n\n.budget-review-grid {\n    display: grid;\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n    gap: 1rem;\n    margin-bottom: 1rem;\n}\n\n.budget-review-grid-bottom {\n    margin-bottom: 0;\n}\n\n.budget-review-card {\n    display: grid;\n    gap: 0.95rem;\n    padding: 1rem 1.05rem;\n    border-radius: 20px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: rgba(248, 250, 252, 0.9);\n}\n\n.budget-review-card-head {\n    display: flex;\n    flex-wrap: wrap;\n    justify-content: space-between;\n    align-items: center;\n    gap: 0.75rem;\n}\n\n.budget-review-card-head h5 {\n    margin: 0;\n    font-size: 1rem;\n    color: var(--desktop-heading);\n}\n\n.budget-review-list,\n.budget-review-totals,\n.budget-review-notes,\n.budget-review-items {\n    display: grid;\n    gap: 0.75rem;\n}\n\n.budget-review-list-item,\n.budget-review-note-block {\n    display: grid;\n    gap: 0.28rem;\n    padding: 0.8rem 0.9rem;\n    border-radius: 16px;\n    border: 1px solid rgba(148, 163, 184, 0.12);\n    background: #fff;\n}\n\n.budget-review-list-item span,\n.budget-review-note-block span,\n.budget-review-item-head span,\n.budget-review-item-meta span {\n    color: var(--desktop-text-soft);\n    font-size: 0.82rem;\n    font-weight: 700;\n    letter-spacing: 0.02em;\n}\n\n.budget-review-list-item strong,\n.budget-review-note-block strong {\n    color: var(--desktop-heading);\n    font-size: 0.96rem;\n    overflow-wrap: anywhere;\n}\n\n.budget-review-item {\n    display: grid;\n    gap: 0.7rem;\n    padding: 0.95rem 1rem;\n    border-radius: 18px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: #fff;\n}\n\n.budget-review-item-head,\n.budget-review-item-meta {\n    display: flex;\n    flex-wrap: wrap;\n    justify-content: space-between;\n    gap: 0.75rem;\n}\n\n.budget-review-item-head strong:last-child {\n    font-size: 1.02rem;\n}\n\n.budget-review-item-meta {\n    padding-top: 0.3rem;\n    border-top: 1px dashed rgba(148, 163, 184, 0.24);\n}\n\n.budget-review-footer-copy {\n    margin-right: auto;\n    max-width: 420px;\n    color: var(--desktop-text-soft);\n    font-size: 0.88rem;\n    line-height: 1.5;\n}\n",
                ".budget-review-modal .modal-header,\n.budget-review-modal .modal-body,\n.budget-review-modal .modal-footer {\n    padding-left: 1.35rem;\n    padding-right: 1.35rem;\n}\n\n.budget-review-modal .modal-footer {\n    align-items: center;\n    gap: 0.75rem 1rem;\n}\n\n.budget-review-grid {\n    display: grid;\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n    gap: 0.85rem;\n    margin-bottom: 0.85rem;\n    align-items: start;\n}\n\n.budget-review-grid-bottom {\n    margin-bottom: 0;\n}\n\n.budget-review-card {\n    display: grid;\n    align-self: start;\n    align-content: start;\n    gap: 0.8rem;\n    padding: 0.9rem 0.95rem;\n    border-radius: 20px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: rgba(248, 250, 252, 0.9);\n}\n\n.budget-review-card-head {\n    display: flex;\n    flex-wrap: wrap;\n    justify-content: space-between;\n    align-items: center;\n    gap: 0.65rem;\n}\n\n.budget-review-card-head h5 {\n    margin: 0;\n    font-size: 1rem;\n    color: var(--desktop-heading);\n}\n\n.budget-review-list,\n.budget-review-totals,\n.budget-review-notes,\n.budget-review-items {\n    display: grid;\n    gap: 0.6rem;\n    align-content: start;\n}\n\n.budget-review-list-item,\n.budget-review-note-block {\n    display: grid;\n    gap: 0.22rem;\n    padding: 0.72rem 0.82rem;\n    border-radius: 16px;\n    border: 1px solid rgba(148, 163, 184, 0.12);\n    background: #fff;\n}\n\n.budget-review-list-item span,\n.budget-review-note-block span,\n.budget-review-item-head span,\n.budget-review-item-meta span {\n    color: var(--desktop-text-soft);\n    font-size: 0.82rem;\n    font-weight: 700;\n    letter-spacing: 0.02em;\n}\n\n.budget-review-list-item strong,\n.budget-review-note-block strong {\n    color: var(--desktop-heading);\n    font-size: 0.94rem;\n    overflow-wrap: anywhere;\n}\n\n.budget-review-item {\n    display: grid;\n    gap: 0.6rem;\n    padding: 0.85rem 0.9rem;\n    border-radius: 18px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: #fff;\n}\n\n.budget-review-item-head,\n.budget-review-item-meta {\n    display: grid;\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n    gap: 0.45rem 0.85rem;\n    align-items: start;\n}\n\n.budget-review-item-head > div {\n    min-width: 0;\n}\n\n.budget-review-item-head strong:last-child {\n    font-size: 1.02rem;\n    text-align: right;\n}\n\n.budget-review-item-meta {\n    padding-top: 0.25rem;\n    border-top: 1px dashed rgba(148, 163, 184, 0.24);\n}\n\n.budget-review-item-meta span {\n    min-width: 0;\n}\n\n.budget-review-totals {\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n}\n\n.budget-review-totals .budget-review-list-item:last-child {\n    grid-column: 1 / -1;\n    padding: 0.88rem 0.95rem;\n    background: linear-gradient(180deg, rgba(111, 90, 252, 0.08), rgba(111, 90, 252, 0.04));\n    border-color: rgba(111, 90, 252, 0.18);\n}\n\n.budget-review-totals .budget-review-list-item:last-child strong {\n    font-size: 1.08rem;\n}\n\n.budget-review-notes {\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n}\n\n.budget-review-note-block:last-child {\n    grid-column: 1 / -1;\n}\n\n.budget-review-footer-copy {\n    display: grid;\n    gap: 0.12rem;\n    max-width: 320px;\n    color: var(--desktop-text-soft);\n    font-size: 0.82rem;\n    line-height: 1.35;\n}\n\n.budget-review-footer-copy strong {\n    font-size: 0.84rem;\n}\n",
                $css
            );

            $css = str_replace(
                "    .budget-review-grid {\n        grid-template-columns: 1fr;\n    }\n\n    .budget-item-line-primary,\n",
                "    .budget-review-grid {\n        grid-template-columns: 1fr;\n    }\n\n    .budget-review-totals,\n    .budget-review-notes,\n    .budget-review-item-head,\n    .budget-review-item-meta {\n        grid-template-columns: 1fr;\n    }\n\n    .budget-review-totals .budget-review-list-item:last-child,\n    .budget-review-note-block:last-child {\n        grid-column: auto;\n    }\n\n    .budget-review-item-head strong:last-child {\n        text-align: left;\n    }\n\n    .budget-item-line-primary,\n",
                $css
            );

            file_put_contents($cssPath, $css);
        }
    }

    private function revertSourceUpdates(): void
    {
        $formPath = base_path('frontends/desktop/resources/views/orcamentos/form.blade.php');
        $cssPath = base_path('frontends/desktop/public/assets/css/desktop.css');

        $form = file_get_contents($formPath);
        if ($form !== false) {
            $form = str_replace(
                '                        <strong>Salvar sem envio</strong>' . PHP_EOL
                . '                        <span>mantém o orçamento interno.</span>' . PHP_EOL
                . '                        <span>Enviar para aprovação gera o PDF do cliente.</span>',
                '                        Salvar sem envio mantem o orcamento registrado internamente. Enviar para aprovacao gera o PDF e tenta disparar a proposta para o cliente.',
                $form
            );

            file_put_contents($formPath, $form);
        }

        $css = file_get_contents($cssPath);
        if ($css !== false) {
            $css = str_replace(
                ".budget-review-modal .modal-header,\n.budget-review-modal .modal-body,\n.budget-review-modal .modal-footer {\n    padding-left: 1.35rem;\n    padding-right: 1.35rem;\n}\n\n.budget-review-modal .modal-footer {\n    align-items: center;\n    gap: 0.75rem 1rem;\n}\n\n.budget-review-grid {\n    display: grid;\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n    gap: 0.85rem;\n    margin-bottom: 0.85rem;\n    align-items: start;\n}\n\n.budget-review-grid-bottom {\n    margin-bottom: 0;\n}\n\n.budget-review-card {\n    display: grid;\n    align-self: start;\n    align-content: start;\n    gap: 0.8rem;\n    padding: 0.9rem 0.95rem;\n    border-radius: 20px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: rgba(248, 250, 252, 0.9);\n}\n\n.budget-review-card-head {\n    display: flex;\n    flex-wrap: wrap;\n    justify-content: space-between;\n    align-items: center;\n    gap: 0.65rem;\n}\n\n.budget-review-card-head h5 {\n    margin: 0;\n    font-size: 1rem;\n    color: var(--desktop-heading);\n}\n\n.budget-review-list,\n.budget-review-totals,\n.budget-review-notes,\n.budget-review-items {\n    display: grid;\n    gap: 0.6rem;\n    align-content: start;\n}\n\n.budget-review-list-item,\n.budget-review-note-block {\n    display: grid;\n    gap: 0.22rem;\n    padding: 0.72rem 0.82rem;\n    border-radius: 16px;\n    border: 1px solid rgba(148, 163, 184, 0.12);\n    background: #fff;\n}\n\n.budget-review-list-item span,\n.budget-review-note-block span,\n.budget-review-item-head span,\n.budget-review-item-meta span {\n    color: var(--desktop-text-soft);\n    font-size: 0.82rem;\n    font-weight: 700;\n    letter-spacing: 0.02em;\n}\n\n.budget-review-list-item strong,\n.budget-review-note-block strong {\n    color: var(--desktop-heading);\n    font-size: 0.94rem;\n    overflow-wrap: anywhere;\n}\n\n.budget-review-item {\n    display: grid;\n    gap: 0.6rem;\n    padding: 0.85rem 0.9rem;\n    border-radius: 18px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: #fff;\n}\n\n.budget-review-item-head,\n.budget-review-item-meta {\n    display: grid;\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n    gap: 0.45rem 0.85rem;\n    align-items: start;\n}\n\n.budget-review-item-head > div {\n    min-width: 0;\n}\n\n.budget-review-item-head strong:last-child {\n    font-size: 1.02rem;\n    text-align: right;\n}\n\n.budget-review-item-meta {\n    padding-top: 0.25rem;\n    border-top: 1px dashed rgba(148, 163, 184, 0.24);\n}\n\n.budget-review-item-meta span {\n    min-width: 0;\n}\n\n.budget-review-totals {\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n}\n\n.budget-review-totals .budget-review-list-item:last-child {\n    grid-column: 1 / -1;\n    padding: 0.88rem 0.95rem;\n    background: linear-gradient(180deg, rgba(111, 90, 252, 0.08), rgba(111, 90, 252, 0.04));\n    border-color: rgba(111, 90, 252, 0.18);\n}\n\n.budget-review-totals .budget-review-list-item:last-child strong {\n    font-size: 1.08rem;\n}\n\n.budget-review-notes {\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n}\n\n.budget-review-note-block:last-child {\n    grid-column: 1 / -1;\n}\n\n.budget-review-footer-copy {\n    display: grid;\n    gap: 0.12rem;\n    max-width: 320px;\n    color: var(--desktop-text-soft);\n    font-size: 0.82rem;\n    line-height: 1.35;\n}\n\n.budget-review-footer-copy strong {\n    font-size: 0.84rem;\n}\n",
                ".budget-review-modal .modal-header,\n.budget-review-modal .modal-body,\n.budget-review-modal .modal-footer {\n    padding-left: 1.5rem;\n    padding-right: 1.5rem;\n}\n\n.budget-review-grid {\n    display: grid;\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n    gap: 1rem;\n    margin-bottom: 1rem;\n}\n\n.budget-review-grid-bottom {\n    margin-bottom: 0;\n}\n\n.budget-review-card {\n    display: grid;\n    gap: 0.95rem;\n    padding: 1rem 1.05rem;\n    border-radius: 20px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: rgba(248, 250, 252, 0.9);\n}\n\n.budget-review-card-head {\n    display: flex;\n    flex-wrap: wrap;\n    justify-content: space-between;\n    align-items: center;\n    gap: 0.75rem;\n}\n\n.budget-review-card-head h5 {\n    margin: 0;\n    font-size: 1rem;\n    color: var(--desktop-heading);\n}\n\n.budget-review-list,\n.budget-review-totals,\n.budget-review-notes,\n.budget-review-items {\n    display: grid;\n    gap: 0.75rem;\n}\n\n.budget-review-list-item,\n.budget-review-note-block {\n    display: grid;\n    gap: 0.28rem;\n    padding: 0.8rem 0.9rem;\n    border-radius: 16px;\n    border: 1px solid rgba(148, 163, 184, 0.12);\n    background: #fff;\n}\n\n.budget-review-list-item span,\n.budget-review-note-block span,\n.budget-review-item-head span,\n.budget-review-item-meta span {\n    color: var(--desktop-text-soft);\n    font-size: 0.82rem;\n    font-weight: 700;\n    letter-spacing: 0.02em;\n}\n\n.budget-review-list-item strong,\n.budget-review-note-block strong {\n    color: var(--desktop-heading);\n    font-size: 0.96rem;\n    overflow-wrap: anywhere;\n}\n\n.budget-review-item {\n    display: grid;\n    gap: 0.7rem;\n    padding: 0.95rem 1rem;\n    border-radius: 18px;\n    border: 1px solid rgba(148, 163, 184, 0.18);\n    background: #fff;\n}\n\n.budget-review-item-head,\n.budget-review-item-meta {\n    display: flex;\n    flex-wrap: wrap;\n    justify-content: space-between;\n    gap: 0.75rem;\n}\n\n.budget-review-item-head strong:last-child {\n    font-size: 1.02rem;\n}\n\n.budget-review-item-meta {\n    padding-top: 0.3rem;\n    border-top: 1px dashed rgba(148, 163, 184, 0.24);\n}\n\n.budget-review-footer-copy {\n    margin-right: auto;\n    max-width: 420px;\n    color: var(--desktop-text-soft);\n    font-size: 0.88rem;\n    line-height: 1.5;\n}\n",
                $css
            );

            $css = str_replace(
                "    .budget-review-grid {\n        grid-template-columns: 1fr;\n    }\n\n    .budget-item-line-primary,\n",
                "    .budget-review-grid {\n        grid-template-columns: 1fr;\n    }\n\n    .budget-review-totals,\n    .budget-review-notes,\n    .budget-review-item-head,\n    .budget-review-item-meta {\n        grid-template-columns: 1fr;\n    }\n\n    .budget-review-totals .budget-review-list-item:last-child,\n    .budget-review-note-block:last-child {\n        grid-column: auto;\n    }\n\n    .budget-review-item-head strong:last-child {\n        text-align: left;\n    }\n\n    .budget-item-line-primary,\n",
                $css
            );

            file_put_contents($cssPath, $css);
        }
    }
};
