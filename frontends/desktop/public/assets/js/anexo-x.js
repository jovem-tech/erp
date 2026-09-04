/**
 * Tela do Anexo X: alternador de regime, os cinco modais da linha e o modal de
 * download.
 *
 * O resumo do ano inteiro — os doze meses nos DOIS regimes, com as dez linhas
 * de cada um — vem no bootstrap da página. Por isso trocar de regime e abrir
 * "Receitas brutas do mês" não fazem requisição nenhuma. O que é caro
 * (drill-down e ajustes) carrega sob demanda, ao abrir o modal.
 */
(function () {
    'use strict';

    var estado = window.__DESKTOP_ANEXO_X || null;
    var regimeAtual = estado ? estado.regime : 'competencia';

    var ROMANOS = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];
    var CALCULADAS = ['iii', 'vi', 'ix', 'x'];

    var ALERTAS = {
        peca_sem_nfe: ['Peça sem NF-e', 'warning'],
        servico_sem_nfse: ['Serviço sem NFS-e', 'warning'],
        documento_cancelado: ['Documento cancelado', 'danger'],
        documento_rascunho: ['Nota em rascunho', 'info'],
        documento_parcial: ['Documento parcial', 'info'],
        documento_excedente: ['Documento maior que a operação', 'danger'],
        valor_diverge_do_xml: ['Valor diverge do XML', 'danger'],
        tomador_pj_sem_documento: ['Tomador PJ sem documento', 'danger'],
        sem_classificacao_de_atividade: ['Sem atividade definida', 'secondary']
    };

    var TIPOS = { os: 'OS', venda: 'Venda', titulo: 'Lançamento', movimento: 'Baixa', devolucao: 'Devolução' };

    function moeda(valor) {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor) || 0);
    }

    function escapar(texto) {
        var div = document.createElement('div');
        div.textContent = texto === null || texto === undefined ? '' : String(texto);

        return div.innerHTML;
    }

    function dataCurta(iso) {
        if (!iso) {
            return '—';
        }

        var partes = String(iso).slice(0, 10).split('-');

        return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : '—';
    }

    function mesPorCompetencia(competencia) {
        if (!estado || !estado.resumo) {
            return null;
        }

        return estado.resumo.meses.find(function (mes) {
            return mes.competencia === competencia;
        }) || null;
    }

    function badges(alertas) {
        return (alertas || []).map(function (alerta) {
            var rotulo = ALERTAS[alerta] || [alerta, 'secondary'];

            return '<span class="badge text-bg-' + rotulo[1] + ' me-1">' + escapar(rotulo[0]) + '</span>';
        }).join('');
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function pedir(url, opcoes) {
        return fetch(url, Object.assign({
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
            },
            credentials: 'same-origin'
        }, opcoes || {})).then(function (resposta) {
            return resposta.json().then(function (corpo) {
                if (!resposta.ok || corpo.success === false) {
                    throw new Error(corpo.message || 'Não foi possível concluir a operação.');
                }

                return corpo;
            });
        });
    }

    // ------------------------------------------------- alternador de regime

    function trocarRegime(regime) {
        if (!estado || regime === regimeAtual) {
            return;
        }

        regimeAtual = regime;

        document.querySelectorAll('[data-anexo-x-regime]').forEach(function (botao) {
            var ativo = botao.dataset.anexoXRegime === regime;
            botao.classList.toggle('is-active', ativo);
            botao.setAttribute('aria-pressed', ativo ? 'true' : 'false');
        });

        var nota = document.querySelector('[data-anexo-x-nota-regime]');

        if (nota) {
            nota.textContent = regime === 'caixa' ? nota.dataset.notaCaixa : nota.dataset.notaCompetencia;
        }

        var totais = { total: 0, comercio: 0, industria: 0, servicos: 0, com_documento: 0, sem_documento: 0 };

        estado.resumo.meses.forEach(function (mes) {
            var linha = document.querySelector('[data-anexo-x-linha="' + mes.competencia + '"]');
            var dados = mes.regimes[regime];

            if (!linha || !dados) {
                return;
            }

            Object.keys(totais).forEach(function (campo) {
                var celula = linha.querySelector('[data-anexo-x-celula="' + campo + '"]');

                if (celula) {
                    celula.textContent = moeda(dados[campo]);
                }

                totais[campo] += Number(dados[campo]) || 0;
            });

            var marca = linha.querySelector('[data-anexo-x-marca-ajuste]');

            if (marca) {
                marca.style.display = Number(dados.ajuste_total) !== 0 ? '' : 'none';
            }

            var situacao = linha.querySelector('[data-anexo-x-celula="situacao"]');

            if (situacao) {
                situacao.innerHTML = situacaoHtml(mes, dados);
            }
        });

        Object.keys(totais).forEach(function (campo) {
            var celula = document.querySelector('[data-anexo-x-total="' + campo + '"]');

            if (celula) {
                celula.textContent = moeda(totais[campo]);
            }
        });

        // Os formulários de encerrar/reabrir precisam seguir o regime visível,
        // senão o operador congela uma apuração diferente da que está lendo.
        document.querySelectorAll('[data-anexo-x-form-encerrar] input[name="regime"], #modalReabrirAnexoX input[name="regime"], [data-anexo-x-download] input[name="regime"]')
            .forEach(function (campo) {
                campo.value = regime;
            });

        document.querySelectorAll('[data-anexo-x-pdf-mes]').forEach(function (link) {
            var url = new URL(link.href, window.location.origin);
            url.searchParams.set('regime', regime);
            link.href = url.toString();
        });
    }

    function situacaoHtml(mes, dados) {
        var fechamento = dados.fechamento;

        if (fechamento && fechamento.status === 'fechado') {
            var autor = fechamento.fechado_por ? fechamento.fechado_por.nome : '—';

            return '<span class="badge text-bg-success" title="Encerrada por ' + escapar(autor) + '">'
                + '<i class="bi bi-lock-fill me-1"></i>Encerrada v' + fechamento.versao + '</span>';
        }

        if (mes.futuro) {
            return '<span class="badge text-bg-light">Futuro</span>';
        }

        return '<span class="badge text-bg-secondary">Aberta</span>';
    }

    // ------------------------------------- modal 1: receitas brutas do mês

    function renderReceitas(competencia, regime) {
        var mes = mesPorCompetencia(competencia);
        var tabela = document.querySelector('[data-receitas-tabela]');

        if (!mes || !tabela) {
            return;
        }

        var dados = mes.regimes[regime];
        var rotulos = estado.resumo.rotulos;

        var blocos = [
            { titulo: 'Revenda de mercadorias (comércio)', chaves: ['i', 'ii', 'iii'], numerais: ['I', 'II', 'III'] },
            { titulo: 'Venda de produtos industrializados (indústria)', chaves: ['iv', 'v', 'vi'], numerais: ['IV', 'V', 'VI'] },
            { titulo: 'Prestação de serviços', chaves: ['vii', 'viii', 'ix'], numerais: ['VII', 'VIII', 'IX'] }
        ];

        var html = '';

        blocos.forEach(function (bloco) {
            html += '<thead><tr><th colspan="3" class="text-uppercase small">' + escapar(bloco.titulo) + '</th></tr></thead><tbody>';

            bloco.chaves.forEach(function (chave, indice) {
                var linha = dados.linhas[chave];
                var calculada = CALCULADAS.indexOf(chave) !== -1;
                var ajuste = Number(linha.ajuste) || 0;

                html += '<tr class="' + (calculada ? 'fw-semibold' : '') + '">'
                    + '<td style="width:60px;">' + bloco.numerais[indice] + '</td>'
                    + '<td>' + escapar(rotulos[chave]);

                if (ajuste !== 0) {
                    html += '<div class="surface-subtitle small">Calculado ' + moeda(linha.calculado)
                        + ' · ajuste ' + moeda(ajuste) + '</div>';
                }

                html += '</td><td class="text-end">' + moeda(linha.valor) + '</td></tr>';
            });

            html += '</tbody>';
        });

        html += '<tfoot><tr class="fw-bold fs-6"><td>X</td><td>' + escapar(rotulos.x)
            + '</td><td class="text-end">' + moeda(dados.linhas.x.valor) + '</td></tr></tfoot>';

        tabela.innerHTML = html;

        var deducoes = document.querySelector('[data-receitas-deducoes]');

        if (deducoes) {
            var texto = 'Já deduzidos: ' + moeda(dados.deducoes.descontos) + ' em descontos e '
                + moeda(dados.deducoes.devolucoes) + ' em devoluções.';

            if (Number(dados.ajuste_total) !== 0) {
                texto += ' Inclui ' + moeda(dados.ajuste_total) + ' em ajuste manual declarado.';
            }

            deducoes.textContent = texto;
        }
    }

    // ------------------------------------------ modal 5: operações do mês

    function renderOperacoes(dados, filtro) {
        var tabela = document.querySelector('[data-operacoes-tabela]');

        if (!tabela) {
            return;
        }

        var itens = (dados.drill_down || []).filter(function (item) {
            if (filtro === 'com') {
                return somaComDocumento(item) > 0;
            }

            if (filtro === 'sem') {
                return Number(item.sem_documento_total) > 0;
            }

            return true;
        });

        if (itens.length === 0) {
            tabela.innerHTML = '<tbody><tr><td class="text-center surface-subtitle py-4">Nenhuma operação neste filtro.</td></tr></tbody>';

            return;
        }

        var html = '<thead><tr><th>Origem</th><th>Data</th><th>Cliente</th><th class="text-end">Líquido</th>'
            + '<th class="text-end">Mercadoria</th><th class="text-end">Serviços</th><th>Documentos</th>'
            + '<th class="text-end">Detalhes</th></tr></thead><tbody>';

        itens.forEach(function (item, indice) {
            html += '<tr>'
                + '<td><span class="badge text-bg-secondary">' + escapar(TIPOS[item.tipo] || item.tipo) + '</span> '
                + escapar(item.referencia) + '</td>'
                + '<td>' + dataCurta(item.data) + '</td>'
                + '<td>' + escapar(item.cliente_nome || 'Consumidor final')
                + (item.tomador_pj ? ' <span class="badge text-bg-danger">PJ</span>' : '') + '</td>'
                + '<td class="text-end">' + moeda(item.liquido) + '</td>'
                + '<td class="text-end">' + moeda(item.comercio.total) + '</td>'
                + '<td class="text-end">' + moeda(item.servicos.total) + '</td>'
                + '<td>' + documentosHtml(item) + badges(item.alertas) + '</td>'
                + '<td class="text-end">'
                + '<button type="button" class="btn btn-sm btn-outline-light anexo-x-operacao-toggle" '
                + 'data-operacao-detalhes-botao="' + indice + '" aria-expanded="false" title="Ver mais detalhes da operação">'
                + '<i class="bi bi-chevron-down"></i></button>'
                + '</td>'
                + '</tr>'
                + '<tr class="d-none" data-operacao-detalhes-linha="' + indice + '">'
                + '<td colspan="8">' + detalhesHtml(item) + '</td>'
                + '</tr>';
        });

        tabela.innerHTML = html + '</tbody>';
    }

    function somaComDocumento(item) {
        return (Number(item.comercio.com_documento) || 0)
            + (Number(item.industria.com_documento) || 0)
            + (Number(item.servicos.com_documento) || 0);
    }

    function documentosHtml(item) {
        if (!item.documentos || item.documentos.length === 0) {
            return '<span class="surface-subtitle small">—</span> ';
        }

        return item.documentos.map(function (documento) {
            var marca = documento.status !== 'emitido'
                ? ' <span class="badge text-bg-warning">' + escapar(documento.status) + '</span>'
                : '';

            return '<div class="small">' + escapar(documento.tipo_label) + ' ' + escapar(documento.numero || '—') + marca + '</div>';
        }).join('');
    }

    function detalhesHtml(item) {
        var atividades = [['Comércio', item.comercio], ['Indústria', item.industria], ['Serviços', item.servicos]]
            .filter(function (par) {
                return par[1] && Number(par[1].total) > 0;
            });

        var linhasAtividade = atividades.map(function (par) {
            return '<tr><td>' + par[0] + '</td>'
                + '<td class="text-end">' + moeda(par[1].total) + '</td>'
                + '<td class="text-end">' + moeda(par[1].com_documento) + '</td>'
                + '<td class="text-end">' + moeda(par[1].sem_documento) + '</td></tr>';
        }).join('');

        var tabelaAtividades = atividades.length === 0 ? '' : '<table class="table table-sm mb-3">'
            + '<thead><tr><th>Atividade</th><th class="text-end">Total</th>'
            + '<th class="text-end">Com documento</th><th class="text-end">Sem documento</th></tr></thead>'
            + '<tbody>' + linhasAtividade + '</tbody></table>';

        var linhasDocumento = (item.documentos || []).map(function (documento) {
            var numero = escapar(documento.numero || '—') + (documento.serie ? '/' + escapar(documento.serie) : '');
            var divergencia = documento.diverge_do_xml
                ? ' <span class="badge text-bg-danger">Valor diverge do XML</span>'
                : '';

            return '<tr>'
                + '<td>' + escapar(documento.tipo_label) + '</td>'
                + '<td>' + numero + '</td>'
                + '<td>' + escapar(documento.status) + '</td>'
                + '<td>' + dataCurta(documento.emitido_em) + '</td>'
                + '<td class="text-end">' + moeda(documento.valor_total) + '</td>'
                + '<td>' + escapar(documento.tomador_nome || '—') + divergencia + '</td>'
                + '</tr>';
        }).join('');

        var tabelaDocumentos = linhasDocumento === ''
            ? '<p class="surface-subtitle small mb-0">Nenhum documento fiscal vinculado.</p>'
            : '<table class="table table-sm mb-0"><thead><tr><th>Tipo</th><th>Número</th><th>Status</th>'
                + '<th>Emitido em</th><th class="text-end">Valor</th><th>Tomador</th></tr></thead>'
                + '<tbody>' + linhasDocumento + '</tbody></table>';

        return '<div class="anexo-x-operacao-detalhes">'
            + '<div class="row g-3 mb-3">'
            + '<div class="col-sm-4"><span class="surface-subtitle small d-block">Bruto</span>' + moeda(item.bruto) + '</div>'
            + '<div class="col-sm-4"><span class="surface-subtitle small d-block">Desconto</span>' + moeda(item.desconto) + '</div>'
            + '<div class="col-sm-4"><span class="surface-subtitle small d-block">Documento do cliente</span>'
            + escapar(item.cliente_documento || '—') + '</div>'
            + '</div>'
            + tabelaAtividades
            + '<h6 class="surface-title">Documentos fiscais</h6>'
            + tabelaDocumentos
            + '</div>';
    }

    // ------------------------------------------ modal 3: editar o relatório

    function renderAjustes(dados) {
        var tabelaLinhas = document.querySelector('[data-editar-linhas]');
        var tabelaLancamentos = document.querySelector('[data-editar-lancamentos]');
        var bloqueado = document.querySelector('[data-editar-bloqueado]');
        var form = document.querySelector('[data-editar-form]');
        var mes = mesPorCompetencia(dados.competencia);

        if (bloqueado) {
            bloqueado.classList.toggle('d-none', !dados.ajustes.bloqueado);
        }

        if (form) {
            form.classList.toggle('d-none', dados.ajustes.bloqueado || !dados.pode_editar);
            form.querySelector('input[name="competencia"]').value = dados.competencia;
            form.querySelector('input[name="regime"]').value = dados.regime;
        }

        var select = document.querySelector('[data-editar-select-linha]');

        if (select && mes) {
            select.innerHTML = (dados.linhas_ajustaveis || []).map(function (chave) {
                return '<option value="' + chave + '">' + chave.toUpperCase() + ' — '
                    + escapar(estado.resumo.rotulos[chave]) + '</option>';
            }).join('');
        }

        if (tabelaLinhas && mes) {
            var linhas = mes.regimes[dados.regime].linhas;
            var html = '<thead><tr><th>Linha</th><th class="text-end">Calculado</th>'
                + '<th class="text-end">Ajuste</th><th class="text-end">Declarado</th></tr></thead><tbody>';

            ROMANOS.filter(function (chave) {
                return (dados.linhas_ajustaveis || []).indexOf(chave) !== -1;
            }).forEach(function (chave) {
                var linha = linhas[chave];
                var ajuste = Number(linha.ajuste) || 0;

                html += '<tr>'
                    + '<td>' + chave.toUpperCase() + ' — <span class="surface-subtitle small">'
                    + escapar(estado.resumo.rotulos[chave]) + '</span></td>'
                    + '<td class="text-end">' + moeda(linha.calculado) + '</td>'
                    + '<td class="text-end ' + (ajuste !== 0 ? 'text-warning fw-semibold' : '') + '">'
                    + (ajuste !== 0 ? moeda(ajuste) : '—') + '</td>'
                    + '<td class="text-end fw-semibold">' + moeda(linha.valor) + '</td>'
                    + '</tr>';
            });

            tabelaLinhas.innerHTML = html + '</tbody>';
        }

        if (tabelaLancamentos) {
            var lancamentos = [];

            Object.keys(dados.ajustes.por_linha || {}).forEach(function (chave) {
                dados.ajustes.por_linha[chave].forEach(function (item) {
                    lancamentos.push(item);
                });
            });

            if (lancamentos.length === 0) {
                tabelaLancamentos.innerHTML = '<tbody><tr><td class="surface-subtitle small py-3">Nenhum ajuste lançado nesta competência.</td></tr></tbody>';

                return;
            }

            var corpo = '<thead><tr><th>Linha</th><th class="text-end">Valor</th><th>Motivo</th>'
                + '<th>Quem / quando</th><th></th></tr></thead><tbody>';

            lancamentos.forEach(function (item) {
                var cancelado = !!item.cancelado_em;
                var autor = item.criado_por ? item.criado_por.nome : '—';

                corpo += '<tr class="' + (cancelado ? 'text-decoration-line-through opacity-50' : '') + '">'
                    + '<td>' + escapar(item.linha.toUpperCase()) + '</td>'
                    + '<td class="text-end">' + moeda(item.valor) + '</td>'
                    + '<td class="small">' + escapar(item.motivo)
                    + (cancelado ? '<div class="text-danger">Cancelado: ' + escapar(item.motivo_cancelamento || '') + '</div>' : '')
                    + '</td>'
                    + '<td class="small">' + escapar(autor) + '<br>' + dataCurta(item.criado_em) + '</td>'
                    + '<td class="text-end">';

                if (!cancelado && dados.pode_editar && !dados.ajustes.bloqueado) {
                    corpo += '<button type="button" class="btn btn-sm btn-outline-danger" data-cancelar-ajuste="' + item.id + '">'
                        + '<i class="bi bi-x-lg"></i></button>';
                }

                corpo += '</td></tr>';
            });

            tabelaLancamentos.innerHTML = corpo + '</tbody>';
        }
    }

    function carregarAjustes(competencia) {
        var url = new URL(estado.rotas.ajustes, window.location.origin);
        url.searchParams.set('competencia', competencia);
        url.searchParams.set('regime', regimeAtual);

        return pedir(url.toString()).then(renderAjustes);
    }

    // ------------------------------------------------------------ ligação

    document.addEventListener('DOMContentLoaded', function () {
        if (!estado) {
            return;
        }

        document.querySelectorAll('[data-anexo-x-regime]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                trocarRegime(botao.dataset.anexoXRegime);
            });
        });

        // Encerrar: submete o form único da tabela com a competência da linha.
        document.querySelectorAll('[data-anexo-x-encerrar]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                var form = document.querySelector('[data-anexo-x-form-encerrar]');

                if (!form) {
                    return;
                }

                form.querySelector('input[name="competencia"]').value = botao.dataset.competencia;
                form.querySelector('input[name="regime"]').value = regimeAtual;
                form.submit();
            });
        });

        var modalReceitas = document.getElementById('modalReceitasDoMes');

        if (modalReceitas) {
            modalReceitas.addEventListener('show.bs.modal', function (evento) {
                var competencia = evento.relatedTarget.dataset.competencia;
                var mes = mesPorCompetencia(competencia);

                modalReceitas.dataset.competencia = competencia;

                var titulo = modalReceitas.querySelector('[data-receitas-periodo]');
                if (titulo && mes) {
                    titulo.textContent = 'Competência ' + mes.periodo_label;
                }

                modalReceitas.querySelectorAll('[data-receitas-regime]').forEach(function (botao) {
                    var ativo = botao.dataset.receitasRegime === regimeAtual;
                    botao.classList.toggle('is-active', ativo);
                    botao.setAttribute('aria-pressed', ativo ? 'true' : 'false');
                });

                renderReceitas(competencia, regimeAtual);
            });

            modalReceitas.querySelectorAll('[data-receitas-regime]').forEach(function (botao) {
                botao.addEventListener('click', function () {
                    modalReceitas.querySelectorAll('[data-receitas-regime]').forEach(function (outro) {
                        var ativo = outro === botao;
                        outro.classList.toggle('is-active', ativo);
                        outro.setAttribute('aria-pressed', ativo ? 'true' : 'false');
                    });

                    renderReceitas(modalReceitas.dataset.competencia, botao.dataset.receitasRegime);
                });
            });
        }

        var modalFormulario = document.getElementById('modalFormularioReceita');

        if (modalFormulario) {
            var iframe = modalFormulario.querySelector('[data-formulario-iframe]');
            var abrir = modalFormulario.querySelector('[data-formulario-abrir]');

            modalFormulario.addEventListener('show.bs.modal', function (evento) {
                var competencia = evento.relatedTarget.dataset.competencia;
                var mes = mesPorCompetencia(competencia);
                var url = new URL(estado.rotas.pdf, window.location.origin);

                url.searchParams.set('competencia', competencia);
                url.searchParams.set('regime', regimeAtual);

                modalFormulario.dataset.url = url.toString();

                if (abrir) {
                    abrir.href = url.toString();
                }

                var titulo = modalFormulario.querySelector('[data-formulario-periodo]');
                if (titulo && mes) {
                    titulo.textContent = 'Competência ' + mes.periodo_label;
                }
            });

            // O `src` só entra depois de aberto e sai ao fechar: sem isso a
            // página carregaria doze PDFs de uma vez.
            modalFormulario.addEventListener('shown.bs.modal', function () {
                if (iframe) {
                    iframe.src = modalFormulario.dataset.url || '';
                }
            });

            modalFormulario.addEventListener('hidden.bs.modal', function () {
                if (iframe) {
                    iframe.removeAttribute('src');
                }
            });
        }

        var modalOperacoes = document.getElementById('modalOperacoesDoMes');

        if (modalOperacoes) {
            modalOperacoes.addEventListener('show.bs.modal', function (evento) {
                var competencia = evento.relatedTarget.dataset.competencia;
                var mes = mesPorCompetencia(competencia);
                var tabela = modalOperacoes.querySelector('[data-operacoes-tabela]');

                var titulo = modalOperacoes.querySelector('[data-operacoes-periodo]');
                if (titulo && mes) {
                    titulo.textContent = 'Competência ' + mes.periodo_label;
                }

                if (tabela) {
                    tabela.innerHTML = '<tbody><tr><td class="text-center surface-subtitle py-4">Carregando…</td></tr></tbody>';
                }

                var url = new URL(estado.rotas.operacoes, window.location.origin);
                url.searchParams.set('competencia', competencia);
                url.searchParams.set('regime', regimeAtual);

                pedir(url.toString()).then(function (dados) {
                    modalOperacoes.__dados = dados;
                    renderOperacoes(dados, 'todas');
                }).catch(function (erro) {
                    if (tabela) {
                        tabela.innerHTML = '<tbody><tr><td class="text-danger py-4">' + escapar(erro.message) + '</td></tr></tbody>';
                    }
                });
            });

            modalOperacoes.querySelectorAll('[data-operacoes-filtro]').forEach(function (botao) {
                botao.addEventListener('click', function () {
                    modalOperacoes.querySelectorAll('[data-operacoes-filtro]').forEach(function (outro) {
                        outro.classList.toggle('btn-primary', outro === botao);
                        outro.classList.toggle('btn-outline-light', outro !== botao);
                    });

                    if (modalOperacoes.__dados) {
                        renderOperacoes(modalOperacoes.__dados, botao.dataset.operacoesFiltro);
                    }
                });
            });

            modalOperacoes.addEventListener('click', function (evento) {
                var botao = evento.target.closest('[data-operacao-detalhes-botao]');

                if (!botao) {
                    return;
                }

                var linha = modalOperacoes.querySelector(
                    '[data-operacao-detalhes-linha="' + botao.dataset.operacaoDetalhesBotao + '"]'
                );

                if (!linha) {
                    return;
                }

                var aberta = linha.classList.toggle('d-none') === false;
                botao.setAttribute('aria-expanded', aberta ? 'true' : 'false');

                var icone = botao.querySelector('i');
                if (icone) {
                    icone.classList.toggle('bi-chevron-down', !aberta);
                    icone.classList.toggle('bi-chevron-up', aberta);
                }
            });
        }

        var modalEditar = document.getElementById('modalEditarRelatorio');

        if (modalEditar) {
            modalEditar.addEventListener('show.bs.modal', function (evento) {
                var competencia = evento.relatedTarget.dataset.competencia;
                var mes = mesPorCompetencia(competencia);

                modalEditar.dataset.competencia = competencia;

                var titulo = modalEditar.querySelector('[data-editar-periodo]');
                if (titulo && mes) {
                    titulo.textContent = 'Competência ' + mes.periodo_label
                        + ' · regime de ' + (regimeAtual === 'caixa' ? 'caixa' : 'competência');
                }

                carregarAjustes(competencia).catch(function (erro) {
                    window.alert(erro.message);
                });
            });

            var form = modalEditar.querySelector('[data-editar-form]');

            if (form) {
                form.addEventListener('submit', function (evento) {
                    evento.preventDefault();

                    var dados = new FormData(form);

                    pedir(estado.rotas.ajustesStore, { method: 'POST', body: dados })
                        .then(function () {
                            form.querySelector('[name="valor"]').value = '';
                            form.querySelector('[name="motivo"]').value = '';

                            return carregarAjustes(modalEditar.dataset.competencia);
                        })
                        .then(function () {
                            window.location.reload();
                        })
                        .catch(function (erro) {
                            window.alert(erro.message);
                        });
                });
            }

            modalEditar.addEventListener('click', function (evento) {
                var botao = evento.target.closest('[data-cancelar-ajuste]');

                if (!botao) {
                    return;
                }

                var motivo = window.prompt('Por que este ajuste está sendo cancelado? (mínimo 10 caracteres)');

                if (!motivo || motivo.trim().length < 10) {
                    return;
                }

                var corpo = new FormData();
                corpo.append('motivo', motivo.trim());

                pedir(estado.rotas.ajustesCancelar.replace('__ID__', botao.dataset.cancelarAjuste), {
                    method: 'POST',
                    body: corpo
                })
                    .then(function () {
                        window.location.reload();
                    })
                    .catch(function (erro) {
                        window.alert(erro.message);
                    });
            });
        }

        var modalReconferir = document.getElementById('modalReconferir');

        if (modalReconferir) {
            modalReconferir.addEventListener('show.bs.modal', function (evento) {
                var competencia = evento.relatedTarget.dataset.competencia;
                var mes = mesPorCompetencia(competencia);
                var corpo = modalReconferir.querySelector('[data-reconferir-corpo]');

                var titulo = modalReconferir.querySelector('[data-reconferir-periodo]');
                if (titulo && mes) {
                    titulo.textContent = 'Competência ' + mes.periodo_label;
                }

                if (corpo) {
                    corpo.innerHTML = '<p class="surface-subtitle">Recalculando com os dados de hoje…</p>';
                }

                var url = new URL(estado.rotas.operacoes, window.location.origin);
                url.searchParams.set('competencia', competencia);
                url.searchParams.set('regime', regimeAtual);
                url.searchParams.set('reconferir', '1');

                pedir(url.toString()).then(function (dados) {
                    corpo.innerHTML = reconferenciaHtml(dados.fechamento);
                }).catch(function (erro) {
                    corpo.innerHTML = '<p class="text-danger">' + escapar(erro.message) + '</p>';
                });
            });
        }

        var modalReabrir = document.getElementById('modalReabrirAnexoX');

        if (modalReabrir) {
            modalReabrir.addEventListener('show.bs.modal', function (evento) {
                modalReabrir.querySelector('[data-reabrir-competencia]').value = evento.relatedTarget.dataset.competencia;
                modalReabrir.querySelector('input[name="regime"]').value = regimeAtual;

                var periodo = modalReabrir.querySelector('[data-reabrir-periodo]');
                if (periodo) {
                    periodo.textContent = evento.relatedTarget.dataset.periodo || '';
                }
            });
        }

        ligarDownload();
    });

    function reconferenciaHtml(fechamento) {
        if (!fechamento) {
            return '<p class="surface-subtitle">Esta competência não está encerrada.</p>';
        }

        if (!fechamento.divergencias || fechamento.divergencias.length === 0) {
            return '<div class="alert alert-success mb-0"><i class="bi bi-check2-circle me-1"></i>'
                + 'Os dados de hoje continuam produzindo exatamente os valores declarados.</div>';
        }

        var html = '<div class="alert alert-danger">Os dados de origem mudaram depois do encerramento. '
            + 'O relatório continua mostrando o que foi declarado.</div>'
            + '<table class="table align-middle mb-0"><thead><tr><th>Linha</th>'
            + '<th class="text-end">Declarado</th><th class="text-end">Hoje</th><th class="text-end">Diferença</th>'
            + '</tr></thead><tbody>';

        fechamento.divergencias.forEach(function (divergencia) {
            html += '<tr><td>' + escapar(divergencia.linha.toUpperCase()) + ' — ' + escapar(divergencia.rotulo) + '</td>'
                + '<td class="text-end">' + moeda(divergencia.congelado) + '</td>'
                + '<td class="text-end">' + moeda(divergencia.atual) + '</td>'
                + '<td class="text-end text-danger">' + moeda(divergencia.diferenca) + '</td></tr>';
        });

        return html + '</tbody></table>';
    }

    /**
     * Modal de download: só UM dos dois campos pode ser enviado. `disabled`
     * (e não `hidden`) porque campo desabilitado não é serializado pelo
     * navegador — é o que garante um período só no querystring.
     */
    function ligarDownload() {
        var formulario = document.querySelector('[data-anexo-x-download]');

        if (!formulario) {
            return;
        }

        var campos = {
            mes: formulario.querySelector('[data-anexo-x-campo="mes"]'),
            ano: formulario.querySelector('[data-anexo-x-campo="ano"]')
        };

        function aplicarPeriodo() {
            var escolhido = formulario.querySelector('[data-anexo-x-periodo]:checked');
            var periodo = escolhido ? escolhido.value : 'mes';

            Object.keys(campos).forEach(function (chave) {
                if (campos[chave]) {
                    campos[chave].disabled = chave !== periodo;
                }
            });
        }

        formulario.querySelectorAll('[data-anexo-x-periodo]').forEach(function (radio) {
            radio.addEventListener('change', aplicarPeriodo);
        });

        Object.keys(campos).forEach(function (chave) {
            if (!campos[chave]) {
                return;
            }

            campos[chave].addEventListener('focus', function () {
                var radio = formulario.querySelector('[data-anexo-x-periodo][value="' + chave + '"]');

                if (radio && !radio.checked) {
                    radio.checked = true;
                    aplicarPeriodo();
                }
            });
        });

        aplicarPeriodo();
    }
})();
