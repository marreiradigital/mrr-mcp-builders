<#
.SYNOPSIS
    Empacota o plugin marreira-mcp-builders num zip versionado em downloads/.

.DESCRIPTION
    Le a versao do header do plugin e gera downloads/marreira-mcp-builders-<versao>.zip
    com a pasta do plugin no topo. As entradas do zip usam BARRA NORMAL (/), nao a
    barra invertida do Windows — senao o WordPress/Linux nao extrai a estrutura de
    pastas (o Compress-Archive do PowerShell 5.1 grava \, o que quebra a instalacao).
    Reproduzivel: pode rodar de qualquer lugar.

.EXAMPLE
    powershell -File scripts/build.ps1
#>
$ErrorActionPreference = 'Stop'

$RepoRoot  = Split-Path -Parent $PSScriptRoot
$PluginDir = Join-Path $RepoRoot 'marreira-mcp-builders'
$MainFile  = Join-Path $PluginDir 'marreira-mcp-builders.php'
$Downloads = Join-Path $RepoRoot 'downloads'

if (-not (Test-Path $MainFile)) {
    throw "Arquivo principal nao encontrado: $MainFile"
}

# Extrai a versao do header "Version: x.y.z".
$header  = Get-Content $MainFile -TotalCount 40
$verLine = $header | Where-Object { $_ -match '^\s*\*\s*Version:\s*(.+)$' } | Select-Object -First 1
if (-not $verLine) { throw 'Nao consegui ler a Version: do header do plugin.' }
$version = ($verLine -replace '^\s*\*\s*Version:\s*', '').Trim()

if (-not (Test-Path $Downloads)) {
    New-Item -ItemType Directory -Path $Downloads | Out-Null
}

$zipPath = Join-Path $Downloads "marreira-mcp-builders-$version.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Write-Host "Empacotando marreira-mcp-builders v$version ..."

Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

# Cria o zip manualmente para forcar separador '/' nas entradas.
$fs  = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
$zip = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $topPrefix = 'marreira-mcp-builders/'
    $baseLen   = $PluginDir.Length + 1
    $level     = [System.IO.Compression.CompressionLevel]::Optimal

    Get-ChildItem -Path $PluginDir -Recurse -File | ForEach-Object {
        $rel   = $_.FullName.Substring($baseLen) -replace '\\', '/'
        $entry = $topPrefix + $rel
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry, $level) | Out-Null
    }
} finally {
    $zip.Dispose()
    $fs.Dispose()
}

$size = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "OK: $zipPath ($size KB)"
