# Checklist de Qualidade - Governança do BFF sistema-hml

**Objetivo**: validar completude e consistência antes de qualquer implementação funcional.
**Criado em**: 24/06/2026.
**Feature**: [spec.md](../spec.md).

## Qualidade do conteúdo

- [x] O escopo está limitado a documentação, PRD e contrato.
- [x] O documento declara valor de negócio e risco arquitetural.
- [x] O papel do `frontend/sistema-hml` como BFF está explícito.
- [x] O documento evita autorizar implementação funcional nova nesta etapa.

## Completude de requisitos

- [x] Não há marcadores `[NEEDS CLARIFICATION]`.
- [x] Os requisitos são testáveis por revisão documental e consistência de rotas.
- [x] Os critérios de aceite citam os documentos e contratos esperados.
- [x] O OpenAPI é definido como contrato técnico oficial.
- [x] O guia humano da API é definido como leitura obrigatória.

## Governança BFF

- [x] Proíbe acesso direto ao banco em módulos migrados.
- [x] Proíbe duplicação de regra de negócio no BFF.
- [x] Proíbe armazenamento de anexos operacionais em pasta pública do BFF.
- [x] Exige token Bearer somente em sessão server-side.
- [x] Exige cliente HTTP único para comunicação com o backend.

## Prontidão para próxima fase

- [x] Próxima etapa recomendada está delimitada como autenticação e `auth/me`.
- [x] A etapa atual não cria rotas novas nem muda runtime.
- [x] O contrato pode ser validado contra `backend/routes/api.php`.
- [x] Os riscos de latência, segurança e estabilidade estão documentados.

## Observações

Checklist aprovado para avançar para planejamento/implementação futura da primeira migração funcional.
