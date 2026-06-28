param(
    [string]$Root = "C:\xampp\htdocs\sistema-erp"
)

$required = @(
    "$Root\backend\public",
    "$Root\backend\storage\app\private",
    "$Root\backend\storage\logs",
    "$Root\frontends\mobile",
    "$Root\frontends\desktop",
    "$Root\documentacao"
)

$failed = $false
foreach ($path in $required) {
    if (Test-Path $path) {
        Write-Host "OK  $path"
    } else {
        Write-Host "FALHA $path"
        $failed = $true
    }
}

function Get-ListeningProcesses {
    param(
        [int]$Port
    )

    try {
        return Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction Stop |
            Select-Object -ExpandProperty OwningProcess -Unique |
            ForEach-Object { Get-Process -Id $_ -ErrorAction SilentlyContinue } |
            Where-Object { $_ }
    } catch {
        return @()
    }
}

try {
    $mobilePortOwners = Get-ListeningProcesses -Port 3001 |
        Where-Object { $_.ProcessName -eq 'httpd' }

    if ($mobilePortOwners) {
        Write-Host "FALHA Porta 3001 ocupada pelo Apache/XAMPP. O frontends/mobile precisa desta porta livre ou vai iniciar em outra porta."
        $failed = $true
    } else {
        Write-Host "OK  Porta 3001 nao esta reservada pelo Apache/XAMPP"
    }
} catch {
    Write-Host "AVISO Nao foi possivel validar a porta 3001 automaticamente."
}

try {
    $chatPortOwners = Get-ListeningProcesses -Port 3002

    if (-not $chatPortOwners) {
        Write-Host "OK  Porta 3002 livre para o frontends/chat"
    } elseif ($chatPortOwners.ProcessName -contains 'node') {
        Write-Host "OK  Porta 3002 ja esta em uso por Node.js (frontends/chat provavelmente ja esta rodando)"
    } else {
        $ownerList = ($chatPortOwners | Select-Object -ExpandProperty ProcessName -Unique) -join ', '
        Write-Host "FALHA Porta 3002 ocupada por: $ownerList. Libere a porta antes de rodar o frontends/chat."
        $failed = $true
    }
} catch {
    Write-Host "AVISO Nao foi possivel validar a porta 3002 automaticamente."
}

try {
    $reverbPortOwners = Get-ListeningProcesses -Port 8090

    if (-not $reverbPortOwners) {
        Write-Host "OK  Porta 8090 livre para o Laravel Reverb"
    } elseif ($reverbPortOwners.ProcessName -contains 'php') {
        Write-Host "OK  Porta 8090 ja esta em uso por PHP (Reverb provavelmente ja esta rodando)"
    } else {
        $ownerList = ($reverbPortOwners | Select-Object -ExpandProperty ProcessName -Unique) -join ', '
        Write-Host "FALHA Porta 8090 ocupada por: $ownerList. Libere a porta antes de rodar o Reverb."
        $failed = $true
    }
} catch {
    Write-Host "AVISO Nao foi possivel validar a porta 8090 automaticamente."
}

if ($failed) {
    exit 1
}

Write-Host "Ambiente de desenvolvimento validado."
