{{-- Os modais da tela do Anexo X.

     Renderizados UMA VEZ e preenchidos no `show.bs.modal` a partir do
     `relatedTarget`: doze cópias de cada um no DOM seriam absurdas, e o modal
     do padrão da Receita carregaria doze PDFs na abertura da página. --}}

{{-- 1. Receitas brutas do mês — não faz requisição nenhuma: lê o resumo que já
     veio no bootstrap da página, inclusive ao trocar de regime. --}}
<div class="modal fade" id="modalReceitasDoMes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Receitas brutas do mês</h5>
                    <p class="surface-subtitle small mb-0" data-receitas-periodo></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="dashboard-regime-switch mb-3" role="group" aria-label="Regime do modal">
                    <button type="button" class="dashboard-regime-option is-active" data-receitas-regime="competencia" aria-pressed="true">
                        Competência
                    </button>
                    <button type="button" class="dashboard-regime-option" data-receitas-regime="caixa" aria-pressed="false">
                        Caixa
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-receitas-tabela></table>
                </div>

                <p class="surface-subtitle small mt-3 mb-0" data-receitas-deducoes></p>
            </div>
        </div>
    </div>
</div>

{{-- 2. Padrão da Receita Federal — iframe do PDF do mês.

     Iframe, e não HTML: o formulário mora num Blade do BACKEND, e o desktop é
     outra aplicação Laravel que não pode incluí-lo. Renderizar HTML aqui
     criaria uma segunda cópia de um formulário definido em norma, em outro
     repositório, sem nenhum teste capaz de compará-las. --}}
<div class="modal fade" id="modalFormularioReceita" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Anexo X no padrão da Receita Federal</h5>
                    <p class="surface-subtitle small mb-0" data-formulario-periodo></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body p-0" style="min-height: 70vh;">
                {{-- `src` só é atribuído no `shown` e apagado no `hidden`: sem
                     isso a página abriria doze renderizações de PDF. --}}
                <iframe data-formulario-iframe title="Formulário do Anexo X"
                        style="width: 100%; height: 70vh; border: 0;"></iframe>
            </div>

            <div class="modal-footer">
                <a href="#" target="_blank" rel="noopener" class="btn btn-outline-light" data-formulario-abrir>
                    <i class="bi bi-box-arrow-up-right me-1"></i>Abrir em nova aba
                </a>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

{{-- 3. Editar o relatório — ajustes auditados por linha.

     Só entra no DOM para quem tem `fiscal:editar`: o item do menu já era
     escondido, mas deixar o formulário na página para quem não pode usá-lo é
     convite a confusão — e a um POST que só falharia no servidor. --}}
@if ($podeEditar)
<div class="modal fade" id="modalEditarRelatorio" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Editar o relatório</h5>
                    <p class="surface-subtitle small mb-0" data-editar-periodo></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="surface-subtitle">
                    O ajuste é um lançamento <strong>somado</strong> ao que o sistema apurou, e não uma
                    substituição: o valor calculado continua visível ao lado. Serve para declarar receita bruta
                    que existiu mas não passou pelo ERP.
                </p>

                <div class="alert alert-warning d-none" data-editar-bloqueado>
                    <i class="bi bi-lock-fill me-1"></i>
                    Competência encerrada — os valores estão congelados. Reabra o mês antes de ajustar.
                </div>

                <div class="table-responsive mb-3">
                    <table class="table align-middle mb-0" data-editar-linhas></table>
                </div>

                <form data-editar-form class="desktop-form-card mb-3">
                    <input type="hidden" name="competencia" value="">
                    <input type="hidden" name="regime" value="">

                    <div class="row g-2">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="ajusteLinha">Linha</label>
                            <select class="form-select" id="ajusteLinha" name="linha" required data-editar-select-linha></select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="ajusteValor">Valor</label>
                            <input type="number" step="0.01" class="form-control" id="ajusteValor" name="valor" required
                                   placeholder="90,00">
                            <div class="form-text">Negativo reduz a linha.</div>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label" for="ajusteMotivo">Motivo</label>
                            <input type="text" class="form-control" id="ajusteMotivo" name="motivo" required
                                   minlength="10" maxlength="500"
                                   placeholder="Serviço cobrado em dinheiro, não lançado no sistema">
                        </div>
                    </div>

                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="ajusteAmbos" name="aplicar_no_outro_regime" value="1">
                        <label class="form-check-label" for="ajusteAmbos">
                            Lançar também no outro regime
                            <span class="surface-subtitle small d-block">
                                Sem isso, as duas leituras do mês passam a discordar entre si.
                            </span>
                        </label>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-1"></i>Lançar ajuste
                        </button>
                    </div>
                </form>

                <h6 class="surface-title">Lançamentos</h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" data-editar-lancamentos></table>
                </div>
            </div>
        </div>
    </div>
</div>

@endif

{{-- 5. Todas as operações do mês, com filtro. --}}
<div class="modal fade" id="modalOperacoesDoMes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Operações do mês</h5>
                    <p class="surface-subtitle small mb-0" data-operacoes-periodo></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-primary" data-operacoes-filtro="todas">Todas</button>
                    <button type="button" class="btn btn-sm btn-outline-light" data-operacoes-filtro="com">Com documento fiscal</button>
                    <button type="button" class="btn btn-sm btn-outline-light" data-operacoes-filtro="sem">Sem documento fiscal</button>
                </div>

                {{-- Sem esta frase as duas contagens não somam o total e parece bug. --}}
                <p class="surface-subtitle small">
                    Uma operação parcialmente documentada aparece nos <strong>dois</strong> filtros: eles são
                    “tem alguma parcela documentada” e “tem alguma parcela sem documento”, não uma divisão do total.
                </p>

                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-operacoes-tabela></table>
                </div>
            </div>
        </div>
    </div>
</div>

@if ($podeEncerrar)
{{-- 6a. Reconferência de um mês encerrado. --}}
<div class="modal fade" id="modalReconferir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Reconferir a competência</h5>
                    <p class="surface-subtitle small mb-0" data-reconferir-periodo></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body" data-reconferir-corpo></div>
        </div>
    </div>
</div>

{{-- 6b. Reabertura, com confirmação de administrador. --}}
<div class="modal fade" id="modalReabrirAnexoX" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="{{ route('fiscal.anexo-x.reabrir') }}" class="modal-content">
            @csrf
            <input type="hidden" name="competencia" value="" data-reabrir-competencia>
            <input type="hidden" name="regime" value="{{ $regime }}">

            <div class="modal-header">
                <h5 class="modal-title">Reabrir a competência <span data-reabrir-periodo></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="surface-subtitle">
                    Reabrir um período já declarado exige confirmação de um administrador. A versão atual fica
                    guardada como evidência, e o próximo encerramento grava uma versão nova.
                </p>

                <div class="mb-3">
                    <label class="form-label" for="motivoReabertura">Motivo</label>
                    <textarea class="form-control" id="motivoReabertura" name="motivo" rows="2"
                              minlength="10" maxlength="500" required
                              placeholder="Ex.: nota fiscal emitida depois do encerramento"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="adminEmailReabertura">E-mail do administrador</label>
                    <input type="email" class="form-control" id="adminEmailReabertura" name="admin_email" required autocomplete="off">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="adminSenhaReabertura">Senha do administrador</label>
                    <input type="password" class="form-control" id="adminSenhaReabertura" name="admin_password" required autocomplete="new-password">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-unlock me-1"></i>Reabrir competência
                </button>
            </div>
        </form>
    </div>
</div>

@endif

{{-- Download do formulário: um mês ou o ano inteiro. --}}
<div class="modal fade" id="modalBaixarAnexoX" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="get" action="{{ route('fiscal.anexo-x.pdf') }}" target="_blank" rel="noopener"
              class="modal-content" data-anexo-x-download>
            <input type="hidden" name="regime" value="{{ $regime }}">

            <div class="modal-header">
                <h5 class="modal-title">Baixar Anexo X (PDF)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="periodo" id="periodoMes" value="mes" checked data-anexo-x-periodo>
                    <label class="form-check-label" for="periodoMes">
                        <strong>Um mês</strong>
                        <div class="surface-subtitle small">Uma folha, com a competência escolhida.</div>
                    </label>
                </div>

                <div class="mb-3 ps-4">
                    <label class="form-label" for="pdfCompetencia">Competência</label>
                    <input type="month" class="form-control" id="pdfCompetencia" name="competencia"
                           value="{{ sprintf('%04d-%02d', $resumo['ano'] ?? now()->year, now()->month) }}"
                           data-anexo-x-campo="mes">
                </div>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="periodo" id="periodoAno" value="ano" data-anexo-x-periodo>
                    <label class="form-check-label" for="periodoAno">
                        <strong>Ano inteiro</strong>
                        <div class="surface-subtitle small">Uma folha para cada mês, de janeiro a dezembro.</div>
                    </label>
                </div>

                <div class="mb-3 ps-4">
                    <label class="form-label" for="pdfAno">Ano</label>
                    <input type="number" class="form-control" id="pdfAno" name="ano" min="2000" max="{{ now()->year + 1 }}"
                           step="1" value="{{ $resumo['ano'] ?? now()->year }}" data-anexo-x-campo="ano" disabled>
                </div>

                <div class="alert alert-secondary mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    As folhas de meses que ainda não terminaram saem com aviso no rodapé para não serem assinadas
                    por engano. Meses já encerrados saem pelos valores congelados no encerramento.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>Gerar PDF
                </button>
            </div>
        </form>
    </div>
</div>
