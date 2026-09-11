<?php

namespace App\Observers;

use App\Models\FinanceiroAnexo;
use App\Services\Files\FinanceiroAnexoCatalogService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Cataloga cada anexo financeiro (boleto/fatura/comprovante) no File Manager
 * central assim que é gravado — mesmo padrão de OrderDocumentFileObserver,
 * para o arquivo aparecer em Gerenciador de Arquivos > Anexos financeiros
 * sem duplicar a gravação em disco (o arquivo já foi salvo por
 * FinanceiroAnexoService::anexar(); aqui só se registra o que já existe).
 */
class FinanceiroAnexoObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly FinanceiroAnexoCatalogService $catalog) {}

    public function created(FinanceiroAnexo $anexo): void
    {
        try {
            $this->catalog->synchronize($anexo);
        } catch (\Throwable $exception) {
            Log::warning('[FILE_MANAGER][FINANCEIRO] Anexo preservado com sincronização pendente.', [
                'anexo_id' => (int) $anexo->id,
                'error_type' => $exception::class,
            ]);
            report($exception);
        }
    }
}
