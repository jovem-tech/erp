# Coletor de Bancada Jovem Tech — build Linux

Script `bash` unico (`jovemtech-bench-collector.sh`), sem dependencias fora do
que a maioria das distros ja traz de fabrica (`bash`, `awk`, `sed`, `lsblk`,
`lscpu`, `curl`). Nao precisa de instalacao nem de `root`/`sudo`.

## Objetivo

Roda diretamente **na maquina do cliente** (o computador sendo diagnosticado),
nao no servidor do ERP. Le o inventario tecnico da propria maquina e:

1. grava um snapshot local em JSON, do lado do cliente (auditoria/debug);
2. se `--pairing-code` + `--erp-base-url` + `--collector-token` forem
   informados, envia esse snapshot pela rede para o ERP
   (`POST /api/v1/collector/snapshots`), pareado ao codigo gerado na tela de
   cadastro do equipamento.

## Uso tipico

O tecnico gera o codigo de pareamento na tela **Novo equipamento** do ERP
(secao "Pareamento remoto"), copia o comando pronto exibido na tela — ja com
`--erp-base-url` e `--collector-token` preenchidos — e roda no computador do
cliente, na mesma rede local:

```bash
./jovemtech-bench-collector.sh \
    --pairing-code=ABCD1234 \
    --erp-base-url=https://192.168.1.100:8443 \
    --collector-token=<token>
```

Ao concluir com sucesso, o snapshot aparece na tela do ERP (status muda para
"pronto para importar") e o tecnico clica em "Importar snapshot" para
preencher os campos tecnicos do formulario.

## Teste local, sem enviar nada ao ERP

```bash
./jovemtech-bench-collector.sh --dry-run
```

Grava so o `last-snapshot.json` ao lado do script, para conferencia manual.

## Campos coletados

`motherboard`, `chipset` (via ponte ISA/LPC do `lspci` — nao e um campo
dedicado, entao em placas mais novas ou incomuns pode nao vir preenchido),
`cpu`, `gpu`, `storageSummary`, `memorySummary`/`ramGb`, `serialNumber` (com
`serialSource` indicando a origem), `manufacturer`, `model`, `deviceType`
(`desktop`/`notebook`), `chassisType`, `biosVendor`, `biosVersion` — mesmo
contrato de campos usado pelo build Windows (`JovemTechBenchCollector.exe`).

## Observacoes

- todas as leituras usam `/sys/class/dmi/id/*`, `/proc/cpuinfo`,
  `/proc/meminfo`, `lscpu`, `lsblk` e `lspci` — nenhuma exige privilegios
  elevados;
- a serie prioriza a BIOS/placa-mae (`product_serial`/`board_serial`/
  `chassis_serial`); a maioria das distros restringe esses arquivos a
  `root` (permissao `0400`), entao sem `sudo` o script cai para o endereco
  MAC da primeira interface de rede fisica (`serialSource: "mac_address"`)
  — mesma regra ja documentada no coletor Windows;
- `--no-prompt`/`--no-save-config` existem so por compatibilidade com a
  mesma linha de comando do `.exe` do Windows — este script nunca pede
  confirmacao interativa;
- em `--dry-run`, roda sem `--pairing-code`/`--erp-base-url`, servindo como
  leitura local pura;
- precisa de `curl` instalado para o envio pela rede (a leitura local
  funciona sem ele);
- o envio usa `curl -k` (aceita certificado autoassinado), pois o ERP na
  rede local normalmente nao tem certificado publico valido;
- o token do coletor (`--collector-token`) e de uso unico por pareamento —
  gerado junto com o codigo, some quando o pareamento expira ou e consumido.
  Nao e mais um segredo global fixo; cada codigo tem o seu proprio.

## Diferenca em relacao ao build Windows

O build Windows equivalente e o `jovemtech-bench-collector.ps1`
(`public/assets/agents/bench-collector/win-x64/`) — mesmo protocolo de
pareamento (`--pairing-code`), mesmo contrato de campos do snapshot. O
`JovemTechBenchCollector.exe` antigo (binario compilado, sem fonte neste
repositorio) usa um fluxo mais antigo, por numero de OS e e-mail do
tecnico (`--warranty-os-number`, `--erp-login-email`), incompativel com o
endpoint de pareamento — a tela do ERP nao oferece mais o `.exe` na secao
de pareamento remoto, so os dois scripts (`.sh` e `.ps1`).
