# Constituição do Sistema ERP

## Princípios centrais

### 1. Documentação sincronizada e auditável
Toda alteração de código, fluxo, API, banco, deploy, layout ou operação deve atualizar a documentação correspondente em `documentacao/`. Nenhuma entrega é concluída sem rastreabilidade explícita dos impactos técnicos e operacionais.

### 2. pt-BR e UTF-8 obrigatórios
Todo texto visível ao usuário deve permanecer em `pt-BR`, e todo arquivo textual novo ou alterado deve ser salvo em `UTF-8`. Não são aceitos textos ambíguos, mistura desnecessária de idiomas ou regressão de acentuação.

### 3. Backend central como fonte única de verdade
Regra de negócio, autenticação, autorização, arquivos privados e integrações externas pertencem ao `backend/`. Nenhum frontend acessa o banco diretamente nem replica decisão final de segurança fora da API central.

### 4. Frontends por canal com sessão e responsabilidades claras
Cada canal deve respeitar sua responsabilidade:
- `frontends/desktop` usa Laravel/Blade com sessão server-side e camada de services;
- `frontend/` ou `frontends/mobile` podem usar outro stack, mas sempre via API;
- o navegador nunca recebe acesso direto ao banco nem caminhos físicos de storage privado.

### 5. UX operacional, responsividade real e falha segura
Toda interface deve preservar fluxo operacional, usar `Swal.fire` para mensagens críticas e funcionar sem quebra em `1280px`, `992px`, `768px`, `430px`, `390px`, `360px` e `320px`. Quando uma dependência externa falhar, a UI deve continuar utilizável com fallback manual sempre que possível.

No `frontends/desktop`, todo `select` visível e interativo deve usar `Select2` com tema `Bootstrap 5`, mensagens em `pt-BR` e helper compartilhado. Exceções só são permitidas para controles técnicos, ocultos ou marcados explicitamente para uso nativo.

### 6. Segurança e compatibilidade de ambiente
Cada fase deve considerar permissões, limites de upload, storage privado, auditoria mínima, compatibilidade entre `Windows/XAMPP` e `Linux/VPS`, e ausência de path hardcoded dependente do Windows.

## Restrições obrigatórias do projeto

- Stack atual do backend central: `PHP 8+`, `Laravel`, `MySQL/MariaDB`.
- Stack atual do desktop: `Laravel/Blade`, sessão server-side, sem Models de negócio.
- No desktop, dropdowns visíveis seguem o padrão `Select2-first` com helper compartilhado e `dropdownParent` adequado em modais/offcanvas.
- Tokens não podem ir para `localStorage` no desktop.
- Arquivos operacionais ficam em storage privado e só saem por endpoint autenticado.
- Toda mudança com novas rotas ou payloads deve atualizar a documentação de contrato da API.
- Toda feature com impacto visual deve validar responsividade real e manter consistência com o shell visual do ERP.

## Fluxo oficial de desenvolvimento

Para features novas ou mudanças estruturais, o fluxo padrão é:

`spec -> plan -> tasks -> implementação -> análise de consistência e segurança -> documentação`

Quando a infraestrutura Spec Kit estiver disponível no projeto, os artefatos devem ser mantidos em `specs/` e sincronizados com `.specify/`.

## Governança

Esta constituição prevalece sobre decisões ad hoc que tentem pular documentação, segurança, responsividade, separação arquitetural ou compatibilidade de ambiente.

**Versão**: 2.1.0 | **Ratificada**: 2026-06-24 | **Última revisão**: 2026-06-24
