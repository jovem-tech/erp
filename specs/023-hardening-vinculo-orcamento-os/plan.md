# Plano de implementação

## Arquitetura

1. Introduzir a ação RBAC `converter_os`.
2. Expor endpoints dedicados e mínimos para pesquisar e carregar orçamentos
   vinculáveis.
3. Aplicar autorização explícita também no `POST /orders`.
4. Mover leitura, validação e conversão do orçamento para a transação de criação
   da OS, usando bloqueio pessimista.
5. Tornar orçamento convertido imutável nas operações genéricas.
6. Trocar o carregamento antecipado do desktop por Select2 AJAX paginado.

## Persistência

- Nova permissão no catálogo RBAC.
- Concessão conservadora somente a grupos que já possuíam, simultaneamente,
  `os:criar` e `orcamentos:visualizar`.
- Índice composto para o predicado canônico dos candidatos:
  `(status, tipo_orcamento, os_id, aprovado_em, id)`.

## Compatibilidade

- Abertura comum de OS não muda.
- Grupos que legitimamente podiam usar o recurso mantêm acesso após a migração.
- Links existentes “Gerar OS” continuam válidos, agora protegidos pela nova
  permissão.
- Nenhuma alteração direta será aplicada na VPS; o fluxo de promoção permanece
  `192.168.1.100 → Git → VPS`.

## Rollback

- A migration remove apenas os vínculos da nova permissão, a própria permissão
  e o índice criado.
- O código anterior pode ser restaurado por rollback de Git, sem migração
  destrutiva de dados operacionais.
