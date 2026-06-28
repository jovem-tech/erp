@extends('layouts.app')

@section('content')
    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <p class="desktop-eyebrow">Ajuda</p>
                <h2 class="surface-title mb-1">Cadastro de equipamento</h2>
                <p class="surface-subtitle mb-0">Referência operacional rápida para o fluxo de novo equipamento no desktop.</p>
            </div>
            <a href="{{ route('equipments.create') }}" class="btn btn-outline-light">
                <i class="bi bi-arrow-left me-2"></i>
                Voltar ao cadastro
            </a>
        </div>

        <div class="surface-list">
            <div class="surface-list-item">
                <strong>1. Informações</strong>
                <span>Selecione cliente, tipo, marca, modelo, série ou IMEI, defina a senha e preencha os campos técnicos quando aplicável.</span>
            </div>
            <div class="surface-list-item">
                <strong>2. Cor</strong>
                <span>Use o seletor principal, o nome manual da cor e as sugestões rápidas. O preview ajuda a validar o resultado antes de salvar.</span>
            </div>
            <div class="surface-list-item">
                <strong>3. Fotos</strong>
                <span>Use galeria ou câmera. O preview local permite recorte, definição da principal e remoção antes do envio final.</span>
            </div>
            <div class="surface-list-item">
                <strong>4. Coletor remoto</strong>
                <span>Gere o código de pareamento, envie o snapshot do agente e importe os dados para preencher o painel técnico sem depender do servidor local.</span>
            </div>
        </div>
    </section>
@endsection
