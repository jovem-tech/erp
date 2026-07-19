@extends('layouts.app')

@section('content')
    <section class="desktop-grid desktop-grid-two">
        <article class="desktop-form-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Configurações do perfil</h2>
                    <p class="surface-subtitle">Atualize o nome exibido no desktop.</p>
                </div>
            </div>

            <form method="post" action="{{ route('profile.update') }}" class="desktop-form-stack">
                @csrf
                @method('patch')

                <div>
                    <label for="profileName">Nome</label>
                    <input
                        type="text"
                        id="profileName"
                        name="nome"
                        class="form-control"
                        value="{{ old('nome', $profile['nome'] ?? '') }}"
                        maxlength="100"
                        required
                    >
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        Salvar alterações
                    </button>
                </div>
            </form>
        </article>

        <article class="desktop-form-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Trocar senha</h2>
                    <p class="surface-subtitle">Após salvar, o desktop pedirá novo login.</p>
                </div>
            </div>

            <form method="post" action="{{ route('profile.password.update') }}" class="desktop-form-stack">
                @csrf
                @method('patch')

                <div>
                    <label for="currentPassword">Senha atual</label>
                    <div class="input-shell">
                        <input type="password" id="currentPassword" name="current_password" class="form-control" minlength="6" required>
                        <button type="button" class="password-toggle" data-password-toggle="currentPassword" aria-label="Mostrar senha atual">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div>
                    <label for="newPassword">Nova senha</label>
                    <div class="input-shell">
                        <input type="password" id="newPassword" name="password" class="form-control" minlength="8" required>
                        <button type="button" class="password-toggle" data-password-toggle="newPassword" aria-label="Mostrar nova senha">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div>
                    <label for="newPasswordConfirmation">Confirmar nova senha</label>
                    <div class="input-shell">
                        <input type="password" id="newPasswordConfirmation" name="password_confirmation" class="form-control" minlength="8" required>
                        <button type="button" class="password-toggle" data-password-toggle="newPasswordConfirmation" aria-label="Mostrar confirmação">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-soft">
                        Atualizar senha
                    </button>
                </div>
            </form>
        </article>

        <article class="desktop-form-card signature-profile-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Minha assinatura</h2>
                    <p class="surface-subtitle">Cadastre uma imagem ou assine diretamente na tela. A assinatura fica privada e será vinculada aos documentos emitidos por você.</p>
                </div>
                <span class="desktop-chip {{ ($signature['registered'] ?? false) ? 'desktop-chip-success' : '' }}">
                    {{ ($signature['registered'] ?? false) ? 'Cadastrada' : 'Pendente' }}
                </span>
            </div>

            <div class="signature-profile-layout">
                <div class="signature-current">
                    <span class="signature-field-label">Assinatura atual</span>
                    <div class="signature-current-preview">
                        @if ($signature['registered'] ?? false)
                            <img src="{{ route('profile.signature.image', ['v' => $signature['signature']['updated_at'] ?? '']) }}" alt="Sua assinatura cadastrada">
                        @else
                            <i class="bi bi-pen"></i>
                            <span>Nenhuma assinatura cadastrada</span>
                        @endif
                    </div>
                    <p class="small text-secondary mb-0">Ao substituir, documentos antigos continuam ligados ao hash da assinatura usada na emissão.</p>
                </div>

                <form method="post" action="{{ route('profile.signature.update') }}" enctype="multipart/form-data" class="signature-enroll-form" data-signature-form>
                    @csrf
                    <input type="hidden" name="signature_origin" value="desenho" data-signature-origin>
                    <input type="hidden" name="signature_data" value="" data-signature-data>

                    <div class="signature-mode-tabs" role="tablist" aria-label="Forma de cadastro da assinatura">
                        <button type="button" class="btn btn-primary" data-signature-mode="desenho" aria-pressed="true">
                            <i class="bi bi-pencil me-2"></i>Assinar na tela
                        </button>
                        <button type="button" class="btn btn-outline-light" data-signature-mode="upload" aria-pressed="false">
                            <i class="bi bi-upload me-2"></i>Importar imagem
                        </button>
                    </div>

                    <div data-signature-panel="desenho">
                        <div class="signature-canvas-shell">
                            <canvas data-signature-canvas aria-label="Área para desenhar a assinatura"></canvas>
                            <span class="signature-canvas-hint" data-signature-hint>Assine aqui com o dedo, mouse ou Apple Pencil</span>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-light" data-signature-undo><i class="bi bi-arrow-counterclockwise me-1"></i>Desfazer</button>
                            <button type="button" class="btn btn-sm btn-outline-light" data-signature-clear><i class="bi bi-eraser me-1"></i>Limpar</button>
                        </div>
                    </div>

                    <div class="d-none" data-signature-panel="upload">
                        <label for="signatureFile">Arquivo PNG, JPG ou WebP</label>
                        <input type="file" id="signatureFile" name="signature_file" class="form-control" accept="image/png,image/jpeg,image/webp" data-signature-file>
                        <p class="small text-secondary mt-2 mb-0">Máximo de 2 MB. O sistema converte para PNG e remove metadados do arquivo.</p>
                    </div>

                    <div>
                        <label for="signatureCurrentPassword">Confirme sua senha atual</label>
                        <div class="input-shell">
                            <input type="password" id="signatureCurrentPassword" name="current_password" class="form-control" maxlength="200" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-password-toggle="signatureCurrentPassword" aria-label="Mostrar senha atual"><i class="bi bi-eye"></i></button>
                        </div>
                        <p class="small text-secondary mt-2 mb-0">A confirmação evita que outra pessoa cadastre uma assinatura em uma sessão deixada aberta.</p>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check me-2"></i>Salvar assinatura</button>
                    </div>
                </form>
            </div>
        </article>

        <article class="desktop-form-card signature-profile-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-title">Documentos aguardando assinatura</h2>
                    <p class="surface-subtitle">Pendências atribuídas a você e solicitações que você encaminhou para outro responsável.</p>
                </div>
                <span class="desktop-chip">{{ count($pendingSignatures ?? []) }} pendente(s)</span>
            </div>

            @forelse (($pendingSignatures ?? []) as $pending)
                <div class="signature-pending-row">
                    <div>
                        <strong>{{ $pending['document_type'] ?? 'Documento' }} · {{ $pending['order_number'] ?? 'OS' }}</strong>
                        <div class="small text-secondary">
                            Responsável: {{ $pending['responsible_user'] ?? 'Não informado' }}
                            @if (!empty($pending['requested_by'])) · Solicitado por {{ $pending['requested_by'] }} @endif
                        </div>
                    </div>
                    <div class="signature-pending-action">
                        <a href="{{ route('document-signatures.review', (int) ($pending['id'] ?? 0)) }}" class="btn btn-primary btn-sm">
                            <i class="bi bi-eye me-1"></i>Visualizar e analisar
                        </a>
                    </div>
                </div>
            @empty
                <div class="signature-pending-empty">
                    <i class="bi bi-check2-circle"></i>
                    <span>Nenhum documento aguardando assinatura.</span>
                </div>
            @endforelse
        </article>
    </section>

    <style>
        .signature-profile-card { grid-column: 1 / -1; }
        .signature-profile-layout { display: grid; grid-template-columns: minmax(240px, .75fr) minmax(420px, 1.4fr); gap: 24px; }
        .signature-field-label { display: block; font-weight: 700; margin-bottom: 8px; }
        .signature-current-preview { min-height: 150px; border: 1px dashed #c9d8ef; border-radius: 16px; background: #f8fbff; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 8px; margin-bottom: 10px; color: #71819b; }
        .signature-current-preview img { display: block; max-width: 90%; max-height: 120px; object-fit: contain; }
        .signature-enroll-form { display: grid; gap: 16px; }
        .signature-mode-tabs { display: flex; flex-wrap: wrap; gap: 8px; }
        .signature-canvas-shell { position: relative; min-height: 220px; border: 2px dashed #b8cae8; border-radius: 16px; overflow: hidden; background: #fff; }
        .signature-canvas-shell canvas { display: block; width: 100%; height: 220px; touch-action: none; cursor: crosshair; }
        .signature-canvas-hint { position: absolute; inset: 0; display: grid; place-items: center; color: #8b98aa; pointer-events: none; }
        .signature-pending-row { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 16px 0; border-top: 1px solid #e4ecf7; }
        .signature-pending-action { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .signature-pending-action .form-control { width: min(230px, 100%); }
        .signature-pending-empty { min-height: 110px; border: 1px dashed #c9d8ef; border-radius: 16px; display: grid; place-items: center; align-content: center; gap: 8px; color: #71819b; }
        @media (max-width: 900px) { .signature-profile-layout { grid-template-columns: 1fr; } }
        @media (max-width: 720px) { .signature-pending-row { align-items: stretch; flex-direction: column; } .signature-pending-action { justify-content: stretch; } .signature-pending-action .form-control, .signature-pending-action .btn { width: 100%; } }
    </style>
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/profile-signature.js') }}?v={{ filemtime(public_path('assets/js/profile-signature.js')) }}"></script>
@endsection
