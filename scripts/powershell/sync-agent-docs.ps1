param(
    [string]$Root = (Resolve-Path (Join-Path $PSScriptRoot "..\\..")).Path
)

$script = Join-Path $Root "scripts\\php\\sync-agent-docs.php"

if (-not (Test-Path $script)) {
    Write-Error "Script nao encontrado: $script"
    exit 1
}

php $script
