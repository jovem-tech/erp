# Inventário de arquivos funcionais

## Escopo e método

O inventário cobre código do backend central, rotas, models, migrations, discos configurados e os dois bancos usados pelos fluxos de arquivo. O script reproduzível é `scripts/bash/inventory-file-usage.sh`; ele lê somente código e metadados, nunca conteúdo de arquivos funcionais.

O scanner operacional `file-manager:scan` trabalha somente com aliases de roots presentes em `config/file-manager.php`, não segue symlinks, recusa arquivos especiais e é dry-run. Seus findings podem registrar path restrito no banco técnico, mas logs comuns recebem somente hash e contadores.

## Matriz de fluxos

| Módulo | Entrada/geração | Dono de domínio | Persistência atual | Entrega | Autorização | Risco inicial |
|---|---|---|---|---|---|---|
| Branding | upload multipart | `CompanyProfileService` | configuração + disco `local` | logo pública/autenticada e fundo público | configuração via RBAC; leitura pública limitada | alto, reduzido no Release A |
| Fotos de equipamento/OS | upload multipart | `EquipmentWorkflowService` / `OrderWorkflowService` | tabelas legadas + `local`/`legacy_public` | rota autenticada | acesso ao equipamento/OS | alto |
| Documentos de OS | PDF gerado | `OrderDocumentCenterService` | `os_documentos`, `os_documento_arquivos` + `local` | download, ZIP, impressão, link público controlado | acesso à OS, escopo do link | crítico |
| Assinaturas | upload/geração | services de assinatura | tabelas de assinatura + `local` | imagem privada e preview | reautenticação, responsável e revisão | crítico |
| Chat outbound | upload multipart | `MessageAttachmentService` | banco `chat` + `local` | rota autenticada por conversa | RBAC + conta/conversa | alto, reduzido no Release A |
| WhatsApp inbound | base64/URL confiável | `IncomingMessageService` / `MessageAttachmentService` | banco `chat` + `local` | rota autenticada por conversa | token webhook + conta/conversa | crítico, reduzido no Release A |
| CSV de serviços/estoque | upload/stream | controllers de catálogo/estoque | processamento transitório | resposta streamed | RBAC do módulo | médio |
| Coletor | binário distribuível/snapshot | `EquipmentCollectorController` | asset/artefato de distribuição | download técnico | contrato do coletor | fora do gerenciador funcional |

## Trust zones

- `public`: branding explicitamente controlado e links documentais assinados/expiráveis;
- `authenticated`: fotos, PDFs, anexos e assinaturas entregues por rota com RBAC;
- `integration`: mídia inbound de origem allowlisted, ainda tratada como não confiável;
- `private storage`: blobs funcionais, sem exposição direta pelo servidor web;
- `legacy read-only`: uploads legados consumidos por resolver controlado;
- `excluded`: código-fonte, assets do frontend, executáveis do coletor, backups, caches e temporários.

## Roots autorizadas

| Alias | Disco | Namespace | Uso |
|---|---|---|---|
| `managed` | `local` | `managed-files` | blobs nativos do núcleo |
| `branding` | `local` | `private/empresa` | observação de logo/fundo |
| `chat` | `local` | `chat-media` | observação de anexos |
| `equipment_photos` | `local` | `private/equipamentos` | fotos atuais de equipamentos |
| `order_files` | `local` | `private/os_documentos` | documentos atuais de OS |
| `budget_documents` | `local` | `private/orcamentos` | PDFs atuais de orçamentos |
| `signatures` | `local` | `private/assinaturas` | assinaturas privadas |
| `legacy_equipment_*` | `legacy_public` | `uploads/equipamentos*` | fotos legadas de equipamentos |
| `legacy_order_*` | `legacy_public` | roots de OS, acessórios e checklists | fotos e documentos legados de OS |
| `legacy_budgets` | `legacy_public` | `uploads/orcamentos` | PDFs legados de orçamentos |
| `legacy_chat` / `legacy_whatsapp` | `legacy_public` | roots de mensagens | anexos legados de comunicação |
| `legacy_users` / `legacy_system` | `legacy_public` | roots de usuários e sistema | fotos de usuário e branding legado |

O root físico do disco nunca é recebido por parâmetro. O alias é resolvido no backend e o `realpath` final precisa permanecer dentro do root configurado do Flysystem.

## Banco principal

O catálogo novo usa:

- `managed_files` para blob imutável, hash, tamanho e estados independentes;
- `managed_file_links` para vínculos de domínio allowlisted;
- `managed_file_legacy_aliases` para compatibilidade e rollback;
- `managed_file_events` para trilha append-only;
- `file_scan_runs` e `file_scan_findings` para inventário e reconciliação.

Não há FK polimórfica arbitrária. `subject_type` usa aliases registrados. O banco `chat` permanece separado e não recebe FK cruzada.

## Permissões e operação

- PHP-FPM/worker precisa de leitura e escrita apenas nos namespaces privados necessários;
- scanner precisa somente de leitura nas roots observadas e escrita nas tabelas técnicas;
- operações destrutivas, trash físico, retenção e deduplicação permanecem desligadas;
- backup e restore devem tratar banco principal, banco `chat` e storage como um conjunto consistente;
- caminhos completos, nomes de clientes e bytes não devem aparecer em métricas ou logs comuns.

## Baseline de desenvolvimento em 2026-07-19

O dry-run nas roots autorizadas encontrou:

| Root | Arquivos legíveis | Bytes legíveis | Distribuição | Finding operacional |
|---|---:|---:|---|---|
| `branding` | 2 | 110.024 | 2 JPG | subdiretório de login sem leitura para o usuário CLI |
| `chat` | 1 | 880.220 | 1 PDF | nenhum erro de leitura |
| `managed` | 0 | 0 | vazio antes da ativação híbrida | root ainda não materializada |

O diretório de login está com modo `0700` e ownership do usuário do PHP-FPM. Isso protege o conteúdo do sistema, porém impede o comando executado pelo usuário operacional atual de inventariá-lo. A correção deve alinhar grupo/ACL do usuário de manutenção ao grupo do PHP-FPM sem abrir leitura global e sem usar `0777`. O scanner registrou `permission_denied` de severidade alta e continuou nas demais roots.

Nenhum arquivo foi movido, regravado ou removido pelo baseline. Os registros criados foram somente `file_scan_runs` e `file_scan_findings`.

## Auditoria ampliada em 2026-07-19

A comparação do filesystem real com as referências do banco confirmou que o primeiro ciclo não cobria todos os namespaces funcionais. O disco `legacy_public` continha aproximadamente 404 arquivos distribuídos entre fotos de equipamentos, fotos/anormalidades/acessórios/checklists de OS, documentos de OS, orçamentos e anexos de mensagens.

Depois da ampliação das roots, o dry-run avaliou 429 novos findings `orphan`: 422 passaram pelas policies de extensão, MIME real, tamanho e decoder; 7 foram recusados de forma segura. Os 422 válidos foram catalogados sem mover ou alterar os binários, elevando o catálogo para 425 registros.

Permanecem fora da leitura do usuário CLI seis namespaces locais com modo `0700`, incluindo assinaturas, fundo de login, documentos de OS e alguns diretórios recentes de equipamentos/orçamentos. O PHP-FPM continua acessando-os como owner. A conclusão do inventário exige ACL de leitura para a conta operacional ou execução controlada do scanner pelo usuário do serviço; não se deve usar `0777`.

## Reprodução

```bash
./scripts/bash/inventory-file-usage.sh
./scripts/bash/inventory-file-usage.sh --output=documentacao/03-arquitetura-tecnica/inventario-arquivos-gerado.md
php artisan file-manager:diagnose --json
FILE_MANAGER_ALLOW_SCANNER=true php artisan file-manager:scan --root=managed
```

O segundo comando gera um artefato detalhado com referências de código. O último apenas inventaria a root allowlisted; não move, reescreve ou remove arquivos.
