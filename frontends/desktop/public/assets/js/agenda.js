/**
 * Agenda — abertura de compromissos, edição e exclusão.
 *
 * A tela é renderizada no servidor (mesma escolha do resto do sistema); este
 * arquivo só cuida da interação com os dois modais. Cada item do calendário e
 * da lista carrega o próprio JSON em data-agenda-item, então abrir o detalhe
 * não custa nenhuma requisição.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Grade horária: posição inicial da rolagem
    // ------------------------------------------------------------------
    // A grade cobre 24 horas, mas abrir à meia-noite obriga a rolar oito horas
    // vazias antes de ver qualquer coisa. Rola para o primeiro compromisso do
    // período; sem compromisso, para o começo do expediente.
    const FALLBACK_HOUR = 7;

    function positionTimeGrid() {
        const scroller = document.querySelector('.agenda-timegrid-scroll');
        const body = document.querySelector('.agenda-timegrid-body');

        if (!scroller || !body) {
            return;
        }

        const slots = body.querySelectorAll('.agenda-timegrid-event-slot');
        let topPercent = (FALLBACK_HOUR / 24) * 100;

        if (slots.length > 0) {
            const earliest = Math.min(...Array.from(slots, (slot) => parseFloat(slot.style.top) || 0));
            topPercent = Math.min(topPercent, earliest);
        }

        // Uma fração de hora acima do alvo, para o bloco não colar no cabeçalho.
        const offset = (body.offsetHeight * topPercent) / 100 - 12;
        scroller.scrollTop = Math.max(0, offset);
    }

    positionTimeGrid();

    const formModal = document.getElementById('agendaFormModal');
    const detalheModal = document.getElementById('agendaDetalheModal');

    if (!formModal || !detalheModal) {
        return;
    }

    const form = document.getElementById('agendaForm');
    const methodField = document.getElementById('agendaFormMethod');
    const modalTitle = document.getElementById('agendaFormModalLabel');
    const managedNotice = document.getElementById('agendaManagedNotice');
    const deleteButton = document.getElementById('agendaDeleteButton');
    const deleteForm = document.getElementById('agendaDeleteForm');

    const fields = {
        titulo: document.getElementById('agendaTitulo'),
        data: document.getElementById('agendaData'),
        hora: document.getElementById('agendaHora'),
        prioridade: document.getElementById('agendaPrioridade'),
        lembrete: document.getElementById('agendaLembrete'),
        descricao: document.getElementById('agendaDescricao'),
    };

    const storeAction = form.getAttribute('action');
    let currentItem = null;

    const tipoLabels = {
        manual: 'Lembrete',
        conta_pagar: 'Conta a pagar',
        conta_receber: 'Conta a receber',
        retorno_pos_servico: 'Retorno pós-serviço',
        prazo_os: 'Prazo de reparo',
        cobranca_os: 'Cobrança automática',
    };

    function parseItem(button) {
        try {
            return JSON.parse(button.getAttribute('data-agenda-item') || '{}');
        } catch (error) {
            return null;
        }
    }

    function resetForm() {
        currentItem = null;
        form.setAttribute('action', storeAction);
        methodField.value = 'POST';
        modalTitle.textContent = 'Novo compromisso';
        managedNotice.classList.add('d-none');
        deleteButton.classList.add('d-none');

        fields.titulo.value = '';
        fields.titulo.readOnly = false;
        fields.data.value = new Date().toISOString().slice(0, 10);
        fields.data.readOnly = false;
        fields.hora.value = '';
        fields.hora.readOnly = false;
        fields.prioridade.value = 'normal';
        fields.lembrete.value = '30';
        fields.descricao.value = '';
    }

    function fillForm(item) {
        currentItem = item;
        form.setAttribute('action', form.dataset.updateTemplate.replace('__ID__', item.id));
        methodField.value = 'PATCH';
        modalTitle.textContent = 'Editar compromisso';

        fields.titulo.value = item.titulo || '';
        fields.data.value = item.data || '';
        fields.hora.value = item.hora || '';
        fields.prioridade.value = item.prioridade || 'normal';
        fields.lembrete.value = item.lembrete_minutos === null || item.lembrete_minutos === undefined
            ? ''
            : String(item.lembrete_minutos);
        fields.descricao.value = item.descricao || '';

        // Item gerido: o backend ignora alterações de data/título, então
        // bloquear os campos aqui evita a promessa falsa de que a edição valeu.
        const managed = Boolean(item.gerido);
        managedNotice.classList.toggle('d-none', !managed);
        fields.titulo.readOnly = managed;
        fields.data.readOnly = managed;
        fields.hora.readOnly = managed;

        // Excluir só faz sentido no que não volta sozinho na próxima
        // reconciliação.
        deleteButton.classList.toggle('d-none', managed);
    }

    function formatWhen(item) {
        if (!item.data) {
            return '';
        }

        const [year, month, day] = item.data.split('-');
        const dia = `${day}/${month}/${year}`;

        return item.hora ? `${dia} às ${item.hora}` : `${dia} — dia inteiro`;
    }

    function fillDetalhe(item) {
        currentItem = item;

        document.getElementById('agendaDetalheTipo').textContent = tipoLabels[item.tipo] || 'Compromisso';
        document.getElementById('agendaDetalheTitulo').textContent = item.titulo || '';
        document.getElementById('agendaDetalheQuando').textContent = formatWhen(item);

        const descricao = document.getElementById('agendaDetalheDescricao');
        descricao.textContent = item.descricao || 'Sem observações.';
        descricao.classList.toggle('text-muted', !item.descricao);

        const status = document.getElementById('agendaDetalheStatus');
        if (item.status === 'concluido') {
            status.textContent = 'Concluído';
        } else if (item.atrasado) {
            status.textContent = 'Atrasado';
        } else {
            status.textContent = 'Pendente';
        }

        // Atalhos para a origem: da agenda o usuário chega direto na OS ou no
        // cliente sem precisar procurar.
        const links = document.getElementById('agendaDetalheLinks');
        links.innerHTML = '';
        if (item.os_id) {
            links.appendChild(buildLink(`/os/${item.os_id}`, 'bi-clipboard-check', 'Abrir OS'));
        }
        if (item.cliente_id) {
            links.appendChild(buildLink(`/clientes/${item.cliente_id}`, 'bi-person', 'Abrir cliente'));
        }

        const toggleForm = document.getElementById('agendaDetalheToggleForm');
        const toggleButton = document.getElementById('agendaDetalheToggleButton');
        const concluido = item.status === 'concluido';

        toggleForm.setAttribute(
            'action',
            (concluido ? toggleForm.dataset.reopenTemplate : toggleForm.dataset.completeTemplate)
                .replace('__ID__', item.id)
        );
        toggleButton.innerHTML = concluido
            ? '<i class="bi bi-arrow-counterclockwise me-1"></i>Reabrir'
            : '<i class="bi bi-check-lg me-1"></i>Concluir';
    }

    function buildLink(href, icon, label) {
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.className = 'btn btn-outline-light btn-sm';
        anchor.innerHTML = `<i class="bi ${icon} me-1"></i>${label}`;
        // Sem esta marca, o guard de sessão do desktop interpreta a saída da
        // página como "navegador fechado" e desloga o usuário.
        anchor.addEventListener('click', function () {
            window.erpMarkInternalNavigation?.();
        });

        return anchor;
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-agenda-open]');
        if (!trigger) {
            return;
        }

        const item = parseItem(trigger);
        if (!item) {
            return;
        }

        fillDetalhe(item);
        window.bootstrap.Modal.getOrCreateInstance(detalheModal).show();
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-agenda-new]')) {
            resetForm();
        }
    });

    document.getElementById('agendaDetalheEditar')?.addEventListener('click', function () {
        if (!currentItem) {
            return;
        }

        const item = currentItem;
        window.bootstrap.Modal.getOrCreateInstance(detalheModal).hide();
        fillForm(item);
        window.bootstrap.Modal.getOrCreateInstance(formModal).show();
    });

    deleteButton?.addEventListener('click', function () {
        if (!currentItem || !window.confirm('Excluir este compromisso? A ação não pode ser desfeita.')) {
            return;
        }

        deleteForm.setAttribute('action', deleteForm.dataset.destroyTemplate.replace('__ID__', currentItem.id));
        deleteForm.submit();
    });

    formModal.addEventListener('hidden.bs.modal', resetForm);
})();
