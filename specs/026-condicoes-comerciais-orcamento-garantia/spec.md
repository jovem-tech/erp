# Condições comerciais do orçamento e garantia da OS

## Problema

O orçamento tem um único campo livre — `orcamentos.condicoes` ("Condições
comerciais") — onde o operador precisa digitar, a cada orçamento:

- as formas de pagamento aceitas;
- a chave Pix da empresa;
- em quantas vezes o crédito é sem juros;
- o prazo de garantia.

Como o preenchimento é manual, longo e repetitivo, na prática **o campo fica em
branco na maioria dos orçamentos**. O resultado é falta de transparência com o
cliente exatamente nos dois pontos que mais geram dúvida e atrito depois da
venda: como pagar e por quanto tempo o serviço está garantido.

Todos esses dados já existem no sistema — o catálogo de formas de pagamento
está em Financeiro > Configurações — mas não são reaproveitados pelo orçamento.

Na OS o problema é simétrico: as colunas `os.garantia_dias` e
`os.garantia_validade` existem no banco desde o legado, mas nenhuma tela as
preenche. A garantia nunca é registrada e o cliente não recebe data de término.

## Objetivo

Transformar as condições comerciais em dado estruturado, reaproveitando o que o
sistema já sabe, e fazer a garantia acompanhar o ciclo orçamento → OS →
documento.

## Escopo

### 1. Chaves Pix no cadastro (Financeiro > Configurações > Formas de Pagamento)

- Nova tabela `financeiro_chaves_pix`: tipo (CPF/CNPJ/e-mail/telefone/aleatória),
  chave, titular, instituição, principal, ativo, ordem.
- Gerenciadas na linha da forma "Pix", pelo botão **Chaves** — é ali que o
  usuário espera encontrá-las.
- Só uma chave pode ser principal por vez.

### 2. Condições comerciais estruturadas no orçamento

- **Formas de pagamento**: checkboxes alimentados pelo catálogo ativo. A ordem
  exibida é a do catálogo, não a da digitação.
- **Parcelamento sem juros**: select de 2x a 24x, negociado por orçamento.
  Só aparece quando alguma forma de cartão parcelável está marcada; débito não
  parcela e fica fora.
- **Chave Pix**: ao marcar Pix, as chaves ativas são exibidas automaticamente —
  nada é digitado.
- **Garantia**: select fechado com 90 dias, 180 dias, 1 ano e 2 anos.
- **Campo livre**: preservado como "Observações complementares", para o que
  fugir do padrão. Nada do que já foi digitado em orçamentos antigos se perde.

As formas aceitas são gravadas em `orcamento_formas_pagamento` com código,
rótulo e tipo **congelados**: renomear ou excluir uma forma no catálogo não
reescreve o que já foi proposto ao cliente. As chaves Pix, ao contrário, são
resolvidas na leitura — se a empresa trocar de chave, a proposta ainda válida
passa a exibir a chave certa em vez de mandar o cliente pagar numa chave morta.

### 3. Garantia na OS

- O prazo prometido no orçamento acompanha a OS desde o vínculo
  (`OrderWorkflowService::linkBudgetToOrder`).
- A tela de baixa traz um select de garantia, já preenchido com o prazo do
  orçamento aprovado (ou o que a OS já tiver).
- Ao encerrar com equipamento reparado entregue (`entregue_reparado_pago`,
  `entregue_reparado_sem_custo`, `entregue_reparado_garantia`), a baixa grava
  `garantia_dias` e calcula `garantia_validade = data_entrega + dias`.
- Devolução sem reparo e descarte não concedem garantia: não houve serviço a
  garantir. O que já estava gravado não é apagado.
- A OS passa a exibir data de entrega, prazo de garantia e data de término.

### 4. Documentos PDF

Novas variáveis no tipo `os_orcamento`: `orcamento.formas_pagamento`,
`orcamento.chaves_pix`, `orcamento.parcelamento`, `orcamento.garantia_dias`,
`orcamento.garantia_prazo`, `orcamento.garantia_texto` e
`orcamento.condicoes_comerciais`; coleções `formas_pagamento` e `chaves_pix`.
Nos documentos de OS, `os.garantia_prazo` exibe o prazo por extenso ("1 ano" em
vez de "365").

O modelo padrão ganha as seções "Condições de pagamento" e "Garantia". Modelos
já publicados recebem os blocos por migration **aditiva**: nada é removido nem
reposicionado, e a versão anterior é arquivada em vez de sobrescrita, para não
destruir personalizações do usuário.

## Fora de escopo

- Cobrança automática por Pix (QR Code dinâmico, conciliação).
- Alteração do ENUM legado `financeiro.forma_pagamento`.
- Fluxo de acionamento de garantia (já existe: `verificacao_garantia`,
  `cumprimento_garantia`, `garantia_concluida`).

## Decisões

| Tema | Decisão | Por quê |
|---|---|---|
| Onde cadastrar chaves Pix | Na linha do Pix, em Formas de Pagamento | É onde o usuário procura; evita mais uma aba solta |
| Garantia em OS sem orçamento | Select na tela de baixa | Garante que toda OS entregue registre garantia, inclusive serviço rápido cobrado direto |
| Campo livre | Mantido como complemento | Preserva histórico e cobre o caso fora do padrão |
| Parcelas sem juros | Escolhidas por orçamento | Permite negociar caso a caso, sem travar num valor global |
| Prazos de garantia | Lista fechada (90/180/365/730) | O prazo vira compromisso no documento e é herdado pela OS: precisa ser comparável entre orçamentos |

## Critérios de aceite

1. Marcar formas de pagamento no orçamento grava e exibe o texto na tela, no
   link público e no PDF, com as três superfícies dizendo o mesmo.
2. Marcar Pix exibe as chaves ativas sem digitação.
3. Parcelamento sem cartão parcelável é descartado.
4. Payload parcial (ex.: só mudança de status) não apaga condições já acordadas.
5. Baixa como reparado entregue grava garantia com data de término correta.
6. Devolução sem reparo não concede garantia.
7. Prazo inválido é rejeitado com 422.
