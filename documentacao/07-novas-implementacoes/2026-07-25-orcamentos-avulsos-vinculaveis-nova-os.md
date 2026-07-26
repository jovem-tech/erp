# Orçamentos avulsos vinculáveis na Nova OS

## Objetivo

Permitir que a abertura de uma OS localize e vincule orçamentos avulsos em
qualquer status operacional, incluindo os que ainda aguardam aprovação.

Continuam indisponíveis:

- orçamentos cancelados;
- orçamentos rejeitados;
- orçamentos já convertidos;
- orçamentos já vinculados a outra OS;
- orçamentos do tipo `assistencia`, pois não são avulsos.

## Arquitetura e regra de domínio

O modelo `Budget` expõe uma única lista de status vinculáveis, reutilizada pelo
catálogo de busca e pela validação transacional da criação da OS. Isso evita
divergência entre o que o desktop exibe e o que a API efetivamente aceita.

O vínculo é realizado dentro da mesma transação que cria a OS, com
`lockForUpdate()` no orçamento. A verificação autoritativa de tipo, status,
cliente, equipamento e ausência de vínculo é repetida depois do lock.

## Comportamento por aprovação

- `pendente_abertura_os` e `aprovado`: o orçamento é convertido na nova OS.
- Demais status vinculáveis: o orçamento recebe o `os_id`, preserva o status e
  continua editável/aprovável.
- Status de preparação (`rascunho`, `pendente_envio`, `pendente`,
  `reenviar_orcamento` e `vencido`): a OS nasce em `aguardando_orcamento`.
- Status que já representam proposta em circulação: a OS nasce em
  `aguardando_autorizacao`.

Quando o cliente aprovar posteriormente um orçamento já vinculado, o fluxo
existente reconhece o `os_id` e sincroniza a aprovação com a OS.

## Interface

O campo passou a se chamar **Vincular orçamento avulso (opcional)**. Cada
resultado mostra também o status atual, além de número, cliente, equipamento e
valor.

O seletor é excluído da inicialização Select2 genérica do desktop e inicializado
somente pelo adaptador remoto da Nova OS. Como proteção adicional, uma instância
preexistente é destruída antes de configurar o AJAX. Isso evita que o componente
se comporte como uma lista local vazia e deixe de consultar o catálogo.

## Segurança, desempenho e escalabilidade

- As permissões `os:criar` e `orcamentos:converter_os` continuam obrigatórias.
- Cancelados, rejeitados, convertidos e registros já consumidos são recusados
  também pelo servidor, independentemente do conteúdo enviado pelo navegador.
- O lock transacional impede que duas requisições vinculem o mesmo orçamento a
  OS diferentes.
- A busca permanece paginada, limitada a 30 itens e escapa curingas de `LIKE`.
- A filtragem usa colunas já presentes na consulta (`tipo_orcamento`, `status`
  e `os_id`), sem carga adicional de relacionamentos ou consultas N+1.

## Testes

Foram cobertos:

- catálogo contendo todos os status operacionais;
- exclusão de cancelado, rejeitado e convertido;
- vínculo de orçamento enviado ainda sem aprovação;
- manutenção do orçamento aberto após o vínculo;
- status inicial da OS aguardando autorização;
- rejeição transacional de cancelados e rejeitados;
- contrato Select2 do desktop com o status visível.
- isolamento do seletor remoto contra a inicialização Select2 global.

## Trade-off

`convertido` é bloqueado além dos dois status solicitados porque representa um
orçamento já consumido. Permiti-lo novamente causaria duplicidade financeira e
violaria a unicidade lógica do vínculo orçamento–OS.
