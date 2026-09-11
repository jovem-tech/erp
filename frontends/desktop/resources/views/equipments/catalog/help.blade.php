@extends('layouts.app')

@section('content')
    <section class="desktop-form-card">
        <div class="surface-card-header">
            <div>
                <h2 class="surface-title">Ajuda do catálogo de equipamentos</h2>
                <p class="surface-subtitle">
                    Lista consultiva de tipos, marcas e modelos usada para padronizar o cadastro de
                    equipamentos numa Ordem de Serviço ou num Orçamento. Não tem vínculo direto com
                    nenhuma OS ou orçamento — só alimenta os selects que essas telas já usavam.
                </p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="surface-card p-3 h-100">
                    <h3 class="h5 mb-2">O que você pode fazer</h3>
                    <ul class="mb-0 text-secondary">
                        <li>Consultar todos os modelos, filtrando por tipo, marca ou status.</li>
                        <li>Cadastrar e renomear tipos, marcas e modelos manualmente.</li>
                        <li>Ativar e desativar registros — "excluir" aqui é sempre desativar, nunca apaga de verdade.</li>
                        <li>Exportar o catálogo inteiro em CSV, corrigir em massa numa planilha e reimportar.</li>
                        <li>Mesclar modelos duplicados (ex.: "J7" e "SM-J730G" cadastrados separadamente) num registro só.</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="surface-card p-3 h-100">
                    <h3 class="h5 mb-2">Formato do CSV</h3>
                    <p class="text-secondary mb-2">
                        Colunas separadas por ponto e vírgula: <code>tipo;marca;modelo;ativo</code>.
                        A coluna <code>ativo</code> é opcional (padrão 1) e aceita 1/0, sim/não ou
                        ativo/inativo. Baixe o "Modelo CSV" em Mais ações para começar.
                    </p>
                    <ul class="mb-0 text-secondary">
                        <li>Uma linha por modelo. Para cadastrar só a marca (sem modelo ainda), deixe a coluna <code>modelo</code> vazia.</li>
                        <li>O <code>tipo</code> precisa já existir — linhas com um tipo desconhecido são rejeitadas e aparecem no relatório, o resto do arquivo continua sendo importado.</li>
                        <li>A importação é aditiva: cria o que falta, atualiza o nome (mantendo o casing do arquivo) e reativa o que estava inativo. Nunca desativa o que ficou fora do arquivo — só a coluna <code>ativo=0</code> explícita desativa.</li>
                        <li>Renomear um modelo/marca reflete em todos os aparelhos já cadastrados com aquele nome — útil para corrigir erros de digitação antigos.</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-12">
                <div class="surface-card p-3">
                    <h3 class="h5 mb-2">Edição em massa e mesclagem de duplicados</h3>
                    <p class="text-secondary mb-2">
                        O CSV exportado (Mais ações → Exportar CSV) traz duas colunas a mais:
                        <code>id</code> (o id de cada modelo) e <code>mesclar_com_id</code> (sempre vazia
                        na exportação). Use isso para corrigir em massa os modelos já cadastrados:
                    </p>
                    <ul class="mb-2 text-secondary">
                        <li>
                            <strong>Corrigir/padronizar nomes:</strong> exporte, edite a coluna
                            <code>modelo</code> das linhas que precisam de ajuste na planilha (pode usar
                            uma IA externa para revisar nomes incompletos ou ambíguos) e reimporte. Como a
                            linha já tem <code>id</code>, o sistema renomeia exatamente aquele registro —
                            não casa mais por nome, então o novo nome pode ser totalmente diferente do
                            antigo.
                        </li>
                        <li>
                            <strong>Mesclar duplicados:</strong> se dois (ou mais) modelos da mesma marca
                            representam o mesmo aparelho (ex.: <code>Samsung - J7</code>,
                            <code>Samsung - j 7</code> e <code>Samsung - SM-J730G</code>), escolha qual
                            fica (o "vencedor", precisa estar ativo) e preencha <code>mesclar_com_id</code>
                            nas linhas dos outros ("perdedores") com o id do vencedor. Ao reimportar, todo
                            aparelho já cadastrado com um modelo perdedor passa a apontar para o vencedor,
                            e o perdedor é desativado — nunca excluído. O restante da linha do perdedor
                            (nome, tipo, ativo) é ignorado nesse caso.
                        </li>
                    </ul>
                    <p class="text-secondary mb-0">
                        Só é possível mesclar modelos da <strong>mesma marca</strong>, e o sistema não
                        sugere duplicatas automaticamente — a identificação é manual, olhando a planilha
                        exportada. Uma mesclagem já atualiza sozinha a busca das OS afetadas; um lote
                        grande de renomeações (sem mesclagem) pode deixar a busca de OS temporariamente
                        desatualizada até alguém rodar <code>php artisan os:reindexar-busca</code>.
                    </p>
                </div>
            </div>

            <div class="col-lg-12">
                <div class="surface-card p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <h3 class="h5 mb-0">Prompt pronto para revisar o CSV com uma IA</h3>
                        <button type="button" class="btn btn-outline-light btn-sm" id="catalogAiPromptCopy">
                            <i class="bi bi-clipboard me-1"></i>Copiar prompt
                        </button>
                    </div>
                    <p class="text-secondary mb-2">
                        Exporte o CSV (Mais ações → Exportar CSV), anexe o arquivo numa conversa com uma
                        IA (ex.: Claude, ChatGPT) e cole o texto abaixo. Ele já explica as regras da coluna
                        <code>modelo</code> e da mesclagem de duplicados para o resultado voltar pronto para
                        reimportar.
                    </p>
                    <textarea id="catalogAiPromptText" class="form-control" rows="14" readonly style="font-size: 0.8rem;">{{ $aiPrompt }}</textarea>
                    <p class="text-secondary small mb-0 mt-2">
                        Para catálogos grandes, peça para a IA revisar por marca, em blocos — e confira o
                        resultado antes de reimportar, principalmente a coluna <code>mesclar_com_id</code>,
                        já que uma mesclagem errada move aparelhos de verdade de um modelo para outro.
                    </p>
                </div>
            </div>

            <div class="col-lg-12">
                <div class="surface-card p-3">
                    <h3 class="h5 mb-2">Onde isso aparece</h3>
                    <p class="text-secondary mb-0">
                        Os tipos, marcas e modelos cadastrados aqui aparecem nos seletores de equipamento
                        da Ordem de Serviço, do Orçamento e do aplicativo do técnico. A tela é puramente
                        consultiva: alterar um registro aqui não move nem apaga nenhuma OS ou orçamento
                        já existente.
                    </p>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        document.getElementById('catalogAiPromptCopy')?.addEventListener('click', function () {
            const campo = document.getElementById('catalogAiPromptText');
            if (!(campo instanceof HTMLTextAreaElement)) return;

            campo.select();
            try {
                navigator.clipboard.writeText(campo.value);
            } catch (erro) {
                // `navigator.clipboard` exige contexto seguro; a oficina acessa
                // por IP em rede local, onde ele nem sempre existe.
                document.execCommand('copy');
            }

            const original = this.innerHTML;
            this.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado';
            window.setTimeout(() => { this.innerHTML = original; }, 2000);
        });
    </script>
@endsection
