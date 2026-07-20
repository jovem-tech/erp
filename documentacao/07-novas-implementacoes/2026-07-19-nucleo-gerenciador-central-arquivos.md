# Núcleo do Gerenciador Central de Arquivos

**Status:** implementado e validado no ambiente de desenvolvimento Linux; feature flags permanecem em `off`; ainda não promovido para produção.

## Entrega

Foi criado um núcleo central incremental para catalogar arquivos sem romper paths, endpoints ou tabelas atuais. A entrega inclui:

- configuração fail-safe com modos `off`, `observe`, `shadow` e `hybrid`;
- catálogo idempotente, vínculos de domínio, aliases legados e auditoria append-only;
- storage nativo com staging, chave imutável, promoção sem overwrite e SHA-256 por stream;
- policies por categoria com validação de MIME real, extensão, tamanho e decoder;
- máquina de estados separando ciclo de vida, integridade, segurança e migração;
- scanner dry-run por roots allowlisted, sem seguir symlink;
- comandos de diagnóstico, integridade e reconciliação protegida por kill switch;
- instrumentação fail-open do branding e chat nos modos de observação;
- piloto de fundo de login e logo usando o mesmo path compreendido pelo leitor legado;
- compensação conservadora: erro de banco ambíguo preserva o blob para reconciliação.

## Banco de dados

A migration aditiva cria `managed_files`, `managed_file_links`, `managed_file_legacy_aliases`, `managed_file_events`, `file_scan_runs` e `file_scan_findings`. Ela foi aplicada somente no servidor de desenvolvimento e os índices críticos foram verificados no MySQL 8.4.

Não há foreign key cruzada com o banco `chat`, remoção de coluna/tabela existente nem mudança obrigatória nos consumidores legados.

## Segurança

- escrita, scanner e reconciliação mutável estão desligados por padrão;
- ativação híbrida exige allowlist de categoria e switch explícito;
- paths físicos não vêm da API e são validados contra disco/root permitidos;
- nomes e MIME do cliente não são confiáveis;
- SVG e conteúdo ativo não são servidos inline no branding/chat;
- logs do fluxo central usam hashes em vez de caminhos e nomes sensíveis;
- nenhuma permissão foi ampliada para `0777`.

## Performance e escala

Hash e cópia são feitos por stream com memória O(1). Queries operacionais possuem índices para operation key, storage key, categoria/data, estado, links e eventos. Scanner tem limite, profundidade, timeout, checkpoint e heartbeat. O contrato de storage permite trocar o provider futuramente, sem antecipar S3 ou deduplicação.

## Validação

- testes do núcleo cobrem idempotência, substituição sem overwrite, MIME spoofing, falha confirmada/ambígua de banco, estados, scanner, symlink, reconciliação e filesystem Linux real;
- testes do piloto comprovam vínculo/alias, retenção da versão anterior, restauração em falha e leitura pelo contrato legado depois de voltar para `off`;
- o scanner real de desenvolvimento leu duas imagens de branding e um PDF de chat sem alterar os arquivos;
- o diagnóstico confirmou migrations e plano de índice;
- regressões de segurança de branding/chat permanecem cobertas.

## Limitações deliberadas

O código não ativa produção, não elimina fallback, não apaga versões anteriores, não executa deduplicação, não oferece antivírus real e não realiza retenção destrutiva. Rollout operacional, restore conjunto e alertas externos permanecem gates obrigatórios.

Arquitetura: `documentacao/03-arquitetura-tecnica/gerenciador-central-arquivos.md`.

Operação: `documentacao/10-deploy/operacao-gerenciador-central-arquivos.md`.
