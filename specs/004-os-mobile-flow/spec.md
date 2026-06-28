# Spec: Fluxo de OS Mobile

**Feature Branch**: `004-os-mobile-flow`  
**Status**: Ready for implementation

## Resumo

O PWA mobile passa a operar o primeiro fluxo real de campo: o técnico visualiza apenas as Ordens de Serviço atribuídas a ele e consegue alterar o status da OS com segurança, mantendo `status` e `estado_fluxo` sincronizados com o catálogo ativo do banco.

## Objetivos

- Permitir que o técnico veja apenas as OS sob sua responsabilidade.
- Permitir a atualização de status da OS com validação em tempo de execução.
- Manter `status` e `estado_fluxo` coerentes durante a alteração.
- Registrar histórico da alteração para auditoria e rastreabilidade.
- Permitir que o técnico consulte o detalhe da OS com cliente, equipamento, histórico recente e anexos controlados.

## Histórias de Usuário

### US1 - Ver minhas OS

Como técnico autenticado, quero ver apenas as OS atribuídas a mim, para focar nas demandas do meu atendimento.

### US2 - Alterar status da OS

Como técnico autenticado, quero atualizar o status de uma OS atribuída a mim, para registrar a evolução do atendimento em campo.

### US3 - Bloqueio de acesso

Como técnico autenticado, quero que o sistema negue a alteração de OS que não me pertence, para evitar alterações indevidas.

### US4 - Ver detalhe da OS

Como técnico autenticado, quero ver o detalhe da OS atribuída a mim com cliente, equipamento, histórico recente e anexos, para executar o atendimento com contexto completo.

## Requisitos Funcionais

- **FR-001** - O sistema deve listar apenas OS cujo responsável seja o usuário autenticado.
- **FR-002** - O sistema deve permitir filtrar a listagem por termo de busca e status.
- **FR-003** - O sistema deve aceitar atualização de status somente com códigos presentes no catálogo ativo de status.
- **FR-004** - O sistema deve atualizar `status` e `estado_fluxo` ao mesmo tempo.
- **FR-005** - O sistema deve registrar o histórico da alteração de status.
- **FR-006** - Se a OS existir, mas não estiver atribuída ao técnico autenticado, o sistema deve responder com acesso negado.
- **FR-007** - O frontend deve receber respostas no envelope padrão da API.
- **FR-008** - O sistema deve expor o detalhe da OS com cliente, equipamento, histórico recente e anexos controlados por endpoint.
- **FR-009** - O sistema não deve expor bytes de arquivos na resposta JSON; apenas URLs ou endpoints de acesso.

## Requisitos Não Funcionais

- **NFR-001** - Não deve existir lista hardcoded de status no backend.
- **NFR-002** - A validação de status deve usar o catálogo do banco no momento da requisição.
- **NFR-003** - A fase não deve implementar validação de transições entre status.
- **NFR-004** - A resposta de acesso negado deve ser explícita e previsível.
- **NFR-005** - O histórico retornado no detalhe da OS deve ser limitado aos últimos 5 registros.

## Critérios de Aceite

- O técnico visualiza somente suas próprias OS.
- O técnico visualiza o detalhe da OS atribuída com cliente, equipamento, histórico recente e anexos.
- Uma OS não atribuída retorna `403`.
- Um status fora do catálogo é rejeitado.
- Ao alterar o status com sucesso, `status` e `estado_fluxo` mudam juntos.
- O histórico da alteração fica disponível para auditoria.
- Os anexos retornam apenas URLs ou endpoints de acesso controlado.

## Premissas

- A fase usa o mesmo banco compartilhado do legado.
- O catálogo `os_status` é a fonte de verdade para os códigos válidos.
- A validação de transição entre status ficará para uma etapa posterior.
