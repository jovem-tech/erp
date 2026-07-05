# ⚠️ Esta pasta não é mais usada para desenvolvimento

**Desde 2026-07-05, o Windows/XAMPP está definitivamente descontinuado como ambiente
de desenvolvimento do Sistema ERP.**

Nada foi apagado desta pasta — ela continua aqui como registro histórico de como o
projeto começou. Mas **nenhum trabalho novo deve acontecer aqui**.

## Onde trabalhar agora

- **Repositório oficial:** `https://github.com/jovem-tech/erp` (branches `develop` e `main`)
- **Ambiente de desenvolvimento:** `192.168.1.100` (via SSH, branch `develop`)
- **Produção:** VPS Contabo `161.97.93.120` (via SSH, branch `main`)

## Por que a mudança

Desenvolver em Windows/XAMPP e implantar em Linux causava uma classe inteira de bugs
que só apareciam no deploy (diferenças de case-sensitivity, MariaDB x MySQL 8,
comportamento de middleware que só roda em produção). Desenvolver no mesmo SO/stack da
produção elimina essa categoria de problema — e permite que qualquer ferramenta de IA
(Claude, Codex, Copilot, Antigravity) trabalhe direto no ambiente real, seguindo a
documentação em `documentacao/` como fonte única da verdade.

Ver `AGENTS.md` (seção "LEIA ISTO PRIMEIRO") e
`documentacao/10-deploy/workflow-git-multiambiente.md` para o fluxo completo.
