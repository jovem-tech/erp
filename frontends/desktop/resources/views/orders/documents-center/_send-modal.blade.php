<div class="modal fade" id="docSendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <h5 class="modal-title">Enviar para cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small mb-3" id="docSendModalCount">Nenhum documento selecionado.</p>

                <div class="alert-shell alert-shell-danger d-none mb-3" id="docSendModalErrors"></div>

                <form id="docSendModalForm">
                    <div class="row g-3">
                        <div class="col-lg-3">
                            <label class="form-label">Canal</label>
                            <select name="channel" class="form-select" id="docSendChannel" data-select2="false">
                                <option value="whatsapp">WhatsApp</option>
                                <option value="email">E-mail</option>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Formato</label>
                            <select name="format" class="form-select" data-select2="false">
                                <option value="a4">A4</option>
                                <option value="80mm">80mm</option>
                            </select>
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label">Template da mensagem</label>
                            <select name="template_code"
                                    class="form-select"
                                    id="docSendTemplate"
                                    data-select2-placeholder="Selecione o template da mensagem">
                                <option value="">Mensagem automática do documento</option>
                                @foreach ($whatsappTemplates as $template)
                                    @php
                                        $templateCode = trim((string) ($template['code'] ?? ''));
                                        $templateName = trim((string) ($template['name'] ?? ''));
                                        $templateEvent = trim((string) ($template['event'] ?? ''));
                                    @endphp
                                    @if ($templateCode !== '' && $templateName !== '')
                                        <option value="{{ $templateCode }}"
                                                data-rendered-message="{{ e((string) ($template['rendered_message'] ?? '')) }}">
                                            {{ $templateName }}{{ $templateEvent !== '' ? ' · ' . $templateEvent : '' }}
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Destino (editável)</label>
                            <input type="text" name="destino" class="form-control" id="docSendDestino" placeholder="Contato do cliente (editável)">
                            <div class="form-text" id="docSendModalDestinationHint"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mensagem final</label>
                            <textarea name="message" class="form-control" id="docSendMessage" rows="4" placeholder="Mensagem final sugerida pelo template (editável)"></textarea>
                        </div>
                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" value="1" id="docSendConfirmAlt" name="confirmar_destino_alternativo">
                        <label class="form-check-label" for="docSendConfirmAlt">
                            Confirmo explicitamente o uso do destino alternativo informado acima, se houver.
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="docSendModalForm" class="btn btn-primary" id="docSendModalSubmit">
                    <i class="bi bi-send me-2"></i>Enfileirar envio
                </button>
            </div>
        </div>
    </div>
</div>
