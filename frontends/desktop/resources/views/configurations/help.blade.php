@extends('layouts.app')

@section('content')
    <section class="desktop-page-stack">
        <div class="desktop-page-hero">
            <div>
                <h2>Ajuda das integrações</h2>
                <p>Guia rápido para entender os campos, botões e diagnósticos da tela de integrações do desktop.</p>
            </div>

            <a href="{{ route('configurations.integrations.index') }}" class="btn btn-outline-info rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>Voltar para integrações
            </a>
        </div>

        <section class="desktop-grid desktop-grid-two">
            <article class="surface-card">
                <div class="surface-card-header">
                    <div>
                        <h3 class="surface-title">O que esta tela controla</h3>
                        <p class="surface-subtitle">Tudo aqui conversa com a API central e não expõe token nem caminho físico ao navegador.</p>
                    </div>
                </div>

                <div class="surface-list">
                    <div class="surface-list-item">
                        <strong>Canal direto</strong>
                        <span>Escolhe entre API local, API Linux, Menuia, Evolution ou Webhook para o fluxo principal do WhatsApp.</span>
                    </div>
                    <div class="surface-list-item">
                        <strong>Canal massa</strong>
                        <span>Define o provedor usado para envios em lote, mantendo a expansão futura já prevista.</span>
                    </div>
                    <div class="surface-list-item">
                        <strong>Gateway local e Linux</strong>
                        <span>Campos de URL, token, origem e timeout para validar comunicação com o serviço de WhatsApp do ambiente.</span>
                    </div>
                    <div class="surface-list-item">
                        <strong>Webhook e fallback</strong>
                        <span>Configuração para receber eventos inbound e integrar com serviços externos quando necessário.</span>
                    </div>
                </div>
            </article>

            <article class="surface-card">
                <div class="surface-card-header">
                    <div>
                        <h3 class="surface-title">Botões de diagnóstico</h3>
                        <p class="surface-subtitle">Ações rápidas para validar a camada de integração sem sair da tela.</p>
                    </div>
                </div>

                <div class="surface-list">
                    <div class="surface-list-item">
                        <strong>Testar conexão</strong>
                        <span>Executa a validação de conectividade do provedor configurado.</span>
                    </div>
                    <div class="surface-list-item">
                        <strong>Enviar mensagem de teste</strong>
                        <span>Dispara uma mensagem controlada para o telefone de teste cadastrado.</span>
                    </div>
                    <div class="surface-list-item">
                        <strong>Self-check inbound</strong>
                        <span>Verifica a rota de entrada e a consistência da integração local ou Linux.</span>
                    </div>
                    <div class="surface-list-item">
                        <strong>Status, QR, reiniciar e desconectar</strong>
                        <span>Consultam e operam o gateway em uso quando o ambiente selecionado suporta essa camada.</span>
                    </div>
                </div>
            </article>
        </section>
    </section>
@endsection
