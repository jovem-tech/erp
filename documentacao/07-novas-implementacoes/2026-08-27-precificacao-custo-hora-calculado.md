# Custo-hora calculado e cadeia de custo do serviço (2026-08-27)

**Spec:** `specs/037-precificacao-integrada-ao-fluxo/spec.md` (Fase 3)
**Tipo:** integração do motor de precificação (MINOR)

## O que fecha aqui

Esta é a fase que fecha o ciclo: **custo fixo real do DRE → preço do serviço →
margem realizada → DRE**. Até agora o custo-hora era `R$ 40` — um default
digitado, sem lastro nenhum na operação.

```
custo_hora = custos fixos mensais ÷ (técnicos × horas produtivas/dia × dias úteis)
```

**A definição de "custo fixo" passou a ser única.** `Financeiro::scopeFixasDre()`
é compartilhado pelo DRE gerencial e pelo custo-hora. Duplicá-la significaria,
mais cedo ou mais tarde, um ponto de equilíbrio que não bate com o preço
cobrado — exatamente o descompasso que esta spec veio corrigir.

**A janela ficou de fora do scope, de propósito.** O DRE soma todo fixo com
vencimento até o fim do mês (heurística de "recorrente ainda vigente"); o
custo-hora precisa de **limite inferior**, ou somaria anos de aluguel num mês só.
E usa **meses fechados**: o mês corrente está sempre incompleto, e incluí-lo
faria o custo-hora despencar no dia 3 e dobrar no dia 28.

**Capacidade é global, não por serviço.** As 4 colunas de capacidade em
`precificacao_servico_overrides` nunca foram lidas — e esse era o sinal. A
oficina tem N técnicos independentemente do serviço cotado; por serviço
permitiria dois custos-hora contraditórios na mesma empresa, e o fixo rateado
deixaria de bater com o fixo do DRE.

## A escada de guardas

`resolver()` **nunca lança e nunca devolve zero**:

| Situação | Resultado | Motivo |
|---|---|---|
| Capacidade não configurada | cai no manual, `confiavel = false` | `CAPACIDADE_NAO_CONFIGURADA` |
| Sem custo fixo lançado na janela | cai no manual, `confiavel = false` | `SEM_CUSTO_FIXO_LANCADO` |
| Fora de 0,5×–5× do manual | devolve o **calculado**, `confiavel = false` | `FORA_DA_FAIXA_ESPERADA` |

**Zero é a saída mais perigosa que este serviço conseguiria produzir**: faria
todo serviço parecer infinitamente lucrativo e o semáforo pintaria tudo de
verde. Por isso o valor manual permanece como escape hatch.

No terceiro caso o calculado **é devolvido assim mesmo** — ele pode estar certo
e o manual desatualizado. A tela avisa e pede conferência. Substituir em
silêncio é o que faz o usuário deixar de confiar no recurso inteiro.

`precificacao_servico_aplicar_piso` continua sem leitor; será implementado na
Fase 6.

## Um bug encontrado ao ligar

`CustoHoraService` lia `configuracoes` **cru**, sem os defaults que
`loadSettings()` aplica. Numa instalação sem essas linhas — o caso de qualquer
ambiente novo — o custo-hora zerava. Corrigido com os mesmos padrões, espelhados
de `PrecificacaoService::DEFAULTS`. O teste `FinanceiroPrecificacaoTest` foi
quem pegou.

## Cadastro de serviço

O formulário tinha três inputs soltos e **nenhuma linha de JavaScript**.
`tempo_padrao_horas` era praticamente morto: existia e nenhum cálculo real o lia.

Agora mostra a cadeia — que é literalmente a saída que `buildServiceQuote()` já
produzia, nada novo calculado no cliente:

```
Mão de obra (1,50 h × R$ 22,73)   R$ 34,10
Materiais por execução            R$ 12,00
Risco 3%                          R$  1,38
Custo total                       R$ 47,48
Piso (÷ 1 − 25% − 3,5% − 0%)      R$ 66,41
```

Com a **procedência do custo-hora** no rodapé: "calculado dos seus custos fixos
lançados" ou o aviso específico de por que caiu no manual. Sem isso o operador
vê um número e não sabe se ele veio da realidade ou de um default esquecido.

### `custo_direto_padrao`: o rótulo era o bug

Nada dizia ao operador se mão de obra entrava ali, e metade dos cadastros a
inclui. Somar `tempo × custo_hora` por cima **dupla-contaria** essas linhas.

O campo virou **"Custo de materiais por execução"**, com "Não inclua mão de obra
— ela é calculada a partir do tempo padrão × custo-hora".

**Migração automática não é possível**: os dois casos são indistinguíveis pelos
dados. Por isso o rótulo vem **antes** de a fórmula mudar.

## Decisão de sequência

A mudança do custo de serviço usado por **PDV e orçamento**
(`custo_direto_padrao + tempo × custo_hora`) — a que move `SaleFlowTest` — foi
**deixada para depois** de propósito. Aplicá-la antes de os cadastros existentes
serem revisados dupla-contaria toda linha que já inclui mão de obra no custo
direto.

O rótulo novo é o que permite a revisão. A fórmula entra quando os cadastros
estiverem corrigidos.

## Testes

`PrecificacaoCustoHoraTest` (6): cálculo normal; ignora o mês corrente; sem
capacidade cai no manual; **sem custo fixo nunca devolve zero**; fora da faixa
devolve o calculado mas avisa; despesa variável não entra (já é descontada item
a item na margem — somar aqui contaria duas vezes).

`ServicoPrecoSugeridoTest` (desktop): cadeia, sugestão e o rótulo novo.

Backend: **879 passando, 0 falhas**. Desktop: **421 passando**, 5
pré-existentes. `SaleFlowTest` intocado — a mudança que o moveria foi adiada.
