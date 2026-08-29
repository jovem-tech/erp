# Precificação integrada ao fluxo

## Problema

O dono olhou as telas e disse: *"todo o processo financeiro parece destoado do
estoque, dos serviços e do orçamento da OS. Tudo deve ser sinérgico e
integrado."*

Ele está certo. `PrecificacaoService` tem **exatamente um chamador** — a própria
tela de configuração e o simulador manual. **Zero** chamadas de qualquer fluxo
real. A matemática existe e está correta (`buildPieceQuote()` faz
`custo + encargos + margem`; `buildServiceQuote()` faz
`custo / (1 − margem − taxa − imposto)`) e nunca é invocada por nada que o
operador use para decidir um preço.

Sintomas verificados:

- Cadastro de peça: `preco_venda` digitado à mão, sem sugestão. O campo
  **código** tem sugestão automática; o preço, que dá o lucro, não.
- Cadastro de serviço: `valor`, `tempo_padrao_horas` e `custo_direto_padrao`
  são três inputs soltos que nunca conversam.
- Orçamento: **8 das 9 colunas de precificação gravadas com `0` literal**;
  `modo_precificacao` era `'manual'` hard-coded em cinco lugares.
- `orcamentos/show.blade.php:289` promete "com custo, margem e observações por
  linha" — **a tabela não tem essas colunas. A legenda mente.**
- PDV: o backend calcula o custo e **envia ao navegador**; o JS descarta. O
  operador dá desconto às cegas.
- `os_itens`: **2.306 linhas, zero com `preco_custo_referencia > 0`** — e
  `buildCostSummary()` soma essa coluna, então a tela de encerramento mostra
  **"Custo estimado: R$ 0,00" em toda OS**.

O padrão é único: **o sistema mede depois e não guia na hora de decidir.**

## Objetivo

Fazer a precificação sair da tela de configuração e entrar no fluxo — cadastro,
orçamento, PDV e encerramento de OS — de modo que o custo fixo real alimente o
preço, o preço alimente a margem e a margem volte ao DRE. O ciclo fecha.

## Decisões

- **Visibilidade por permissão.** `financeiro:visualizar` **ou**
  `precificacao:visualizar` vê custo e margem em reais; os demais veem
  semáforo e aviso de piso. O técnico para de vender no prejuízo sem que a
  tabela de custo fique exposta para quem atende o balcão.
- **A redação mora no DTO, não na view.** `@if` em Blade esconde o pixel e
  deixa o número no devtools. `PrecoQuote::toArray()` apaga a chave.
- **Preço sugerido pré-preenche**, com override livre — e nunca sobrescreve
  digitação (regra do "sujo").
- **Custo-hora calculado** dos custos fixos reais do DRE, com capacidade
  **global**: a oficina tem N técnicos independentemente do serviço cotado.
  Capacidade por serviço produziria dois custos-hora contraditórios na mesma
  empresa, e o fixo rateado deixaria de bater com o fixo do DRE.
- **Semáforo nunca premia cadastro incompleto.** Custo desconhecido é
  `indefinido` (cinza), nunca verde — senão quem esquecesse o custo veria a
  tela toda verde.
- **Modo de precificação é resolvido no servidor**, por comparação. Vindo do
  cliente, a coluna registraria intenção declarada, não fato.
- **Nunca bloquear a venda** por margem. PDV que se recusa a vender é PDV
  contornado por recibo manual. O bloqueio fica disponível via
  `precificacao_servico_aplicar_piso`, hoje um botão morto.

## Escopo

Fase 0 fundação · Fase 1 PDV · Fase 2 preço sugerido na peça · Fase 3
custo-hora + serviço · Fase 4 orçamento com custo e margem · Fase 5 fechar o
ciclo com a OS · Fase 6 limpeza e telas de parâmetro.

## Fora de escopo

Emissão fiscal. Conversão entre unidades de medida. Multi-depósito.

## Riscos

- **`loadSettings()` era N+1** (16 queries) e é chamado de novo dentro de cada
  `buildQuote`. Sem o contexto compartilhado, um orçamento de 20 linhas custaria
  ~300 queries. Corrigido na Fase 0.
- **`syncItems()` apaga e reinsere tudo a cada save** — editar a observação de
  um orçamento aprovado o reprecificaria pelas configurações de hoje.
- **`custo_direto_padrao` é semanticamente ambíguo**: nada nunca disse ao
  operador se mão de obra entra ali. Somar `tempo × custo_hora` por cima
  dupla-conta as linhas que já a incluem. Os dois casos são indistinguíveis
  pelos dados — não tentar migração automática.
- **`buildServiceQuote()` clampa o divisor em 0.01**: margem+taxa+imposto ≥ 95%
  faz o piso explodir para 100× o custo, em silêncio.
- **`RegimeTributario::PADRAO = 'mei'` ⇒ imposto 0 no divisor.** Oficina no
  Simples que nunca abriu a tela precifica com imposto zero.
- **RBAC cacheia permissão por 5 minutos** — promover alguém não revela custo
  na hora.
