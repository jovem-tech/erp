# Coletor de Bancada Jovem Tech - build Windows (PowerShell).
#
# Roda diretamente NA MAQUINA DO CLIENTE (o computador sendo diagnosticado) -
# nao precisa de instalacao, so copiar este arquivo pra uma pasta (ex.:
# %USERPROFILE%\JovemTechBenchCollector\) e executar. Le o inventario de
# hardware da propria maquina (sem precisar rodar como administrador) e:
#   1) grava um snapshot local em JSON, do lado do cliente (auditoria/debug);
#   2) se --pairing-code + --erp-base-url + --collector-token forem
#      informados, ENVIA o snapshot pela rede para o ERP
#      (POST /api/v1/collector/snapshots), pareado ao codigo gerado na tela
#      de cadastro do equipamento.
#
# Uso tipico (o tecnico gera o codigo na tela do ERP e roda isto na maquina
# do cliente, na mesma rede local):
#   powershell -ExecutionPolicy Bypass -File .\jovemtech-bench-collector.ps1 `
#       --pairing-code=ABCD1234 `
#       --erp-base-url=https://192.168.1.100:8443 `
#       --collector-token=<token>
#
# Uso local, so para ler o proprio hardware sem enviar nada (debug):
#   powershell -ExecutionPolicy Bypass -File .\jovemtech-bench-collector.ps1 --dry-run
#
# Mesmo contrato de campos JSON usado pelo build Linux
# (jovemtech-bench-collector.sh) e pelo antigo JovemTechBenchCollector.exe -
# qualquer um dos tres pode preencher o painel tecnico do cadastro.
#
# Fallback de numero de serie (mesma regra do build Linux e do .exe antigo):
# prioriza o serial da BIOS/placa-mae/chassi; quando nenhum vem preenchido
# (comum em clones/whitebox), cai para o endereco MAC da primeira placa de
# rede fisica.

$ErrorActionPreference = 'SilentlyContinue'

$DryRun = $false
$PairingCode = ''
$ErpBaseUrl = ''
$CollectorToken = ''

foreach ($arg in $args) {
    if ($arg -eq '--dry-run') {
        $DryRun = $true
    } elseif ($arg -eq '--no-prompt' -or $arg -eq '--no-save-config') {
        # existem so por compatibilidade com a mesma linha de comando do
        # .exe antigo - este script nunca pede confirmacao interativa.
    } elseif ($arg -match '^--pairing-code=(.*)$') {
        $PairingCode = $Matches[1]
    } elseif ($arg -match '^--erp-base-url=(.*)$') {
        $ErpBaseUrl = $Matches[1].TrimEnd('/')
    } elseif ($arg -match '^--collector-token=(.*)$') {
        $CollectorToken = $Matches[1]
    }
}

function Clean-DmiValue {
    param([string]$Value)

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return ''
    }

    $trimmed = $Value.Trim()
    $lower = $trimmed.ToLowerInvariant()
    $placeholders = @('to be filled', 'system manufacturer', 'system product name', 'default string', 'o.e.m', 'not specified', 'none')

    foreach ($placeholder in $placeholders) {
        if ($lower.Contains($placeholder)) {
            return ''
        }
    }

    return $trimmed
}

# --- deteccao de hardware (via WMI/CIM, sem precisar de administrador) -----

$baseBoard = Get-CimInstance -ClassName Win32_BaseBoard -ErrorAction SilentlyContinue | Select-Object -First 1
$bios = Get-CimInstance -ClassName Win32_BIOS -ErrorAction SilentlyContinue | Select-Object -First 1
$computerSystem = Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction SilentlyContinue | Select-Object -First 1
$processor = Get-CimInstance -ClassName Win32_Processor -ErrorAction SilentlyContinue | Select-Object -First 1
$enclosure = Get-CimInstance -ClassName Win32_SystemEnclosure -ErrorAction SilentlyContinue | Select-Object -First 1
$videoControllers = @(Get-CimInstance -ClassName Win32_VideoController -ErrorAction SilentlyContinue)
$diskDrives = @(Get-CimInstance -ClassName Win32_DiskDrive -ErrorAction SilentlyContinue)

$boardVendor = Clean-DmiValue $baseBoard.Manufacturer
$boardName = Clean-DmiValue $baseBoard.Product
$biosVendor = Clean-DmiValue $bios.Manufacturer
$biosVersion = Clean-DmiValue $bios.SMBIOSBIOSVersion
$sysVendor = Clean-DmiValue $computerSystem.Manufacturer
$productName = Clean-DmiValue $computerSystem.Model

$motherboard = (@($boardVendor, $boardName) | Where-Object { $_ -ne '' }) -join ' '
$manufacturer = $sysVendor
$model = $productName

$serial = ''
$serialSource = ''
foreach ($candidate in @(
    (Clean-DmiValue $bios.SerialNumber),
    (Clean-DmiValue $baseBoard.SerialNumber),
    (Clean-DmiValue $enclosure.SerialNumber)
)) {
    if ($candidate -ne '') {
        $serial = $candidate
        $serialSource = 'bios'
        break
    }
}

if ($serial -eq '') {
    $nic = Get-CimInstance -ClassName Win32_NetworkAdapter -ErrorAction SilentlyContinue | Where-Object {
        $_.PhysicalAdapter -eq $true -and $_.MACAddress -and $_.MACAddress -ne '00:00:00:00:00:00'
    } | Select-Object -First 1

    if ($nic) {
        $serial = $nic.MACAddress
        $serialSource = 'mac_address'
    }
}

$cpu = Clean-DmiValue $processor.Name

$ramGb = $null
$memorySummary = ''
if ($computerSystem.TotalPhysicalMemory) {
    $ramGbValue = [Math]::Round([double]$computerSystem.TotalPhysicalMemory / 1GB, 2)
    $ramGb = $ramGbValue
    $memorySummary = "$ramGbValue GB"
}

$storageParts = @()
foreach ($disk in $diskDrives) {
    $sizeGb = 0
    if ($disk.Size) {
        $sizeGb = [Math]::Round([double]$disk.Size / 1GB, 0)
    }
    $label = if ($disk.Caption) { $disk.Caption } else { $disk.Model }
    $storageParts += "$label (${sizeGb}GB)"
}
$storageSummary = $storageParts -join '; '

$gpuParts = @()
foreach ($video in $videoControllers) {
    if ($video.Name) {
        $gpuParts += $video.Name
    }
}
$gpu = $gpuParts -join '; '

# Mesmos codigos numericos SMBIOS usados pelo /sys/class/dmi/id/chassis_type
# no Linux - Win32_SystemEnclosure.ChassisTypes usa a mesma tabela.
$chassisTypeCode = $null
if ($enclosure -and $enclosure.ChassisTypes -and $enclosure.ChassisTypes.Count -gt 0) {
    $chassisTypeCode = [int]$enclosure.ChassisTypes[0]
}

$chassisType = ''
$deviceType = 'desktop'
switch ($chassisTypeCode) {
    3  { $chassisType = 'Desktop' }
    4  { $chassisType = 'Low Profile Desktop' }
    5  { $chassisType = 'Pizza Box' }
    6  { $chassisType = 'Mini Tower' }
    7  { $chassisType = 'Tower' }
    8  { $chassisType = 'Portable'; $deviceType = 'notebook' }
    9  { $chassisType = 'Laptop'; $deviceType = 'notebook' }
    10 { $chassisType = 'Notebook'; $deviceType = 'notebook' }
    11 { $chassisType = 'Hand Held'; $deviceType = 'notebook' }
    13 { $chassisType = 'All In One' }
    14 { $chassisType = 'Sub Notebook'; $deviceType = 'notebook' }
    15 { $chassisType = 'Space Saving' }
    16 { $chassisType = 'Lunch Box' }
    17 { $chassisType = 'Main Server Chassis' }
    23 { $chassisType = 'Rack Mount Chassis' }
    24 { $chassisType = 'Sealed Case PC' }
    30 { $chassisType = 'Tablet'; $deviceType = 'notebook' }
    31 { $chassisType = 'Convertible'; $deviceType = 'notebook' }
    32 { $chassisType = 'Detachable'; $deviceType = 'notebook' }
    35 { $chassisType = 'Mini PC' }
    36 { $chassisType = 'Stick PC' }
    default { $chassisType = '' }
}

# --- monta e grava o snapshot local -----------------------------------------

$nowUtc = (Get-Date).ToUniversalTime()
$nowLocal = Get-Date

$snapshot = [ordered]@{
    motherboard    = $motherboard
    chipset        = ''
    cpu            = $cpu
    gpu            = $gpu
    storageSummary = $storageSummary
    memorySummary  = $memorySummary
    ramGb          = $ramGb
    serialNumber   = $serial
    serialSource   = $serialSource
    manufacturer   = $manufacturer
    model          = $model
    deviceType     = $deviceType
    chassisType    = $chassisType
    biosVendor     = $biosVendor
    biosVersion    = $biosVersion
}

$envelope = [ordered]@{
    collectedAtUtc   = $nowUtc.ToString('yyyy-MM-ddTHH:mm:ssZ')
    collectedAtLocal = $nowLocal.ToString("yyyy-MM-ddTHH:mm:sszzz")
    savedAtUtc       = $nowUtc.ToString('yyyy-MM-ddTHH:mm:ssZ')
    savedAtLocal     = $nowLocal.ToString("yyyy-MM-ddTHH:mm:sszzz")
    agentVersion     = 'win-x64-ps-1.0'
    hostname         = $env:COMPUTERNAME
    snapshot         = $snapshot
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$snapshotPath = Join-Path $scriptDir 'last-snapshot.json'
$envelope | ConvertTo-Json -Depth 6 | Set-Content -Path $snapshotPath -Encoding UTF8

Write-Host "Snapshot local gravado em $snapshotPath"

if ($DryRun) {
    Write-Host 'Modo --dry-run: nada foi enviado pela rede.'
    exit 0
}

if ([string]::IsNullOrWhiteSpace($PairingCode) -or [string]::IsNullOrWhiteSpace($ErpBaseUrl) -or [string]::IsNullOrWhiteSpace($CollectorToken)) {
    Write-Host 'Nenhum --pairing-code/--erp-base-url/--collector-token informado - apenas o snapshot local foi gravado.'
    exit 0
}

# --- check-in pela rede: envia o snapshot para o ERP ------------------------

$requestBody = [ordered]@{
    pairing_code  = $PairingCode
    snapshot      = $snapshot
    source        = 'win-x64'
    agent_version = 'win-x64-ps-1.0'
    hostname      = $env:COMPUTERNAME
} | ConvertTo-Json -Depth 6

$uri = "$ErpBaseUrl/api/v1/collector/snapshots"

$requestParams = @{
    Uri         = $uri
    Method      = 'Post'
    Headers     = @{ 'X-Collector-Token' = $CollectorToken }
    ContentType = 'application/json'
    Body        = $requestBody
}

if ($PSVersionTable.PSVersion.Major -ge 6) {
    # PowerShell 7+: suporte nativo pra aceitar certificado autoassinado
    # (a rede local da assistencia normalmente nao tem certificado publico).
    $requestParams['SkipCertificateCheck'] = $true
} else {
    # Windows PowerShell 5.1: Invoke-RestMethod usa ServicePointManager, que
    # precisa desse callback (scriptblock convertido pro delegate nativo)
    # pra aceitar o certificado autoassinado do ERP.
    try {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
        [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
    } catch {
    }
}

try {
    Invoke-RestMethod @requestParams | Out-Null
    Write-Host "Check-in enviado com sucesso para $ErpBaseUrl (codigo $PairingCode)."
    exit 0
} catch {
    $statusCode = $null
    if ($_.Exception.Response) {
        $statusCode = [int]$_.Exception.Response.StatusCode
    }
    Write-Host "ERRO: falha ao enviar o check-in (HTTP $statusCode). $($_.Exception.Message)"
    exit 1
}
