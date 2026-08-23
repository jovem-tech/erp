@php
    $podeCriar = \App\Support\DesktopSession::can('backups', 'criar');
    $podeBaixar = \App\Support\DesktopSession::can('backups', 'baixar');
    $podeExcluir = \App\Support\DesktopSession::can('backups', 'excluir');
    $podeRestaurar = \App\Support\DesktopSession::can('backups', 'restaurar');
    $podeAdministrar = \App\Support\DesktopSession::can('backups', 'administrar');
@endphp

<div class="desktop-form-card" id="backup-app"
     data-url-dados="{{ route('configurations.backups.data') }}"
     data-url-gerar="{{ route('configurations.backups.generate') }}"
     data-url-varrer="{{ route('configurations.backups.scan') }}"
     data-url-configuracoes="{{ route('configurations.backups.settings') }}"
     data-url-frase="{{ route('configurations.backups.passphrase') }}"
     data-pode-baixar="{{ $podeBaixar ? '1' : '0' }}"
     data-pode-excluir="{{ $podeExcluir ? '1' : '0' }}"
     data-pode-restaurar="{{ $podeRestaurar ? '1' : '0' }}">

    <div class="surface-card-header">
        <div>
            <h3 class="surface-title mb-1">Backup e Restauração</h3>
            <p class="surface-subtitle mb-0">
                Cópias de segurança do banco de dados, dos arquivos e da configuração do sistema.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-light" data-backup-acao="varrer">
                <i class="bi bi-arrow-repeat me-1"></i>Sincronizar
            </button>
            @if ($podeCriar)
                <button type="button" class="btn btn-primary" data-backup-acao="gerar">
                    <i class="bi bi-hdd-stack me-1"></i>Gerar backup agora
                </button>
            @endif
        </div>
    </div>

    {{-- Enquanto nao existir um pacote completo, o alerta diz com todas as
         letras o que os backups atuais NAO cobrem. --}}
    <div class="alert alert-warning d-none" data-backup-alerta-incompleto>
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Nenhum backup completo existe ainda.</strong>
        As cópias atuais cobrem apenas o banco de dados — nenhuma imagem, PDF ou documento de cliente está protegido.
    </div>

    <div class="alert alert-danger d-none" data-backup-alerta-ambiente></div>

    <div class="row g-3 mb-4" data-backup-resumo>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="surface-card h-100 p-3">
                <div class="text-muted small">Último backup completo</div>
                <div class="fs-5 fw-semibold" data-backup-resumo-completo>—</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="surface-card h-100 p-3">
                <div class="text-muted small">Próxima execução automática</div>
                <div class="fs-5 fw-semibold" data-backup-resumo-agenda>—</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="surface-card h-100 p-3">
                <div class="text-muted small">Cópias no catálogo</div>
                <div class="fs-5 fw-semibold" data-backup-resumo-total>—</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="surface-card h-100 p-3">
                <div class="text-muted small">Espaço ocupado</div>
                <div class="fs-5 fw-semibold" data-backup-resumo-espaco>—</div>
            </div>
        </div>
    </div>

    <div class="d-none mb-4" data-backup-progresso>
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-semibold" data-backup-progresso-etapa>Preparando…</span>
            <span class="text-muted small" data-backup-progresso-percentual>0%</span>
        </div>
        <div class="progress" style="height: .5rem;">
            <div class="progress-bar progress-bar-striped progress-bar-animated"
                 role="progressbar" style="width: 0%" data-backup-progresso-barra></div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th scope="col">Data</th>
                    <th scope="col">Origem</th>
                    <th scope="col">Conteúdo</th>
                    <th scope="col" class="text-end">Tamanho</th>
                    <th scope="col">Situação</th>
                    <th scope="col" class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody data-backup-lista>
                <tr><td colspan="6" class="text-center text-muted py-4">Carregando…</td></tr>
            </tbody>
        </table>
    </div>

    @if ($podeAdministrar)
        <hr class="my-4">

        <h4 class="surface-title fs-6 mb-3">Frase secreta</h4>
        <p class="surface-subtitle">
            O pacote é criptografado com AES-256. Estado atual:
            <strong data-backup-frase-estado>verificando…</strong>
        </p>
        <div class="alert alert-warning">
            <i class="bi bi-key-fill me-2"></i>
            Guarde esta frase <strong>fora do servidor</strong>. Sem ela, nenhum backup pode ser restaurado —
            nem por nós, nem por ninguém.
        </div>
        <form class="row g-2 align-items-end mb-4" data-backup-form-frase>
            <div class="col-12 col-md-4">
                <label class="form-label" for="backup-frase">Nova frase secreta</label>
                <input type="password" class="form-control" id="backup-frase" name="frase"
                       minlength="12" maxlength="200" autocomplete="new-password" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="backup-frase-confirmacao">Repita a frase</label>
                <input type="password" class="form-control" id="backup-frase-confirmacao"
                       name="frase_confirmation" minlength="12" maxlength="200"
                       autocomplete="new-password" required>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-outline-light w-100">Definir frase secreta</button>
            </div>
        </form>

        <h4 class="surface-title fs-6 mb-3">Agenda e retenção</h4>
        <form class="row g-3" data-backup-form-config>
            <div class="col-6 col-md-3">
                <label class="form-label" for="backup-horario">Horário diário</label>
                <input type="time" class="form-control" id="backup-horario" name="backup_horario" value="03:15">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="backup-diarios">Diários</label>
                <input type="number" class="form-control" id="backup-diarios"
                       name="backup_retencao_diarios" min="0" max="365" value="7">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="backup-semanais">Semanais</label>
                <input type="number" class="form-control" id="backup-semanais"
                       name="backup_retencao_semanais" min="0" max="104" value="4">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="backup-mensais">Mensais</label>
                <input type="number" class="form-control" id="backup-mensais"
                       name="backup_retencao_mensais" min="0" max="120" value="6">
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="backup-agendado" name="backup_agendado_habilitado" checked>
                    <label class="form-check-label" for="backup-agendado">Backup automático diário</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="backup-legado" name="backup_incluir_legado" checked>
                    <label class="form-check-label" for="backup-legado">Incluir os arquivos do sistema legado</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="backup-config" name="backup_incluir_config" checked>
                    <label class="form-check-label" for="backup-config">
                        Incluir a configuração (necessário para ler colunas criptografadas)
                    </label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Salvar configurações</button>
            </div>
        </form>
    @endif
</div>
