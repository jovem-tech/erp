<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Chat\AttachmentController;
use App\Http\Controllers\Api\V1\Chat\ClientSearchController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\Chat\ConversationController;
use App\Http\Controllers\Api\V1\Chat\MessageController;
use App\Http\Controllers\Api\V1\ChecklistModeloController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DefeitoRelatadoController;
use App\Http\Controllers\Api\V1\EquipamentoDefeitoController;
use App\Http\Controllers\Api\V1\EquipmentCollectorController;
use App\Http\Controllers\Api\V1\EquipmentController;
use App\Http\Controllers\Api\V1\EstoqueController;
use App\Http\Controllers\Api\V1\KnowledgeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\ConfigurationController;
use App\Http\Controllers\Api\V1\FinanceiroCatalogController;
use App\Http\Controllers\Api\V1\FinanceiroCartaoController;
use App\Http\Controllers\Api\V1\FinanceiroPrecificacaoController;
use App\Http\Controllers\Api\V1\FinanceiroController;
use App\Http\Controllers\Api\V1\FinanceiroMargemController;
use App\Http\Controllers\Api\V1\FinanceiroReportController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderStatusFlowController;
use App\Http\Controllers\Api\V1\OsPdfTemplateController;
use App\Http\Controllers\Api\V1\ServicoController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WhatsappTemplateController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class);

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('collector/snapshots', [EquipmentCollectorController::class, 'storeSnapshot'])
        ->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/me', [AuthController::class, 'updateProfile']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:60,1');
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard/summary', [DashboardController::class, 'summary'])
            ->name('api.v1.dashboard.summary');

        Route::get('notifications', [NotificationController::class, 'index'])->name('api.v1.notifications.index');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('api.v1.notifications.read');
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('api.v1.notifications.read_all');
        Route::delete('notifications/read', [NotificationController::class, 'clearRead'])->name('api.v1.notifications.clear_read');

        Route::get('orcamentos/form-data', [BudgetController::class, 'formData'])->name('api.v1.orcamentos.form_data');
        Route::get('orcamentos', [BudgetController::class, 'index'])->name('api.v1.orcamentos.index');
        Route::post('orcamentos', [BudgetController::class, 'store'])->name('api.v1.orcamentos.store');
        Route::get('orcamentos/{budget}', [BudgetController::class, 'show'])->name('api.v1.orcamentos.show');
        Route::match(['put', 'patch'], 'orcamentos/{budget}', [BudgetController::class, 'update'])->name('api.v1.orcamentos.update');
        Route::post('orcamentos/{budget}/send-approval', [BudgetController::class, 'sendForApproval'])->name('api.v1.orcamentos.send_approval');
        Route::delete('orcamentos/{budget}', [BudgetController::class, 'destroy'])->name('api.v1.orcamentos.destroy');

        Route::prefix('financeiro/cartoes')
            ->name('api.v1.financeiro.cartoes.')
            ->group(function (): void {
                Route::get('/', [FinanceiroCartaoController::class, 'index'])->name('index');
                Route::post('/simular', [FinanceiroCartaoController::class, 'simulate'])->name('simular');

                Route::post('/operadoras', [FinanceiroCartaoController::class, 'storeOperadora'])->name('operadoras.store');
                Route::match(['put', 'patch'], '/operadoras/{operadora}', [FinanceiroCartaoController::class, 'updateOperadora'])->name('operadoras.update');
                Route::delete('/operadoras/{operadora}', [FinanceiroCartaoController::class, 'destroyOperadora'])->name('operadoras.destroy');

                Route::post('/bandeiras', [FinanceiroCartaoController::class, 'storeBandeira'])->name('bandeiras.store');
                Route::match(['put', 'patch'], '/bandeiras/{bandeira}', [FinanceiroCartaoController::class, 'updateBandeira'])->name('bandeiras.update');
                Route::delete('/bandeiras/{bandeira}', [FinanceiroCartaoController::class, 'destroyBandeira'])->name('bandeiras.destroy');

                Route::post('/taxas', [FinanceiroCartaoController::class, 'storeTaxa'])->name('taxas.store');
                Route::match(['put', 'patch'], '/taxas/{taxa}', [FinanceiroCartaoController::class, 'updateTaxa'])->name('taxas.update');
                Route::delete('/taxas/{taxa}', [FinanceiroCartaoController::class, 'destroyTaxa'])->name('taxas.destroy');

                Route::post('/taxas-online', [FinanceiroCartaoController::class, 'storeGatewayTaxa'])->name('taxas_online.store');
                Route::match(['put', 'patch'], '/taxas-online/{gatewayTaxa}', [FinanceiroCartaoController::class, 'updateGatewayTaxa'])->name('taxas_online.update');
                Route::delete('/taxas-online/{gatewayTaxa}', [FinanceiroCartaoController::class, 'destroyGatewayTaxa'])->name('taxas_online.destroy');
            });

        Route::prefix('financeiro/precificacao')
            ->name('api.v1.financeiro.precificacao.')
            ->group(function (): void {
                Route::get('/', [FinanceiroPrecificacaoController::class, 'index'])->name('index');
                Route::put('/', [FinanceiroPrecificacaoController::class, 'update'])->name('update');
                Route::post('/simular-peca', [FinanceiroPrecificacaoController::class, 'simulatePeca'])->name('simular_peca');
                Route::post('/simular-servico', [FinanceiroPrecificacaoController::class, 'simulateServico'])->name('simular_servico');
            });

        Route::prefix('financeiro')
            ->name('api.v1.financeiro.')
            ->group(function (): void {
                Route::get('catalogo', [FinanceiroCatalogController::class, 'index'])->name('catalogo.index');
                Route::post('categorias', [FinanceiroCatalogController::class, 'storeCategoria'])->name('categorias.store');
                Route::match(['put', 'patch'], 'categorias/{categoria}', [FinanceiroCatalogController::class, 'updateCategoria'])->name('categorias.update');
                Route::delete('categorias/{categoria}', [FinanceiroCatalogController::class, 'destroyCategoria'])->name('categorias.destroy');
                Route::post('dre-grupos', [FinanceiroCatalogController::class, 'storeGrupo'])->name('dre_grupos.store');
                Route::match(['put', 'patch'], 'dre-grupos/{grupo}', [FinanceiroCatalogController::class, 'updateGrupo'])->name('dre_grupos.update');
                Route::delete('dre-grupos/{grupo}', [FinanceiroCatalogController::class, 'destroyGrupo'])->name('dre_grupos.destroy');
                Route::post('dre-subgrupos', [FinanceiroCatalogController::class, 'storeSubgrupo'])->name('dre_subgrupos.store');
                Route::match(['put', 'patch'], 'dre-subgrupos/{subgrupo}', [FinanceiroCatalogController::class, 'updateSubgrupo'])->name('dre_subgrupos.update');
                Route::delete('dre-subgrupos/{subgrupo}', [FinanceiroCatalogController::class, 'destroySubgrupo'])->name('dre_subgrupos.destroy');

                Route::post('comissoes', [FinanceiroCatalogController::class, 'storeComissao'])->name('comissoes.store');
                Route::match(['put', 'patch'], 'comissoes/{comissao}', [FinanceiroCatalogController::class, 'updateComissao'])->name('comissoes.update');
                Route::delete('comissoes/{comissao}', [FinanceiroCatalogController::class, 'destroyComissao'])->name('comissoes.destroy');
                Route::match(['put', 'patch'], 'comissoes-padrao', [FinanceiroCatalogController::class, 'updateComissaoPadrao'])->name('comissoes.padrao');

                Route::get('relatorios/dre', [FinanceiroReportController::class, 'dre'])->name('relatorios.dre');
                Route::get('relatorios/dre-caixa', [FinanceiroReportController::class, 'dreCaixa'])->name('relatorios.dre_caixa');
                Route::get('relatorios/fluxo-caixa', [FinanceiroReportController::class, 'fluxoCaixa'])->name('relatorios.fluxo_caixa');

                Route::get('margem', [FinanceiroMargemController::class, 'index'])->name('margem.index');
                Route::get('margem/{os}', [FinanceiroMargemController::class, 'show'])->name('margem.show');
                Route::post('margem/{os}/recalcular', [FinanceiroMargemController::class, 'recalcular'])->name('margem.recalcular');

                Route::get('/', [FinanceiroController::class, 'index'])->name('index');
                Route::post('/', [FinanceiroController::class, 'store'])->name('store');
                Route::get('/{financeiro}', [FinanceiroController::class, 'show'])->name('show');
                Route::match(['put', 'patch'], '/{financeiro}', [FinanceiroController::class, 'update'])->name('update');
                Route::delete('/{financeiro}', [FinanceiroController::class, 'destroy'])->name('destroy');
                Route::post('/{financeiro}/baixar', [FinanceiroController::class, 'pay'])->name('pay');
                Route::post('/{financeiro}/cancelar', [FinanceiroController::class, 'cancel'])->name('cancel');
            });

        Route::prefix('conversas')
            ->name('api.v1.conversas.')
            ->group(function (): void {
                Route::get('/', [ConversationController::class, 'index'])->name('index');
                Route::post('/', [ConversationController::class, 'store'])->name('store');
                Route::get('/{conversation}', [ConversationController::class, 'show'])->name('show');
                Route::post('/{conversation}/mensagens', [MessageController::class, 'store'])->name('mensagens.store');
            });

        Route::prefix('chat')
            ->name('api.v1.chat.')
            ->group(function (): void {
                Route::get('clientes/search', [ClientSearchController::class, 'index'])->name('clients.search');
                Route::get('anexos/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
            });

        Route::get('orders', [OrderController::class, 'index'])->name('api.v1.orders.index');
        Route::get('orders/status-catalog', [OrderController::class, 'statusCatalog'])->name('api.v1.orders.status_catalog');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('api.v1.orders.show');
        Route::post('orders', [OrderController::class, 'store'])->name('api.v1.orders.store');
        Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update'])->name('api.v1.orders.update');
        Route::get('orders/{order}/photos/{photo}', [OrderController::class, 'photo'])->name('api.v1.orders.photos.show');
        Route::get('orders/{order}/documents/{document}', [OrderController::class, 'document'])->name('api.v1.orders.documents.show');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('api.v1.orders.status.update');
        Route::post('orders/{order}/procedures', [OrderController::class, 'storeProcedure'])->name('api.v1.orders.procedures.store');
        Route::get('orders/{order}/closure', [OrderController::class, 'closureMetadata'])->name('api.v1.orders.closure.metadata');
        Route::post('orders/{order}/closure', [OrderController::class, 'close'])->name('api.v1.orders.closure.store');
        Route::post('orders/{order}/closure/cancel', [OrderController::class, 'cancelClosure'])->name('api.v1.orders.closure.cancel');

        Route::get('clients', [ClientController::class, 'index'])->name('api.v1.clients.index');
        Route::post('clients', [ClientController::class, 'store'])->name('api.v1.clients.store');
        Route::get('clients/{client}', [ClientController::class, 'show'])->name('api.v1.clients.show');
        Route::match(['put', 'patch'], 'clients/{client}', [ClientController::class, 'update'])->name('api.v1.clients.update');

        Route::get('servicos/form-data', [ServicoController::class, 'formData'])->name('api.v1.servicos.form_data');
        Route::get('servicos/exportar-csv', [ServicoController::class, 'exportCsv'])->name('api.v1.servicos.export_csv');
        Route::get('servicos/modelo-importacao.csv', [ServicoController::class, 'downloadCsvTemplate'])->name('api.v1.servicos.csv_template');
        Route::post('servicos/importar-lote', [ServicoController::class, 'importCsv'])->name('api.v1.servicos.import_csv');
        Route::get('servicos', [ServicoController::class, 'index'])->name('api.v1.servicos.index');
        Route::post('servicos', [ServicoController::class, 'store'])->name('api.v1.servicos.store');
        Route::get('servicos/{servico}', [ServicoController::class, 'show'])->name('api.v1.servicos.show');
        Route::match(['put', 'patch'], 'servicos/{servico}', [ServicoController::class, 'update'])->name('api.v1.servicos.update');
        Route::patch('servicos/{servico}/encerrar', [ServicoController::class, 'close'])->name('api.v1.servicos.close');
        Route::delete('servicos/{servico}', [ServicoController::class, 'destroy'])->name('api.v1.servicos.destroy');

        Route::get('estoque/form-data', [EstoqueController::class, 'formData'])->name('api.v1.estoque.form_data');
        Route::get('estoque/exportar-csv', [EstoqueController::class, 'exportCsv'])->name('api.v1.estoque.export_csv');
        Route::get('estoque/modelo-importacao.csv', [EstoqueController::class, 'downloadCsvTemplate'])->name('api.v1.estoque.csv_template');
        Route::post('estoque/importar-lote', [EstoqueController::class, 'importCsv'])->name('api.v1.estoque.import_csv');
        Route::get('estoque/baixo', [EstoqueController::class, 'lowStock'])->name('api.v1.estoque.low_stock');
        Route::get('estoque', [EstoqueController::class, 'index'])->name('api.v1.estoque.index');
        Route::post('estoque', [EstoqueController::class, 'store'])->name('api.v1.estoque.store');
        Route::get('estoque/{peca}/movimentacoes', [EstoqueController::class, 'movements'])->name('api.v1.estoque.movements.index');
        Route::post('estoque/{peca}/movimentacoes', [EstoqueController::class, 'storeMovement'])->name('api.v1.estoque.movements.store');
        Route::get('estoque/{peca}', [EstoqueController::class, 'show'])->name('api.v1.estoque.show');
        Route::match(['put', 'patch'], 'estoque/{peca}', [EstoqueController::class, 'update'])->name('api.v1.estoque.update');
        Route::patch('estoque/{peca}/encerrar', [EstoqueController::class, 'close'])->name('api.v1.estoque.close');
        Route::delete('estoque/{peca}', [EstoqueController::class, 'destroy'])->name('api.v1.estoque.destroy');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('api.v1.suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('api.v1.suppliers.store');
        Route::get('suppliers/consultar-cnpj', [SupplierController::class, 'lookupCnpj'])->name('api.v1.suppliers.cnpj_lookup');
        Route::patch('suppliers/{supplier}/encerrar', [SupplierController::class, 'close'])
            ->whereNumber('supplier')
            ->name('api.v1.suppliers.close');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
            ->whereNumber('supplier')
            ->name('api.v1.suppliers.show');
        Route::match(['put', 'patch'], 'suppliers/{supplier}', [SupplierController::class, 'update'])
            ->whereNumber('supplier')
            ->name('api.v1.suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])
            ->whereNumber('supplier')
            ->name('api.v1.suppliers.destroy');

        Route::get('equipments/form-data', [EquipmentController::class, 'formData'])->name('api.v1.equipments.form_data');
        Route::get('equipments/models/suggestions', [EquipmentController::class, 'suggestModels'])->name('api.v1.equipments.models.suggestions');
        Route::post('equipments/brands', [EquipmentController::class, 'storeBrand'])->name('api.v1.equipments.brands.store');
        Route::post('equipments/models', [EquipmentController::class, 'storeModel'])->name('api.v1.equipments.models.store');
        Route::get('equipments/collector/local-snapshot', [EquipmentController::class, 'localCollectorSnapshot'])->name('api.v1.equipments.collector.local_snapshot');
        Route::post('equipments/collector/local-collect', [EquipmentController::class, 'localCollectorCollect'])->name('api.v1.equipments.collector.local_collect');
        Route::post('equipments/collector-pairings', [EquipmentController::class, 'createCollectorPairing'])->name('api.v1.equipments.collector_pairings.store');
        Route::get('equipments/collector-pairings/{code}', [EquipmentController::class, 'showCollectorPairing'])->name('api.v1.equipments.collector_pairings.show');
        Route::get('equipments', [EquipmentController::class, 'index'])->name('api.v1.equipments.index');
        Route::post('equipments', [EquipmentController::class, 'store'])->name('api.v1.equipments.store');
        Route::match(['put', 'patch'], 'equipments/{equipment}', [EquipmentController::class, 'update'])->name('api.v1.equipments.update');
        Route::get('equipments/{equipment}/photos/{photo}', [EquipmentController::class, 'photo'])->name('api.v1.equipments.photos.show');
        Route::get('equipments/{equipment}', [EquipmentController::class, 'show'])->name('api.v1.equipments.show');

        Route::get('knowledge/equipment-types', [KnowledgeController::class, 'equipmentTypes'])->name('api.v1.knowledge.equipment_types');

        Route::get('knowledge/reported-defects', [DefeitoRelatadoController::class, 'index'])->name('api.v1.knowledge.reported_defects.index');
        Route::post('knowledge/reported-defects', [DefeitoRelatadoController::class, 'store'])->name('api.v1.knowledge.reported_defects.store');
        Route::get('knowledge/reported-defects/{defeito}', [DefeitoRelatadoController::class, 'show'])->whereNumber('defeito')->name('api.v1.knowledge.reported_defects.show');
        Route::match(['put', 'patch'], 'knowledge/reported-defects/{defeito}', [DefeitoRelatadoController::class, 'update'])->whereNumber('defeito')->name('api.v1.knowledge.reported_defects.update');
        Route::patch('knowledge/reported-defects/{defeito}/ativo', [DefeitoRelatadoController::class, 'toggleActive'])->whereNumber('defeito')->name('api.v1.knowledge.reported_defects.toggle_active');
        Route::delete('knowledge/reported-defects/{defeito}', [DefeitoRelatadoController::class, 'destroy'])->whereNumber('defeito')->name('api.v1.knowledge.reported_defects.destroy');

        Route::get('knowledge/defects', [EquipamentoDefeitoController::class, 'index'])->name('api.v1.knowledge.defects.index');
        Route::post('knowledge/defects', [EquipamentoDefeitoController::class, 'store'])->name('api.v1.knowledge.defects.store');
        Route::get('knowledge/defects/{defeito}', [EquipamentoDefeitoController::class, 'show'])->whereNumber('defeito')->name('api.v1.knowledge.defects.show');
        Route::match(['put', 'patch'], 'knowledge/defects/{defeito}', [EquipamentoDefeitoController::class, 'update'])->whereNumber('defeito')->name('api.v1.knowledge.defects.update');
        Route::patch('knowledge/defects/{defeito}/ativo', [EquipamentoDefeitoController::class, 'toggleActive'])->whereNumber('defeito')->name('api.v1.knowledge.defects.toggle_active');
        Route::delete('knowledge/defects/{defeito}', [EquipamentoDefeitoController::class, 'destroy'])->whereNumber('defeito')->name('api.v1.knowledge.defects.destroy');
        Route::post('knowledge/defects/{defeito}/procedures', [EquipamentoDefeitoController::class, 'storeProcedimento'])->whereNumber('defeito')->name('api.v1.knowledge.defects.procedures.store');
        Route::match(['put', 'patch'], 'knowledge/defects/{defeito}/procedures/{procedimento}', [EquipamentoDefeitoController::class, 'updateProcedimento'])->whereNumber('defeito')->whereNumber('procedimento')->name('api.v1.knowledge.defects.procedures.update');
        Route::delete('knowledge/defects/{defeito}/procedures/{procedimento}', [EquipamentoDefeitoController::class, 'destroyProcedimento'])->whereNumber('defeito')->whereNumber('procedimento')->name('api.v1.knowledge.defects.procedures.destroy');
        Route::patch('knowledge/defects/{defeito}/procedures/{procedimento}/move', [EquipamentoDefeitoController::class, 'moveProcedimento'])->whereNumber('defeito')->whereNumber('procedimento')->name('api.v1.knowledge.defects.procedures.move');

        Route::get('knowledge/pdf-templates', [OsPdfTemplateController::class, 'index'])->name('api.v1.knowledge.pdf_templates.index');
        Route::get('knowledge/pdf-templates/placeholders', [OsPdfTemplateController::class, 'placeholders'])->name('api.v1.knowledge.pdf_templates.placeholders');
        Route::post('knowledge/pdf-templates', [OsPdfTemplateController::class, 'store'])->name('api.v1.knowledge.pdf_templates.store');
        Route::get('knowledge/pdf-templates/{template}', [OsPdfTemplateController::class, 'show'])->whereNumber('template')->name('api.v1.knowledge.pdf_templates.show');
        Route::match(['put', 'patch'], 'knowledge/pdf-templates/{template}', [OsPdfTemplateController::class, 'update'])->whereNumber('template')->name('api.v1.knowledge.pdf_templates.update');
        Route::patch('knowledge/pdf-templates/{template}/ativo', [OsPdfTemplateController::class, 'toggleActive'])->whereNumber('template')->name('api.v1.knowledge.pdf_templates.toggle_active');
        Route::delete('knowledge/pdf-templates/{template}', [OsPdfTemplateController::class, 'destroy'])->whereNumber('template')->name('api.v1.knowledge.pdf_templates.destroy');

        Route::get('knowledge/whatsapp-templates', [WhatsappTemplateController::class, 'index'])->name('api.v1.knowledge.whatsapp_templates.index');
        Route::get('knowledge/whatsapp-templates/placeholders', [WhatsappTemplateController::class, 'placeholders'])->name('api.v1.knowledge.whatsapp_templates.placeholders');
        Route::post('knowledge/whatsapp-templates', [WhatsappTemplateController::class, 'store'])->name('api.v1.knowledge.whatsapp_templates.store');
        Route::get('knowledge/whatsapp-templates/{template}', [WhatsappTemplateController::class, 'show'])->whereNumber('template')->name('api.v1.knowledge.whatsapp_templates.show');
        Route::match(['put', 'patch'], 'knowledge/whatsapp-templates/{template}', [WhatsappTemplateController::class, 'update'])->whereNumber('template')->name('api.v1.knowledge.whatsapp_templates.update');
        Route::patch('knowledge/whatsapp-templates/{template}/ativo', [WhatsappTemplateController::class, 'toggleActive'])->whereNumber('template')->name('api.v1.knowledge.whatsapp_templates.toggle_active');
        Route::delete('knowledge/whatsapp-templates/{template}', [WhatsappTemplateController::class, 'destroy'])->whereNumber('template')->name('api.v1.knowledge.whatsapp_templates.destroy');

        Route::get('knowledge/checklists/{tipo}', [ChecklistModeloController::class, 'index'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])
            ->name('api.v1.knowledge.checklists.index');
        Route::get('knowledge/checklists/{tipo}/modelos/{tipoEquipamento}', [ChecklistModeloController::class, 'showOrCreate'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('tipoEquipamento')
            ->name('api.v1.knowledge.checklists.modelos.show');
        Route::post('knowledge/checklists/{tipo}/modelos/{tipoEquipamento}', [ChecklistModeloController::class, 'storeModelo'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('tipoEquipamento')
            ->name('api.v1.knowledge.checklists.modelos.store');
        Route::match(['put', 'patch'], 'knowledge/checklists/{tipo}/modelos/{modelo}', [ChecklistModeloController::class, 'updateModelo'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')
            ->name('api.v1.knowledge.checklists.modelos.update');
        Route::delete('knowledge/checklists/{tipo}/modelos/{modelo}', [ChecklistModeloController::class, 'destroyModelo'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')
            ->name('api.v1.knowledge.checklists.modelos.destroy');
        Route::post('knowledge/checklists/{tipo}/modelos/{modelo}/itens', [ChecklistModeloController::class, 'storeItem'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')
            ->name('api.v1.knowledge.checklists.items.store');
        Route::match(['put', 'patch'], 'knowledge/checklists/{tipo}/modelos/{modelo}/itens/{item}', [ChecklistModeloController::class, 'updateItem'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
            ->name('api.v1.knowledge.checklists.items.update');
        Route::delete('knowledge/checklists/{tipo}/modelos/{modelo}/itens/{item}', [ChecklistModeloController::class, 'destroyItem'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
            ->name('api.v1.knowledge.checklists.items.destroy');
        Route::patch('knowledge/checklists/{tipo}/modelos/{modelo}/itens/{item}/mover', [ChecklistModeloController::class, 'moveItem'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
            ->name('api.v1.knowledge.checklists.items.move');
        Route::patch('knowledge/checklists/{tipo}/modelos/{modelo}/itens/{item}/ativo', [ChecklistModeloController::class, 'toggleItemActive'])
            ->whereIn('tipo', ['entrada', 'manutencao', 'controle_qualidade', 'saida'])->whereNumber('modelo')->whereNumber('item')
            ->name('api.v1.knowledge.checklists.items.toggle_active');

        Route::get('knowledge/os-flow', [OrderStatusFlowController::class, 'index'])->name('api.v1.knowledge.os_flow.index');
        Route::post('knowledge/os-flow/statuses', [OrderStatusFlowController::class, 'store'])->name('api.v1.knowledge.os_flow.statuses.store');
        Route::match(['put', 'patch'], 'knowledge/os-flow/statuses/{status}', [OrderStatusFlowController::class, 'update'])->whereNumber('status')->name('api.v1.knowledge.os_flow.statuses.update');
        Route::match(['put', 'patch'], 'knowledge/os-flow/transitions', [OrderStatusFlowController::class, 'updateTransitions'])->name('api.v1.knowledge.os_flow.transitions.update');

        Route::get('users', [UserController::class, 'index'])->name('api.v1.users.index');
        Route::post('users', [UserController::class, 'store'])->name('api.v1.users.store');
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->name('api.v1.users.update');
        Route::patch('users/{user}/active', [UserController::class, 'updateActive'])->name('api.v1.users.active.update');

        Route::get('groups', [GroupController::class, 'index'])->name('api.v1.groups.index');
        Route::post('groups', [GroupController::class, 'store'])->name('api.v1.groups.store');
        Route::match(['put', 'patch'], 'groups/{group}', [GroupController::class, 'update'])->name('api.v1.groups.update');
        Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('api.v1.groups.destroy');
        Route::get('groups/{group}/permissions', [GroupController::class, 'permissions'])->name('api.v1.groups.permissions.index');
        Route::put('groups/{group}/permissions', [GroupController::class, 'updatePermissions'])->name('api.v1.groups.permissions.update');

        Route::get('modules', [CatalogController::class, 'modules'])->name('api.v1.modules.index');
        Route::get('permissions', [CatalogController::class, 'permissions'])->name('api.v1.permissions.index');

        Route::prefix('configuracoes/empresa')
            ->name('api.v1.configuracoes.empresa.')
            ->group(function (): void {
                Route::get('/', [ConfigurationController::class, 'companyProfile'])->name('index');
                Route::match(['put', 'patch'], '/', [ConfigurationController::class, 'updateCompanyProfile'])->name('update');
                Route::get('/logo', [ConfigurationController::class, 'companyLogo'])->name('logo');
            });

        Route::prefix('configuracoes/integracoes')
            ->name('api.v1.configuracoes.integracoes.')
            ->group(function (): void {
                Route::get('/', [ConfigurationController::class, 'integrations'])->name('index');
                Route::put('/', [ConfigurationController::class, 'updateIntegrations'])->name('update');
                Route::post('/testar-conexao', [ConfigurationController::class, 'testConnection'])->name('test_connection');
                Route::post('/enviar-teste', [ConfigurationController::class, 'sendTestMessage'])->name('send_test');
                Route::post('/self-check-inbound', [ConfigurationController::class, 'selfCheckInbound'])->name('self_check');
                Route::post('/pagamentos/testar-conexao', [ConfigurationController::class, 'testPaymentConnection'])->name('pagamentos.test_connection');
                Route::post('/email/enviar-teste', [ConfigurationController::class, 'sendEmailTest'])->name('email.send_test');

                Route::prefix('gateway')
                    ->name('gateway.')
                    ->group(function (): void {
                        Route::get('/status', [ConfigurationController::class, 'gatewayStatus'])->name('status');
                        Route::get('/qr', [ConfigurationController::class, 'gatewayQr'])->name('qr');
                        Route::post('/restart', [ConfigurationController::class, 'gatewayRestart'])->name('restart');
                        Route::post('/logout', [ConfigurationController::class, 'gatewayLogout'])->name('logout');
                        Route::post('/start', [ConfigurationController::class, 'gatewayStart'])->name('start');
                    });
            });
    });
});

Route::fallback(function (Request $request) {
    return ApiResponse::error(
        'Rota não encontrada.',
        404,
        'API_NOT_FOUND',
        null,
        [],
        $request
    );
});
