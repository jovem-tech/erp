# Seleção de equipamento do cliente na Nova OS mobile

## Contexto

- versão: `5.19.0.0`;
- data: `2026-07-26`;
- canal: PWA Next.js em `frontends/mobile`;
- fluxo: `/os/novo`, etapa `Equipamento`.

O campo de equipamento exigia que o operador digitasse antes de consultar a
API. Isso ocultava os equipamentos já vinculados ao cliente e não conduzia o
fluxo para um cadastro novo quando o cliente ainda não possuía equipamentos.

## Arquitetura e comportamento

O componente genérico `SearchSelect` ganhou carregamento opcional no foco,
conteúdo visual à esquerda e callback para o resultado da consulta inicial.
A etapa de equipamento usa essas extensões para:

1. consultar `GET /api/v1/equipments` com `client_id` obrigatório;
2. listar até 50 equipamentos recentes do cliente, mantendo busca remota por
   marca, modelo, série, IMEI, tipo e resumo técnico;
3. renderizar foto principal, identificação e série em cada opção;
4. permitir a seleção direta do equipamento;
5. trocar automaticamente para `Equipamento novo` quando a consulta inicial
   retornar uma lista vazia;
6. manter o novo equipamento como cadastro diferido, associado ao cliente
   dentro da mesma transação que cria a OS.

Quando o próprio cliente também é novo, a pesquisa de equipamentos existentes
fica desabilitada e o formulário de equipamento novo é aberto diretamente.

## Segurança e consistência

- o frontend nunca consulta equipamentos sem `client_id` nesse fluxo;
- fotos privadas são obtidas pelo cliente HTTP autenticado já existente, com
  Bearer token e validação de resposta;
- a rota da foto é derivada de IDs numéricos do contrato, em vez de reutilizar
  uma URL absoluta no header `Authorization`, evitando encaminhamento do token
  para uma origem inesperada;
- apenas respostas com MIME `image/*` viram URLs `blob:` exibidas pelo
  navegador;
- trocar o cliente remove equipamento selecionado, cadastro/fotos pendentes e
  checklist anterior;
- o estado local rejeita equipamento cujo `cliente_id` não corresponda ao
  cliente selecionado;
- o backend permanece autoritativo e já rejeita `equipment_client_mismatch`,
  protegendo contra manipulação do estado ou requisição manual.

Não foram adicionadas rotas, migrations ou mudanças no modelo de permissões.

## Performance e escalabilidade

- a consulta permanece paginada no backend e carrega no máximo 50 registros;
- catálogos maiores continuam acessíveis pela pesquisa remota, sem transferir
  a coleção inteira;
- o debounce de 250 ms é preservado durante a digitação;
- miniaturas usam `IntersectionObserver` com margem de pré-carregamento, de
  modo que fotos fora da área visível não geram requisições imediatas;
- URLs `blob:` são revogadas ao desmontar o item, evitando retenção de memória;
- respostas antigas de busca são invalidadas por identificador de requisição,
  prevenindo race condition visual quando o termo muda rapidamente.

Em uma futura paginação explícita da lista, o mesmo endpoint pode expor
`Carregar mais` sem alterar o contrato de seleção.

## Testes e validação

- carregamento da lista ao focar o campo;
- filtro por `client_id` e `per_page=50`;
- seleção de uma opção com miniatura à esquerda;
- carregamento autenticado da foto privada e liberação da URL `blob:`;
- fallback automático para equipamento novo em lista vazia;
- fluxo direto de equipamento novo quando o cliente ainda será cadastrado;
- limpeza do contexto ao trocar o cliente;
- rejeição local de equipamento pertencente a outro cliente;
- suíte Vitest com 86 testes passando;
- ESLint sem erros ou avisos;
- build de produção Next.js concluído.

## Trade-offs e melhorias futuras

A primeira abertura mostra até 50 equipamentos para manter latência e memória
previsíveis. Em clientes excepcionalmente grandes, o operador pode filtrar no
mesmo campo. Se esse volume se tornar frequente, a evolução recomendada é
adicionar paginação incremental no dropdown e cache privado de miniaturas por
ETag, preservando o controle de acesso atual.
