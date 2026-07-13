<?php

use App\Http\Controllers\Web\BudgetPublicController;
use App\Http\Controllers\Web\OrderDocumentPublicController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Notifications\FrontendPasswordResetNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Nao expor a welcome page do framework (revela versao do Laravel).
    // A raiz do backend e apenas um banner de servico para health checks manuais.
    return response()->json([
        'service' => 'sistema-erp-api',
        'status' => 'ok',
    ]);
});

Route::post('/webhooks/whatsapp', WhatsAppWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.whatsapp');

Route::get('/redefinir-senha/{token}', function (Request $request, string $token) {
    $email = (string) $request->query('email', '');

    return redirect()->away(
        FrontendPasswordResetNotification::resetUrlFor($email, $token)
    );
})
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('password.reset.redirect_to_desktop');

Route::get('/orcamento/{token}', [BudgetPublicController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('budgets.public.show');

Route::get('/orcamento/{token}/pdf', [BudgetPublicController::class, 'pdf'])
    ->middleware('throttle:60,1')
    ->name('budgets.public.pdf');

Route::post('/orcamento/{token}/aprovar', [BudgetPublicController::class, 'approve'])
    ->middleware(['throttle:30,1'])
    ->name('budgets.public.approve');

Route::post('/orcamento/{token}/rejeitar', [BudgetPublicController::class, 'reject'])
    ->middleware(['throttle:30,1'])
    ->name('budgets.public.reject');

Route::get('/documentos/compartilhados/{token}', [OrderDocumentPublicController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('orders.documents.public.show');

Route::get('/documentos/compartilhados/{token}/arquivos/{document}/{format}', [OrderDocumentPublicController::class, 'file'])
    ->whereNumber('document')
    ->whereIn('format', ['a4', '80mm'])
    ->middleware('throttle:120,1')
    ->name('orders.documents.public.file');
