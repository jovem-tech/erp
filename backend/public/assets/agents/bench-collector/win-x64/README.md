# Coletor de Bancada Jovem Tech

Projeto fonte do coletor portatil para `Desktop` e `Notebook`.

## jovemtech-bench-collector.ps1 (recomendado — fluxo de pareamento)

Script PowerShell novo, sem compilacao (funciona no PowerShell 5.1 que ja
vem com o Windows, ou no PowerShell 7+). E o equivalente Windows do build
Linux (`jovemtech-bench-collector.sh`) — fala o mesmo protocolo de
pareamento por codigo (`--pairing-code`) que o `.exe` abaixo **nao**
suporta, e por isso e o que a tela de pareamento remoto do ERP oferece
pra download.

Uso tipico (o tecnico gera o codigo na tela do ERP e roda isto na maquina
do cliente, na mesma rede local):

```powershell
powershell -ExecutionPolicy Bypass -File .\jovemtech-bench-collector.ps1 `
    --pairing-code=ABCD1234 `
    --erp-base-url=https://192.168.1.100:8443 `
    --collector-token=<token>
```

Teste local, sem enviar nada ao ERP:

```powershell
powershell -ExecutionPolicy Bypass -File .\jovemtech-bench-collector.ps1 --dry-run
```

Detecta hardware via WMI/CIM (`Get-CimInstance`), sem precisar rodar como
administrador: placa-mae, BIOS, processador, RAM, discos, GPU e tipo de
gabinete (mesmos codigos SMBIOS usados pelo build Linux). Serial prioriza
BIOS/placa-mae/chassi; sem nenhum disponivel, cai pro MAC da primeira
placa de rede fisica — mesma regra do build Linux e do `.exe` legado
abaixo. Aceita certificado autoassinado do ERP (`ServerCertificateValidationCallback`
no PowerShell 5.1, `-SkipCertificateCheck` nativo no 7+).

**Nao testado em uma maquina Windows real nesta sessao** (o ambiente onde
isto foi escrito e Linux, sem PowerShell disponivel para validar) — o
fluxo de pareamento em si (endpoint, payload, token) e o mesmo ja validado
ponta-a-ponta com o script Linux; o que fica sem cobertura de teste real e
especificamente a leitura via WMI/CIM e o envio via `Invoke-RestMethod`
neste script. Vale um teste manual numa maquina Windows antes de confiar
cegamente em producao.

## .exe legado (JovemTechBenchCollector.exe)

Binario compilado, sem fonte neste repositorio — so o `.exe` publicado e
este README existem. Usa um fluxo mais antigo, por numero de OS e e-mail
do tecnico (nao por codigo de pareamento) — o backend atual **nao**
implementa mais o endpoint que esse fluxo espera, entao o `.exe` sozinho
nao consegue mais fazer check-in automatico pela rede com este ERP. Segue
documentado abaixo por referencia historica.

## Objetivo

- rodar sem instalacao no computador do cliente;
- coletar inventario tecnico visivel quando a maquina ligar;
- provisionar o agente pela `OS`;
- enviar o `check-in` para o ERP;
- complementar automaticamente `placa-mae`, `chipset`, `processador`, `memoria`, `GPU`, `armazenamento` e dados do Windows.

## Publicacao

Use o script:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\agents\publish-bench-collector.ps1
```

Saidas geradas:

- `public/assets/agents/bench-collector/win-x64/JovemTechBenchCollector.exe`
- `public/assets/agents/JovemTechBenchCollector-win-x64.zip`

## Uso rapido do executavel

Modo interativo:

```powershell
.\JovemTechBenchCollector.exe
```

Modo direto:

```powershell
.\JovemTechBenchCollector.exe --erp-base-url "https://erp.exemplo.com.br" --warranty-os-number "OS12345" --erp-login-email "tecnico@empresa.com"
```

Teste sem enviar ao ERP:

```powershell
.\JovemTechBenchCollector.exe --dry-run
```

## Observacoes

- o coletor foi pensado para `Windows`;
- por padrao ele executa uma coleta unica, ideal para bancada;
- use `--continuous` apenas quando quiser repetir `check-ins`;
- em `--dry-run`, ele pode rodar sem informar `ERP`, `OS` e `email`, servindo como leitura local pura;
- a serie prioriza a `BIOS`; quando a BIOS nao trouxer uma serie confiavel, o coletor usa o `MAC` da placa de rede;
- quando houver `OS` informada, o arquivo local passa a usar o padrao `C:\JovemTechBenchCollector\inf_<numero_os>.json`;
- quando nao houver `OS`, o fallback continua sendo `C:\JovemTechBenchCollector\last-snapshot.json`;
- o snapshot local agora sempre registra `collectedAtUtc`, `collectedAtLocal`, `savedAtUtc` e `savedAtLocal`;
- quando a coleta e disparada pelo botao `Buscar do agente (C:\)` dentro do ERP, o arquivo local e enriquecido com um bloco de `OS digital`, incluindo `serviceOrder`, `customer` e `company`;
- nesse fluxo automatico do ERP, o `JovemTechBenchCollector.exe` e o `README.md` sao removidos da pasta local ao final, deixando apenas o JSON final;
- o script `public/assets/agents/jovemtec-monitor-agent.ps1` continua disponivel como fallback tecnico.
