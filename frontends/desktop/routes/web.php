<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BroadcastAuthController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DefectController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\FinanceiroCartaoController;
use App\Http\Controllers\FinanceiroCatalogController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\FinanceiroContaController;
use App\Http\Controllers\FinanceiroPrecificacaoController;
use App\Http\Controllers\FinanceiroMargemController;
use App\Http\Controllers\FinanceiroReportController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\AssistanceModelController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderStatusFlowController;
use App\Http\Controllers\PdfTemplateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportedDefectController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServicoController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsappTemplateController;
use App\Support\DesktopNavigation;
use App\Support\DesktopSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! DesktopSession::hasToken()) {
        return redirect()->route('login');
    }

    return redirect()->route(DesktopNavigation::firstAllowedRouteName());
});

Route::get('/branding/empresa/logo', [ConfigurationController::class, 'publicCompanyLogo'])
    ->name('branding.company.logo');
Route::get('/branding/login/background', [ConfigurationController::class, 'publicLoginBackground'])
    ->name('branding.login.background');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    Route::get('/esqueci-minha-senha', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/esqueci-minha-senha', [PasswordResetController::class, 'store'])->name('password.email');
    Route::get('/redefinir-senha/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/redefinir-senha', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware('desktop.auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::post('/logout/esquecer', [AuthController::class, 'destroyAndForget'])->name('logout.forget');

    Route::get('/buscar', [SearchController::class, 'index'])->name('search.index');
    Route::get('/buscar/sugestoes', [SearchController::class, 'suggest'])->name('search.suggest');

    Route::get('/notificacoes', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notificacoes/resumo', [NotificationController::class, 'summary'])->name('notifications.summary');
    Route::get('/notificacoes/{notification}/abrir', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notificacoes/lidas', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all');
    Route::post('/notificacoes/limpar-lidas', [NotificationController::class, 'clearRead'])->name('notifications.clear-read');

    Route::post('/broadcasting/auth', BroadcastAuthController::class)
        ->name('desktop.broadcasting.auth');

    Route::get('/perfil', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/perfil/configuracoes', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/perfil/senha', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/configuracoes/sistema', [ConfigurationController::class, 'system'])
        ->middleware('desktop.permission:configuracoes,visualizar')
        ->name('configurations.system.index');
    Route::post('/configuracoes/aparencia', [ConfigurationController::class, 'updateAppearance'])
        ->name('configurations.appearance.update');
    Route::post('/configuracoes/empresa', [ConfigurationController::class, 'updateCompany'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.company.update');
    Route::post('/configuracoes/sessao-seguranca', [ConfigurationController::class, 'updateSessionSecurity'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.session-security.update');
    Route::get('/configuracoes/empresa/logo', [ConfigurationController::class, 'companyLogo'])
        ->name('configurations.company.logo');
    Route::get('/configuracoes/integracoes', [ConfigurationController::class, 'integrations'])
        ->middleware('desktop.permission:configuracoes,visualizar')
        ->name('configurations.integrations.index');
    Route::get('/configuracoes/integracoes/ajuda', [ConfigurationController::class, 'help'])
        ->middleware('desktop.permission:configuracoes,visualizar')
        ->name('configurations.integrations.help');
    Route::match(['post', 'put'], '/configuracoes/integracoes', [ConfigurationController::class, 'update'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.update');
    Route::post('/configuracoes/integracoes/testar-conexao', [ConfigurationController::class, 'testConnection'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.test-connection');
    Route::post('/configuracoes/integracoes/enviar-teste', [ConfigurationController::class, 'sendTestMessage'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.send-test');
    Route::post('/configuracoes/integracoes/self-check-inbound', [ConfigurationController::class, 'selfCheckInbound'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.self-check');
    Route::post('/configuracoes/integracoes/pagamentos/testar-conexao', [ConfigurationController::class, 'testPaymentConnection'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.payments.test-connection');
    Route::post('/configuracoes/integracoes/email/enviar-teste', [ConfigurationController::class, 'sendEmailTest'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.email.send-test');
    Route::post('/configuracoes/integracoes/gateway/status', [ConfigurationController::class, 'gatewayStatus'])
        ->middleware('desktop.permission:configuracoes,visualizar')
        ->name('configurations.integrations.gateway-status');
    Route::post('/configuracoes/integracoes/gateway/qr', [ConfigurationController::class, 'gatewayQr'])
        ->middleware('desktop.permission:configuracoes,visualizar')
        ->name('configurations.integrations.gateway-qr');
    Route::post('/configuracoes/integracoes/gateway/restart', [ConfigurationController::class, 'gatewayRestart'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.gateway-restart');
    Route::post('/configuracoes/integracoes/gateway/logout', [ConfigurationController::class, 'gatewayLogout'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.gateway-logout');
    Route::post('/configuracoes/integracoes/gateway/start', [ConfigurationController::class, 'gatewayStart'])
        ->middleware('desktop.permission:configuracoes,editar')
        ->name('configurations.integrations.gateway-start');

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('desktop.permission:dashboard,visualizar')
        ->name('dashboard');
    Route::get('/dashboard/dados', [DashboardController::class, 'data'])
        ->middleware('desktop.permission:dashboard,visualizar')
        ->name('dashboard.data');
    Route::get('/dashboard/ajuda', [DashboardController::class, 'help'])
        ->middleware('desktop.permission:dashboard,visualizar')
        ->name('dashboard.help');

    Route::get('/orcamentos/ajuda', [OrcamentoController::class, 'help'])
        ->middleware('desktop.permission:orcamentos,visualizar')
        ->name('orcamentos.help');
    Route::get('/orcamentos', [OrcamentoController::class, 'index'])
        ->middleware('desktop.permission:orcamentos,visualizar')
        ->name('orcamentos.index');
    Route::get('/orcamentos/novo', [OrcamentoController::class, 'create'])
        ->middleware('desktop.permission:orcamentos,criar')
        ->name('orcamentos.create');
    Route::post('/orcamentos', [OrcamentoController::class, 'store'])
        ->middleware('desktop.permission:orcamentos,criar')
        ->name('orcamentos.store');
    Route::get('/orcamentos/{orcamento}', [OrcamentoController::class, 'show'])
        ->middleware('desktop.permission:orcamentos,visualizar')
        ->name('orcamentos.show');
    Route::get('/orcamentos/{orcamento}/editar', [OrcamentoController::class, 'edit'])
        ->middleware('desktop.permission:orcamentos,editar')
        ->name('orcamentos.edit');
    Route::match(['put', 'patch'], '/orcamentos/{orcamento}', [OrcamentoController::class, 'update'])
        ->middleware('desktop.permission:orcamentos,editar')
        ->name('orcamentos.update');
    Route::post('/orcamentos/{orcamento}/enviar-aprovacao', [OrcamentoController::class, 'sendApproval'])
        ->middleware('desktop.permission:orcamentos,editar')
        ->name('orcamentos.send_approval');
    Route::delete('/orcamentos/{orcamento}', [OrcamentoController::class, 'destroy'])
        ->middleware('desktop.permission:orcamentos,excluir')
        ->name('orcamentos.destroy');

    Route::get('/os/criar', [OrderController::class, 'create'])
        ->middleware('desktop.permission:os,criar')
        ->name('orders.create');
    Route::get('/os/clientes/buscar', [OrderController::class, 'searchClients'])
        ->middleware('desktop.permission:os,criar|editar')
        ->name('orders.clients.search');
    Route::get('/os/equipamentos/buscar', [OrderController::class, 'searchEquipments'])
        ->middleware('desktop.permission:os,criar|editar')
        ->name('orders.equipments.search');
    Route::get('/os/defeitos-relatados/buscar', [OrderController::class, 'searchReportedDefects'])
        ->middleware('desktop.permission:os,criar|editar')
        ->name('orders.reported-defects.search');
    Route::get('/os/checklists/entrada/modelos/{tipoEquipamento}', [OrderController::class, 'entryChecklistModel'])
        ->whereNumber('tipoEquipamento')
        ->middleware('desktop.permission:os,criar|editar')
        ->name('orders.entry-checklist.model');
    Route::post('/os', [OrderController::class, 'store'])
        ->middleware('desktop.permission:os,criar')
        ->name('orders.store');
    Route::get('/os/{order}/preview', [OrderController::class, 'preview'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.preview');
    Route::get('/os', [OrderController::class, 'index'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.index');
    Route::get('/os/{order}', [OrderController::class, 'show'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.show');
    Route::get('/os/{order}/historico', [OrderController::class, 'audit'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.audit');
    Route::get('/os/{order}/mapa', [OrderController::class, 'map'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.map');
    Route::get('/os/{order}/mapa/dados', [OrderController::class, 'mapData'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.map.data');
    Route::get('/os/{order}/editar', [OrderController::class, 'edit'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.edit');
    Route::match(['put', 'patch'], '/os/{order}', [OrderController::class, 'update'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.update');
    Route::get('/os/{order}/baixa', [OrderController::class, 'closureShow'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.closure.show');
    Route::post('/os/{order}/baixa', [OrderController::class, 'closureStore'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.closure.store');
    Route::post('/os/{order}/baixa/cancelar', [OrderController::class, 'closureCancel'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.closure.cancel');
    Route::get('/os/{order}/status-context', [OrderController::class, 'statusContext'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.status.context');
    Route::post('/os/{order}/status', [OrderController::class, 'updateStatus'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.status.update');
    Route::post('/os/{order}/procedimentos', [OrderController::class, 'storeProcedure'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.procedures.store');
    Route::get('/os/{order}/fotos/{photo}', [OrderController::class, 'photo'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.photos.show');
    Route::get('/os/{order}/documentos', [OrderController::class, 'documentsCenter'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.documents.center');
    Route::post('/os/{order}/documentos', [OrderController::class, 'documentsCenterDispatch'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.documents.dispatch');
    Route::post('/os/{order}/documentos/gerar', [OrderController::class, 'documentsCenterGenerate'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.documents.generate');
    Route::post('/os/{order}/documentos/enviar', [OrderController::class, 'documentsCenterSend'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.documents.send');
    Route::post('/os/{order}/documentos/links', [OrderController::class, 'documentsCenterShare'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.documents.share');
    Route::post('/os/{order}/documentos/links/{link}/revogar', [OrderController::class, 'documentsCenterRevokeLink'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.documents.share.revoke');
    Route::post('/os/{order}/documentos/{document}/arquivar', [OrderController::class, 'documentsCenterArchive'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.documents.archive');
    Route::post('/os/{order}/documentos/{document}/reativar', [OrderController::class, 'documentsCenterUnarchive'])
        ->middleware('desktop.permission:os,editar')
        ->name('orders.documents.unarchive');
    Route::get('/os/{order}/documentos/estado', [OrderController::class, 'documentsCenterState'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.documents.state');
    Route::get('/os/{order}/documentos/download', [OrderController::class, 'documentsCenterDownload'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.documents.download');
    Route::get('/os/{order}/documentos/imprimir', [OrderController::class, 'documentsCenterPrint'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.documents.print');
    Route::get('/os/{order}/documentos/{document}/arquivos/{format}', [OrderController::class, 'documentFormat'])
        ->middleware('desktop.permission:os,visualizar')
        ->whereIn('format', ['a4', '80mm'])
        ->name('orders.documents.files.show');
    Route::get('/os/{order}/documentos/{document}', [OrderController::class, 'document'])
        ->middleware('desktop.permission:os,visualizar')
        ->name('orders.documents.show');

    Route::get('/clientes', [ClientController::class, 'index'])
        ->middleware('desktop.permission:clientes,visualizar')
        ->name('clients.index');
    Route::get('/clientes/novo', [ClientController::class, 'create'])
        ->middleware('desktop.permission:clientes,criar')
        ->name('clients.create');
    Route::post('/clientes', [ClientController::class, 'store'])
        ->middleware('desktop.permission:clientes,criar')
        ->name('clients.store');
    Route::post('/clientes/rapido', [ClientController::class, 'quickStore'])
        ->middleware('desktop.permission:clientes,criar')
        ->name('clients.quick.store');
    Route::get('/clientes/{client}/editar', [ClientController::class, 'edit'])
        ->middleware('desktop.permission:clientes,editar')
        ->name('clients.edit');
    Route::match(['put', 'patch'], '/clientes/{client}', [ClientController::class, 'update'])
        ->middleware('desktop.permission:clientes,editar')
        ->name('clients.update');
    Route::get('/clientes/{client}', [ClientController::class, 'show'])
        ->middleware('desktop.permission:clientes,visualizar')
        ->name('clients.show');

    Route::get('/fornecedores', [SupplierController::class, 'index'])
        ->middleware('desktop.permission:fornecedores,visualizar')
        ->name('suppliers.index');
    Route::get('/fornecedores/ajuda', [SupplierController::class, 'help'])
        ->middleware('desktop.permission:fornecedores,visualizar')
        ->name('suppliers.help');
    Route::get('/fornecedores/novo', [SupplierController::class, 'create'])
        ->middleware('desktop.permission:fornecedores,criar')
        ->name('suppliers.create');
    Route::post('/fornecedores', [SupplierController::class, 'store'])
        ->middleware('desktop.permission:fornecedores,criar')
        ->name('suppliers.store');
    Route::get('/fornecedores/consultar-cnpj', [SupplierController::class, 'lookupCnpj'])
        ->middleware('desktop.permission:fornecedores,visualizar')
        ->name('suppliers.lookup-cnpj');
    Route::get('/fornecedores/{supplier}/editar', [SupplierController::class, 'edit'])
        ->middleware('desktop.permission:fornecedores,editar')
        ->name('suppliers.edit');
    Route::match(['put', 'patch'], '/fornecedores/{supplier}', [SupplierController::class, 'update'])
        ->middleware('desktop.permission:fornecedores,editar')
        ->name('suppliers.update');
    Route::patch('/fornecedores/{supplier}/encerrar', [SupplierController::class, 'close'])
        ->middleware('desktop.permission:fornecedores,encerrar')
        ->name('suppliers.close');
    Route::delete('/fornecedores/{supplier}', [SupplierController::class, 'destroy'])
        ->middleware('desktop.permission:fornecedores,excluir')
        ->name('suppliers.destroy');

    Route::get('/financeiro', [FinanceiroController::class, 'index'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.index');
    Route::get('/financeiro/clientes/buscar', [FinanceiroController::class, 'searchClients'])
        ->middleware('desktop.permission:financeiro,criar|editar')
        ->name('financeiro.clients.search');
    Route::get('/financeiro/novo', [FinanceiroController::class, 'create'])
        ->middleware('desktop.permission:financeiro,criar')
        ->name('financeiro.create');
    Route::post('/financeiro', [FinanceiroController::class, 'store'])
        ->middleware('desktop.permission:financeiro,criar')
        ->name('financeiro.store');
    Route::get('/financeiro/contas', [FinanceiroContaController::class, 'index'])
        ->middleware('desktop.permission:contas_saldos,visualizar')
        ->name('financeiro.contas.index');
    Route::post('/financeiro/contas', [FinanceiroContaController::class, 'store'])
        ->middleware('desktop.permission:contas_saldos,criar')
        ->name('financeiro.contas.store');
    Route::patch('/financeiro/contas/{conta}', [FinanceiroContaController::class, 'update'])
        ->middleware('desktop.permission:contas_saldos,editar')
        ->name('financeiro.contas.update');
    Route::get('/financeiro/contas/{conta}/extrato', [FinanceiroContaController::class, 'statement'])
        ->middleware('desktop.permission:contas_saldos,visualizar')
        ->name('financeiro.contas.extrato');
    Route::post('/financeiro/contas/{conta}/ajustes', [FinanceiroContaController::class, 'adjust'])
        ->middleware('desktop.permission:contas_saldos,editar')
        ->name('financeiro.contas.ajustes.store');
    Route::post('/financeiro/contas-transferencias', [FinanceiroContaController::class, 'transfer'])
        ->middleware('desktop.permission:contas_saldos,editar')
        ->name('financeiro.contas.transferencias.store');
    Route::post('/financeiro/contas-transferencias/{transferencia}/cancelar', [FinanceiroContaController::class, 'cancelTransfer'])
        ->middleware('desktop.permission:contas_saldos,editar')
        ->name('financeiro.contas.transferencias.cancelar');
    Route::post('/financeiro/contas-cartoes/{cartao}/confirmar', [FinanceiroContaController::class, 'confirmCard'])
        ->middleware('desktop.permission:contas_saldos,editar')
        ->name('financeiro.contas.cartoes.confirmar');
    Route::get('/financeiro/configuracoes', [FinanceiroCatalogController::class, 'index'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.configuracoes');
    Route::get('/financeiro/precificacao', [FinanceiroPrecificacaoController::class, 'index'])
        ->middleware('desktop.permission:precificacao,visualizar')
        ->name('financeiro.precificacao.index');
    Route::match(['post', 'put'], '/financeiro/precificacao', [FinanceiroPrecificacaoController::class, 'save'])
        ->middleware('desktop.permission:precificacao,editar')
        ->name('financeiro.precificacao.save');
    Route::post('/financeiro/precificacao/simular-peca', [FinanceiroPrecificacaoController::class, 'simulatePeca'])
        ->middleware('desktop.permission:precificacao,visualizar')
        ->name('financeiro.precificacao.simular-peca');
    Route::post('/financeiro/precificacao/simular-servico', [FinanceiroPrecificacaoController::class, 'simulateServico'])
        ->middleware('desktop.permission:precificacao,visualizar')
        ->name('financeiro.precificacao.simular-servico');
    Route::get('/financeiro/cartoes', [FinanceiroCartaoController::class, 'index'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.cartoes.index');
    Route::get('/financeiro/cartoes/ajuda', [FinanceiroCartaoController::class, 'help'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.cartoes.help');
    Route::post('/financeiro/cartoes/simular', [FinanceiroCartaoController::class, 'simulate'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.cartoes.simulate');
    Route::post('/financeiro/cartoes/operadoras', [FinanceiroCartaoController::class, 'saveOperadora'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.cartoes.operadoras.save');
    Route::delete('/financeiro/cartoes/operadoras/{operadora}', [FinanceiroCartaoController::class, 'destroyOperadora'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.cartoes.operadoras.delete');
    Route::post('/financeiro/cartoes/bandeiras', [FinanceiroCartaoController::class, 'saveBandeira'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.cartoes.bandeiras.save');
    Route::delete('/financeiro/cartoes/bandeiras/{bandeira}', [FinanceiroCartaoController::class, 'destroyBandeira'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.cartoes.bandeiras.delete');
    Route::post('/financeiro/cartoes/taxas', [FinanceiroCartaoController::class, 'saveTaxa'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.cartoes.taxas.save');
    Route::delete('/financeiro/cartoes/taxas/{taxa}', [FinanceiroCartaoController::class, 'destroyTaxa'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.cartoes.taxas.delete');
    Route::post('/financeiro/cartoes/taxas-online', [FinanceiroCartaoController::class, 'saveGatewayTaxa'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.cartoes.gateway.save');
    Route::delete('/financeiro/cartoes/taxas-online/{gatewayTaxa}', [FinanceiroCartaoController::class, 'destroyGatewayTaxa'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.cartoes.gateway.delete');
    Route::get('/financeiro/relatorios/dre', [FinanceiroReportController::class, 'dre'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.relatorios.dre');
    Route::get('/financeiro/relatorios/dre-caixa', [FinanceiroReportController::class, 'dreCaixa'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.relatorios.dre-caixa');
    Route::get('/financeiro/relatorios/fluxo-caixa', [FinanceiroReportController::class, 'fluxoCaixa'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.relatorios.fluxo-caixa');
    Route::post('/financeiro/configuracoes/categorias', [FinanceiroCatalogController::class, 'saveCategoria'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.configuracoes.categorias.save');
    Route::delete('/financeiro/configuracoes/categorias/{categoria}', [FinanceiroCatalogController::class, 'deleteCategoria'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.configuracoes.categorias.delete');
    Route::post('/financeiro/configuracoes/dre/grupos', [FinanceiroCatalogController::class, 'saveGrupo'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.configuracoes.grupos.save');
    Route::delete('/financeiro/configuracoes/dre/grupos/{grupo}', [FinanceiroCatalogController::class, 'deleteGrupo'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.configuracoes.grupos.delete');
    Route::post('/financeiro/configuracoes/dre/subgrupos', [FinanceiroCatalogController::class, 'saveSubgrupo'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.configuracoes.subgrupos.save');
    Route::delete('/financeiro/configuracoes/dre/subgrupos/{subgrupo}', [FinanceiroCatalogController::class, 'deleteSubgrupo'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.configuracoes.subgrupos.delete');
    Route::post('/financeiro/configuracoes/comissoes', [FinanceiroCatalogController::class, 'saveComissao'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.configuracoes.comissoes.save');
    Route::delete('/financeiro/configuracoes/comissoes/{comissao}', [FinanceiroCatalogController::class, 'deleteComissao'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.configuracoes.comissoes.delete');
    Route::post('/financeiro/configuracoes/comissoes-padrao', [FinanceiroCatalogController::class, 'saveComissaoPadrao'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.configuracoes.comissoes.padrao');
    Route::get('/financeiro/relatorios/margem', [FinanceiroMargemController::class, 'index'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.relatorios.margem');
    Route::get('/financeiro/{financeiro}', [FinanceiroController::class, 'show'])
        ->middleware('desktop.permission:financeiro,visualizar')
        ->name('financeiro.show');
    Route::get('/financeiro/{financeiro}/editar', [FinanceiroController::class, 'edit'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.edit');
    Route::match(['put', 'patch'], '/financeiro/{financeiro}', [FinanceiroController::class, 'update'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.update');
    Route::delete('/financeiro/{financeiro}', [FinanceiroController::class, 'destroy'])
        ->middleware('desktop.permission:financeiro,excluir')
        ->name('financeiro.destroy');
    Route::post('/financeiro/{financeiro}/baixar', [FinanceiroController::class, 'pay'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.pay');
    Route::post('/financeiro/{financeiro}/cancelar', [FinanceiroController::class, 'cancel'])
        ->middleware('desktop.permission:financeiro,editar')
        ->name('financeiro.cancel');

    Route::get('/conhecimento/defeitos-relatados', [ReportedDefectController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->name('knowledge.reported-defects.index');
    Route::get('/conhecimento/defeitos-relatados/novo', [ReportedDefectController::class, 'create'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.reported-defects.create');
    Route::post('/conhecimento/defeitos-relatados', [ReportedDefectController::class, 'store'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.reported-defects.store');
    Route::get('/conhecimento/defeitos-relatados/{defeito}/editar', [ReportedDefectController::class, 'edit'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.reported-defects.edit');
    Route::match(['put', 'patch'], '/conhecimento/defeitos-relatados/{defeito}', [ReportedDefectController::class, 'update'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.reported-defects.update');
    Route::patch('/conhecimento/defeitos-relatados/{defeito}/ativo', [ReportedDefectController::class, 'toggleActive'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.reported-defects.toggle-active');
    Route::delete('/conhecimento/defeitos-relatados/{defeito}', [ReportedDefectController::class, 'destroy'])
        ->middleware('desktop.permission:conhecimento,excluir')
        ->name('knowledge.reported-defects.destroy');

    Route::get('/conhecimento/defeitos', [DefectController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->name('knowledge.defects.index');
    Route::get('/conhecimento/defeitos/novo', [DefectController::class, 'create'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.defects.create');
    Route::post('/conhecimento/defeitos', [DefectController::class, 'store'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.defects.store');
    Route::get('/conhecimento/defeitos/{defeito}/editar', [DefectController::class, 'edit'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.defects.edit');
    Route::match(['put', 'patch'], '/conhecimento/defeitos/{defeito}', [DefectController::class, 'update'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.defects.update');
    Route::patch('/conhecimento/defeitos/{defeito}/ativo', [DefectController::class, 'toggleActive'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.defects.toggle-active');
    Route::delete('/conhecimento/defeitos/{defeito}', [DefectController::class, 'destroy'])
        ->middleware('desktop.permission:conhecimento,excluir')
        ->name('knowledge.defects.destroy');
    Route::post('/conhecimento/defeitos/{defeito}/procedimentos', [DefectController::class, 'storeProcedure'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.defects.procedures.store');
    Route::match(['put', 'patch'], '/conhecimento/defeitos/{defeito}/procedimentos/{procedimento}', [DefectController::class, 'updateProcedure'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.defects.procedures.update');
    Route::delete('/conhecimento/defeitos/{defeito}/procedimentos/{procedimento}', [DefectController::class, 'destroyProcedure'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.defects.procedures.destroy');
    Route::patch('/conhecimento/defeitos/{defeito}/procedimentos/{procedimento}/mover', [DefectController::class, 'moveProcedure'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.defects.procedures.move');

    Route::get('/conhecimento/modelos-pdf', [PdfTemplateController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->name('knowledge.pdf-templates.index');
    Route::get('/conhecimento/modelos-pdf/novo', [PdfTemplateController::class, 'create'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.pdf-templates.create');
    Route::post('/conhecimento/modelos-pdf', [PdfTemplateController::class, 'store'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.pdf-templates.store');
    Route::get('/conhecimento/modelos-pdf/{template}/editar', [PdfTemplateController::class, 'edit'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.pdf-templates.edit');
    Route::match(['put', 'patch'], '/conhecimento/modelos-pdf/{template}', [PdfTemplateController::class, 'update'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.pdf-templates.update');
    Route::patch('/conhecimento/modelos-pdf/{template}/ativo', [PdfTemplateController::class, 'toggleActive'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.pdf-templates.toggle-active');
    Route::delete('/conhecimento/modelos-pdf/{template}', [PdfTemplateController::class, 'destroy'])
        ->middleware('desktop.permission:conhecimento,excluir')
        ->name('knowledge.pdf-templates.destroy');

    Route::get('/conhecimento/templates-whatsapp', [WhatsappTemplateController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->name('knowledge.whatsapp-templates.index');
    Route::get('/conhecimento/templates-whatsapp/novo', [WhatsappTemplateController::class, 'create'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.whatsapp-templates.create');
    Route::post('/conhecimento/templates-whatsapp', [WhatsappTemplateController::class, 'store'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.whatsapp-templates.store');
    Route::get('/conhecimento/templates-whatsapp/{template}/editar', [WhatsappTemplateController::class, 'edit'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.whatsapp-templates.edit');
    Route::match(['put', 'patch'], '/conhecimento/templates-whatsapp/{template}', [WhatsappTemplateController::class, 'update'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.whatsapp-templates.update');
    Route::patch('/conhecimento/templates-whatsapp/{template}/ativo', [WhatsappTemplateController::class, 'toggleActive'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.whatsapp-templates.toggle-active');
    Route::delete('/conhecimento/templates-whatsapp/{template}', [WhatsappTemplateController::class, 'destroy'])
        ->middleware('desktop.permission:conhecimento,excluir')
        ->name('knowledge.whatsapp-templates.destroy');

    Route::get('/conhecimento/checklists/entrada', [ChecklistController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->defaults('tipo', 'entrada')
        ->name('knowledge.checklists.entrada');
    Route::get('/conhecimento/checklists/manutencao', [ChecklistController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->defaults('tipo', 'manutencao')
        ->name('knowledge.checklists.manutencao');
    Route::get('/conhecimento/checklists/controle-qualidade', [ChecklistController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->defaults('tipo', 'controle_qualidade')
        ->name('knowledge.checklists.controle-qualidade');
    Route::get('/conhecimento/checklists/saida', [ChecklistController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->defaults('tipo', 'saida')
        ->name('knowledge.checklists.saida');

    Route::get('/conhecimento/checklists/{tipo}/modelos/{tipoEquipamento}', [ChecklistController::class, 'showModelo'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('tipoEquipamento')
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->name('knowledge.checklists.modelo.show');
    Route::post('/conhecimento/checklists/{tipo}/modelos/{tipoEquipamento}', [ChecklistController::class, 'storeModelo'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('tipoEquipamento')
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.checklists.modelo.store');
    Route::match(['put', 'patch'], '/conhecimento/checklists/{tipo}/modelos/{modelo}', [ChecklistController::class, 'updateModelo'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.checklists.modelo.update');
    Route::delete('/conhecimento/checklists/{tipo}/modelos/{modelo}', [ChecklistController::class, 'destroyModelo'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')
        ->middleware('desktop.permission:conhecimento,excluir')
        ->name('knowledge.checklists.modelo.destroy');
    Route::post('/conhecimento/checklists/{tipo}/modelos/{modelo}/itens', [ChecklistController::class, 'storeItem'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.checklists.items.store');
    Route::match(['put', 'patch'], '/conhecimento/checklists/{tipo}/modelos/{modelo}/itens/{item}', [ChecklistController::class, 'updateItem'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.checklists.items.update');
    Route::delete('/conhecimento/checklists/{tipo}/modelos/{modelo}/itens/{item}', [ChecklistController::class, 'destroyItem'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.checklists.items.destroy');
    Route::patch('/conhecimento/checklists/{tipo}/modelos/{modelo}/itens/{item}/mover', [ChecklistController::class, 'moveItem'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.checklists.items.move');
    Route::patch('/conhecimento/checklists/{tipo}/modelos/{modelo}/itens/{item}/ativo', [ChecklistController::class, 'toggleItemActive'])
        ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.checklists.items.toggle-active');

    Route::get('/conhecimento/fluxo-os', [OrderStatusFlowController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->name('knowledge.os-flow.index');
    Route::get('/conhecimento/modelo-assistencia-tecnica', [AssistanceModelController::class, 'index'])
        ->middleware('desktop.permission:conhecimento,visualizar')
        ->name('knowledge.assistance-model.index');
    Route::post('/conhecimento/fluxo-os/status', [OrderStatusFlowController::class, 'storeStatus'])
        ->middleware('desktop.permission:conhecimento,criar')
        ->name('knowledge.os-flow.status.store');
    Route::match(['put', 'patch'], '/conhecimento/fluxo-os/status/{status}', [OrderStatusFlowController::class, 'updateStatus'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.os-flow.status.update');
    Route::match(['put', 'patch'], '/conhecimento/fluxo-os/transicoes', [OrderStatusFlowController::class, 'updateTransitions'])
        ->middleware('desktop.permission:conhecimento,editar')
        ->name('knowledge.os-flow.transitions.update');

    Route::get('/servicos', [ServicoController::class, 'index'])
        ->middleware('desktop.permission:servicos,visualizar')
        ->name('servicos.index');
    Route::get('/servicos/ajuda', [ServicoController::class, 'help'])
        ->middleware('desktop.permission:servicos,visualizar')
        ->name('servicos.help');
    Route::get('/servicos/novo', [ServicoController::class, 'create'])
        ->middleware('desktop.permission:servicos,criar')
        ->name('servicos.create');
    Route::post('/servicos', [ServicoController::class, 'store'])
        ->middleware('desktop.permission:servicos,criar')
        ->name('servicos.store');
    Route::post('/servicos/rapido', [ServicoController::class, 'quickStore'])
        ->middleware('desktop.permission:servicos,criar')
        ->name('servicos.quick.store');
    Route::get('/servicos/exportar-csv', [ServicoController::class, 'exportCsv'])
        ->middleware('desktop.permission:servicos,exportar')
        ->name('servicos.export.csv');
    Route::get('/servicos/modelo-importacao.csv', [ServicoController::class, 'downloadCsvTemplate'])
        ->middleware('desktop.permission:servicos,importar')
        ->name('servicos.download-template');
    Route::post('/servicos/importar-lote', [ServicoController::class, 'importCsv'])
        ->middleware('desktop.permission:servicos,importar')
        ->name('servicos.import');
    Route::get('/servicos/{service}/editar', [ServicoController::class, 'edit'])
        ->middleware('desktop.permission:servicos,editar')
        ->name('servicos.edit');
    Route::match(['put', 'patch'], '/servicos/{service}', [ServicoController::class, 'update'])
        ->middleware('desktop.permission:servicos,editar')
        ->name('servicos.update');
    Route::patch('/servicos/{service}/encerrar', [ServicoController::class, 'close'])
        ->middleware('desktop.permission:servicos,encerrar')
        ->name('servicos.close');
    Route::delete('/servicos/{service}', [ServicoController::class, 'destroy'])
        ->middleware('desktop.permission:servicos,excluir')
        ->name('servicos.destroy');

    Route::get('/estoque', [StockController::class, 'index'])
        ->middleware('desktop.permission:estoque,visualizar')
        ->name('estoque.index');
    Route::get('/estoque/ajuda', [StockController::class, 'help'])
        ->middleware('desktop.permission:estoque,visualizar')
        ->name('estoque.help');
    Route::get('/estoque/novo', [StockController::class, 'create'])
        ->middleware('desktop.permission:estoque,criar')
        ->name('estoque.create');
    Route::post('/estoque', [StockController::class, 'store'])
        ->middleware('desktop.permission:estoque,criar')
        ->name('estoque.store');
    Route::post('/estoque/rapido', [StockController::class, 'quickStore'])
        ->middleware('desktop.permission:estoque,criar')
        ->name('estoque.quick.store');
    Route::get('/estoque/exportar-csv', [StockController::class, 'exportCsv'])
        ->middleware('desktop.permission:estoque,exportar')
        ->name('estoque.export.csv');
    Route::get('/estoque/modelo-importacao.csv', [StockController::class, 'downloadCsvTemplate'])
        ->middleware('desktop.permission:estoque,importar')
        ->name('estoque.download-template');
    Route::post('/estoque/importar-lote', [StockController::class, 'importCsv'])
        ->middleware('desktop.permission:estoque,importar')
        ->name('estoque.import');
    Route::get('/estoque/{part}/editar', [StockController::class, 'edit'])
        ->middleware('desktop.permission:estoque,editar')
        ->name('estoque.edit');
    Route::match(['put', 'patch'], '/estoque/{part}', [StockController::class, 'update'])
        ->middleware('desktop.permission:estoque,editar')
        ->name('estoque.update');
    Route::get('/estoque/{part}/movimentacoes', [StockController::class, 'movements'])
        ->middleware('desktop.permission:estoque,visualizar')
        ->name('estoque.movements');
    Route::post('/estoque/{part}/movimentacoes', [StockController::class, 'storeMovement'])
        ->middleware('desktop.permission:estoque,editar')
        ->name('estoque.movements.store');
    Route::patch('/estoque/{part}/encerrar', [StockController::class, 'close'])
        ->middleware('desktop.permission:estoque,encerrar')
        ->name('estoque.close');
    Route::delete('/estoque/{part}', [StockController::class, 'destroy'])
        ->middleware('desktop.permission:estoque,excluir')
        ->name('estoque.destroy');

    Route::get('/equipe-tecnica', [PeopleController::class, 'technicalTeam'])
        ->middleware('desktop.permission:funcionarios,visualizar')
        ->name('technicians.index');
    Route::post('/equipe-tecnica', [PeopleController::class, 'store'])
        ->middleware('desktop.permission:funcionarios,criar')
        ->name('technicians.store');
    Route::match(['put', 'patch'], '/equipe-tecnica/{member}', [PeopleController::class, 'update'])
        ->middleware('desktop.permission:funcionarios,editar')
        ->name('technicians.update');
    Route::patch('/equipe-tecnica/{member}/active', [PeopleController::class, 'updateTechnicalTeamActive'])
        ->middleware('desktop.permission:funcionarios,editar')
        ->name('technicians.active.update');

    Route::get('/equipamentos', [EquipmentController::class, 'index'])
        ->middleware('desktop.permission:equipamentos,visualizar')
        ->name('equipments.index');
    Route::get('/equipamentos/ajuda', [EquipmentController::class, 'help'])
        ->middleware('desktop.permission:equipamentos,visualizar')
        ->name('equipments.help');
    Route::get('/equipamentos/novo', [EquipmentController::class, 'create'])
        ->middleware('desktop.permission:equipamentos,criar')
        ->name('equipments.create');
    Route::post('/equipamentos', [EquipmentController::class, 'store'])
        ->middleware('desktop.permission:equipamentos,criar')
        ->name('equipments.store');
    Route::get('/equipamentos/{equipment}/editar', [EquipmentController::class, 'edit'])
        ->middleware('desktop.permission:equipamentos,editar')
        ->name('equipments.edit');
    Route::match(['put', 'patch'], '/equipamentos/{equipment}', [EquipmentController::class, 'update'])
        ->middleware('desktop.permission:equipamentos,editar')
        ->name('equipments.update');
    Route::post('/equipamentos/{equipment}/revelar-senha', [EquipmentController::class, 'revealPassword'])
        ->middleware('desktop.permission:equipamentos,visualizar')
        ->name('equipments.reveal-password');
    Route::get('/equipamentos/clientes/buscar', [EquipmentController::class, 'searchClients'])
        ->middleware('desktop.permission:equipamentos,criar|editar')
        ->name('equipments.clients.search');
    Route::post('/equipamentos/marcas/rapido', [EquipmentController::class, 'quickStoreBrand'])
        ->middleware('desktop.permission:equipamentos,criar')
        ->name('equipments.brands.quick.store');
    Route::post('/equipamentos/modelos/rapido', [EquipmentController::class, 'quickStoreModel'])
        ->middleware('desktop.permission:equipamentos,criar')
        ->name('equipments.models.quick.store');
    Route::get('/equipamentos/modelos/sugestoes', [EquipmentController::class, 'suggestModels'])
        ->middleware('desktop.permission:equipamentos,criar|editar')
        ->name('equipments.models.suggestions');
    Route::get('/equipamentos/coletor/local/snapshot', [EquipmentController::class, 'localCollectorSnapshot'])
        ->middleware('desktop.permission:equipamentos,criar|editar')
        ->name('equipments.collector.local-snapshot');
    Route::post('/equipamentos/coletor/local/coletar', [EquipmentController::class, 'localCollectorCollect'])
        ->middleware('desktop.permission:equipamentos,criar|editar')
        ->name('equipments.collector.local-collect');
    Route::post('/equipamentos/coletor/pareamentos', [EquipmentController::class, 'createCollectorPairing'])
        ->middleware('desktop.permission:equipamentos,criar|editar')
        ->name('equipments.collector-pairings.store');
    Route::get('/equipamentos/coletor/pareamentos/{code}', [EquipmentController::class, 'showCollectorPairing'])
        ->middleware('desktop.permission:equipamentos,criar|editar')
        ->name('equipments.collector-pairings.show');
    Route::get('/equipamentos/coletor/pareamentos/{code}/baixar/windows', [EquipmentController::class, 'downloadWindowsCollectorPackage'])
        ->middleware('desktop.permission:equipamentos,criar|editar')
        ->name('equipments.collector-pairings.download-windows');
    Route::get('/equipamentos/{equipment}/fotos/{photo}', [EquipmentController::class, 'photo'])
        ->middleware('desktop.permission:equipamentos,visualizar|editar')
        ->name('equipments.photos.show');
    Route::get('/equipamentos/{equipment}', [EquipmentController::class, 'show'])
        ->middleware('desktop.permission:equipamentos,visualizar')
        ->name('equipments.show');

    Route::get('/usuarios', [UserController::class, 'index'])
        ->middleware('desktop.permission:usuarios,visualizar')
        ->name('users.index');
    Route::post('/usuarios', [UserController::class, 'store'])
        ->middleware('desktop.permission:usuarios,criar')
        ->name('users.store');
    Route::post('/usuarios/{user}', [UserController::class, 'update'])
        ->middleware('desktop.permission:usuarios,editar')
        ->name('users.update');
    Route::post('/usuarios/{user}/ativo', [UserController::class, 'updateActive'])
        ->middleware('desktop.permission:usuarios,editar')
        ->name('users.active.update');

    Route::get('/grupos', [GroupController::class, 'index'])
        ->middleware('desktop.permission:grupos,visualizar')
        ->name('groups.index');
    Route::post('/grupos', [GroupController::class, 'store'])
        ->middleware('desktop.permission:grupos,criar')
        ->name('groups.store');
    Route::post('/grupos/{group}', [GroupController::class, 'update'])
        ->middleware('desktop.permission:grupos,editar')
        ->name('groups.update');
    Route::post('/grupos/{group}/remover', [GroupController::class, 'destroy'])
        ->middleware('desktop.permission:grupos,excluir')
        ->name('groups.destroy');
    Route::get('/grupos/{group}/permissoes', [GroupController::class, 'permissions'])
        ->middleware('desktop.permission:grupos,visualizar')
        ->name('groups.permissions.edit');
    Route::post('/grupos/{group}/permissoes', [GroupController::class, 'updatePermissions'])
        ->middleware('desktop.permission:grupos,editar')
        ->name('groups.permissions.update');
});
