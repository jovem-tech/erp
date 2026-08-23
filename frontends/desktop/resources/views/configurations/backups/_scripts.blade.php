<script>
(function () {
    const raiz = document.getElementById('backup-app');
    if (!raiz) { return; }

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const podeBaixar = raiz.dataset.podeBaixar === '1';
    const podeExcluir = raiz.dataset.podeExcluir === '1';
    const podeRestaurar = raiz.dataset.podeRestaurar === '1';

    let temporizador = null;

    const chamar = async (url, metodo = 'POST', corpo = null) => {
        const resposta = await fetch(url, {
            method: metodo,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: corpo ? JSON.stringify(corpo) : null,
        });
        const json = await resposta.json().catch(() => ({}));
        if (!resposta.ok || json.ok === false) {
            throw new Error(json.mensagem || 'Não foi possível concluir a operação.');
        }
        return json.data || {};
    };

    const bytes = (n) => {
        if (!n) { return '—'; }
        const unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0; let v = Number(n);
        while (v >= 1024 && i < unidades.length - 1) { v /= 1024; i += 1; }
        return v.toFixed(1) + ' ' + unidades[i];
    };

    const dataHora = (iso) => {
        if (!iso) { return '—'; }
        return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    };

    const selo = (backup) => {
        const mapa = {
            concluido: 'success',
            concluido_com_avisos: 'warning',
            executando: 'info',
            pendente: 'secondary',
            falhou: 'danger',
            expirado: 'secondary',
            ausente: 'danger',
        };
        return `<span class="badge text-bg-${mapa[backup.status] || 'secondary'}">${backup.status_label || ''}</span>`;
    };

    const linha = (b) => {
        const acoes = [];

        if (podeBaixar && b.pode_restaurar) {
            acoes.push(`<button type="button" class="btn btn-sm btn-outline-light" data-backup-baixar="${b.uuid}">
                <i class="bi bi-download"></i></button>`);
        }
        if (podeRestaurar && b.pode_restaurar) {
            acoes.push(`<button type="button" class="btn btn-sm btn-outline-light" data-backup-verificar="${b.uuid}">
                <i class="bi bi-shield-check"></i></button>`);
        }
        if (podeExcluir) {
            // Backups do cron de root vivem num diretório que o sistema não
            // escreve: o botão fica desabilitado com a explicação, em vez de
            // oferecer uma ação que falharia.
            const desabilitado = b.pode_excluir ? '' : 'disabled';
            const motivo = b.pode_excluir
                ? 'Excluir'
                : (b.protegido ? 'Backup fixado' : 'Criado fora do sistema — a retenção é do cron do servidor');
            acoes.push(`<button type="button" class="btn btn-sm btn-outline-danger" ${desabilitado}
                title="${motivo}" data-backup-excluir="${b.uuid}"><i class="bi bi-trash"></i></button>`);
        }

        const avisos = (b.avisos || []).length
            ? `<i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                  title="${(b.avisos || []).map(a => (a.mensagem || '').replace(/"/g, "'")).join(' | ')}"></i>`
            : '';

        const conteudo = b.conteudo === 'completo'
            ? '<span class="badge text-bg-success">Completo</span>'
            : '<span class="badge text-bg-secondary" title="Não contém imagens nem documentos">Somente banco</span>';

        return `<tr>
            <td>${dataHora(b.criado_em)}</td>
            <td><span class="badge text-bg-dark">${b.origem_label || ''}</span></td>
            <td>${conteudo}</td>
            <td class="text-end">${bytes(b.tamanho_bytes)}</td>
            <td>${selo(b)}${avisos}</td>
            <td class="text-end"><div class="btn-group">${acoes.join('')}</div></td>
        </tr>`;
    };

    const desenhar = (dados) => {
        const resumo = dados.resumo || {};
        const lista = dados.backups || [];

        raiz.querySelector('[data-backup-resumo-completo]').textContent =
            resumo.ultimo_completo ? dataHora(resumo.ultimo_completo.criado_em) : 'nunca';
        raiz.querySelector('[data-backup-resumo-agenda]').textContent =
            resumo.agendamento_ativo ? (resumo.agendado_para || '—') : 'desligado';
        raiz.querySelector('[data-backup-resumo-total]').textContent = resumo.total ?? 0;
        raiz.querySelector('[data-backup-resumo-espaco]').textContent = bytes(resumo.bytes_ocupados);

        raiz.querySelector('[data-backup-alerta-incompleto]')
            .classList.toggle('d-none', !resumo.alerta_sem_backup_completo);

        const alertaAmbiente = raiz.querySelector('[data-backup-alerta-ambiente]');
        const erros = resumo.ambiente?.erros || [];
        alertaAmbiente.classList.toggle('d-none', erros.length === 0);
        alertaAmbiente.innerHTML = erros.map(e => `<div>${e}</div>`).join('');

        const estadoFrase = raiz.querySelector('[data-backup-frase-estado]');
        if (estadoFrase) {
            estadoFrase.textContent = resumo.frase_configurada ? 'configurada' : 'NÃO configurada';
        }

        const corpo = raiz.querySelector('[data-backup-lista]');
        corpo.innerHTML = lista.length
            ? lista.map(linha).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-4">Nenhum backup catalogado.</td></tr>';

        const emAndamento = resumo.em_andamento;
        const painelProgresso = raiz.querySelector('[data-backup-progresso]');
        painelProgresso.classList.toggle('d-none', !emAndamento);

        if (emAndamento) {
            raiz.querySelector('[data-backup-progresso-etapa]').textContent = emAndamento.etapa_atual || 'Processando…';
            raiz.querySelector('[data-backup-progresso-percentual]').textContent = (emAndamento.progresso_percentual || 0) + '%';
            raiz.querySelector('[data-backup-progresso-barra]').style.width = (emAndamento.progresso_percentual || 0) + '%';
            agendarAtualizacao(3000);
        } else if (temporizador) {
            clearTimeout(temporizador);
            temporizador = null;
        }
    };

    const agendarAtualizacao = (ms) => {
        if (temporizador) { clearTimeout(temporizador); }
        temporizador = setTimeout(atualizar, ms);
    };

    const atualizar = async () => {
        try {
            desenhar(await chamar(raiz.dataset.urlDados, 'GET'));
        } catch (erro) {
            console.error('[backup]', erro);
        }
    };

    raiz.addEventListener('click', async (evento) => {
        const alvo = evento.target.closest('[data-backup-acao], [data-backup-baixar], [data-backup-verificar], [data-backup-excluir]');
        if (!alvo || alvo.disabled) { return; }

        try {
            if (alvo.dataset.backupAcao === 'gerar') {
                await chamar(raiz.dataset.urlGerar);
                Swal.fire({
                    icon: 'success',
                    title: 'Backup na fila',
                    text: 'A cópia começa em até um minuto e o progresso aparece aqui.',
                });
                agendarAtualizacao(1000);
                return;
            }

            if (alvo.dataset.backupAcao === 'varrer') {
                const r = await chamar(raiz.dataset.urlVarrer);
                Swal.fire({
                    icon: 'success',
                    title: 'Catálogo sincronizado',
                    text: `${r.catalogados || 0} novo(s), ${r.atualizados || 0} atualizado(s), ${r.ausentes || 0} ausente(s).`,
                });
                await atualizar();
                return;
            }

            if (alvo.dataset.backupBaixar) {
                const r = await chamar(`/configuracoes/backups/${alvo.dataset.backupBaixar}/link`);
                // O navegador busca o arquivo direto do backend por URL
                // assinada: 130 MB não passam pelo painel.
                window.location.href = r.url;
                return;
            }

            if (alvo.dataset.backupVerificar) {
                Swal.fire({ title: 'Verificando…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
                const r = await chamar(`/configuracoes/backups/${alvo.dataset.backupVerificar}/verificar`);
                Swal.fire({
                    icon: r.ok ? 'success' : 'error',
                    title: r.ok ? 'Integridade confirmada' : 'Problemas encontrados',
                    html: r.ok
                        ? `${(r.membros || []).length} parte(s) conferida(s).`
                        : (r.problemas || []).map(p => `<div>${p}</div>`).join(''),
                });
                return;
            }

            if (alvo.dataset.backupExcluir) {
                const confirmacao = await Swal.fire({
                    icon: 'warning',
                    title: 'Excluir este backup?',
                    text: 'O arquivo será apagado do disco. O registro permanece no histórico.',
                    showCancelButton: true,
                    confirmButtonText: 'Excluir',
                    cancelButtonText: 'Cancelar',
                });
                if (!confirmacao.isConfirmed) { return; }
                await chamar(`/configuracoes/backups/${alvo.dataset.backupExcluir}`, 'DELETE');
                await atualizar();
            }
        } catch (erro) {
            Swal.fire({ icon: 'error', title: 'Não foi possível concluir', text: erro.message });
        }
    });

    raiz.querySelector('[data-backup-form-frase]')?.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        const dados = Object.fromEntries(new FormData(evento.target));

        if (dados.frase !== dados.frase_confirmation) {
            Swal.fire({ icon: 'error', title: 'As frases não conferem' });
            return;
        }

        const confirmacao = await Swal.fire({
            icon: 'warning',
            title: 'Confirmar a frase secreta?',
            html: '<strong>Guarde esta frase fora do servidor.</strong><br>'
                + 'Sem ela, nenhum backup poderá ser restaurado — por ninguém.',
            showCancelButton: true,
            confirmButtonText: 'Definir frase',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmacao.isConfirmed) { return; }

        try {
            await chamar(raiz.dataset.urlFrase, 'POST', dados);
            evento.target.reset();
            Swal.fire({ icon: 'success', title: 'Frase secreta definida' });
            await atualizar();
        } catch (erro) {
            Swal.fire({ icon: 'error', title: 'Não foi possível definir', text: erro.message });
        }
    });

    raiz.querySelector('[data-backup-form-config]')?.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        const formulario = evento.target;
        const dados = Object.fromEntries(new FormData(formulario));

        ['backup_agendado_habilitado', 'backup_incluir_legado', 'backup_incluir_config'].forEach((campo) => {
            dados[campo] = formulario.querySelector(`[name="${campo}"]`)?.checked ? 1 : 0;
        });

        try {
            await chamar(raiz.dataset.urlConfiguracoes, 'POST', dados);
            Swal.fire({ icon: 'success', title: 'Configurações salvas' });
            await atualizar();
        } catch (erro) {
            Swal.fire({ icon: 'error', title: 'Não foi possível salvar', text: erro.message });
        }
    });

    // Só carrega quando a aba entra em cena: evita uma chamada à API em toda
    // visita a Configurações, mesmo quem nunca abre Backup.
    const observador = new MutationObserver(() => {
        const painel = raiz.closest('.config-subpanel');
        if (painel?.classList.contains('is-active') && !raiz.dataset.carregado) {
            raiz.dataset.carregado = '1';
            atualizar();
        }
    });

    const painelAtual = raiz.closest('.config-subpanel');
    if (painelAtual) {
        observador.observe(painelAtual, { attributes: true, attributeFilter: ['class'] });
        if (painelAtual.classList.contains('is-active')) {
            raiz.dataset.carregado = '1';
            atualizar();
        }
    }
})();
</script>
