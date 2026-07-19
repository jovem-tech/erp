@if ($desktopSignaturePending ?? false)
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-3" role="alert">
        <div>
            <strong><i class="bi bi-pen me-2"></i>Cadastre sua assinatura</strong>
            <div class="small">Documentos emitidos em seu nome exigem uma assinatura vinculada e protegida pela sua senha.</div>
        </div>
        <a href="{{ route('profile.edit') }}" class="btn btn-sm btn-primary">Cadastrar agora</a>
    </div>
@endif
