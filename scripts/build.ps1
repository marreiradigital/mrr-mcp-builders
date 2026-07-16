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

# ---------------------------------------------------------------------------
# Sincroniza a versao exibida no site (docs/index.html) com o header do plugin.
#
# A versao aparece em varios pontos do HTML (badge do topo, badge do shields,
# tabela comparativa, links do zip, rodape). Mantida na mao, ela defasa: o site
# ja anunciou "v1.0.0" enquanto o plugin estava em 1.3.1 e os links do zip
# apontavam pra 1.3.1. Aqui o header do plugin e a fonte unica: o build reescreve
# todos os pontos a partir dele, entao nao ha o que esquecer de atualizar.
#
# Este bloco e ASCII puro de proposito. O PowerShell 5.1 le .ps1 sem BOM como
# ANSI, entao um caractere UTF-8 dentro de uma string vira mojibake que termina
# em aspa curva, e o PowerShell aceita aspa curva como delimitador de string,
# quebrando o parse do arquivo inteiro. O separador do rodape e montado com
# [char]0xB7 justamente pra nao depender da codificacao deste arquivo.
# ---------------------------------------------------------------------------
$IndexHtml = Join-Path $RepoRoot 'docs/index.html'

if (Test-Path $IndexHtml) {
    $semver = '\d+\.\d+\.\d+'
    $dot    = [char]0xB7   # middle dot que separa os itens do rodape do site
    $html   = [System.IO.File]::ReadAllText($IndexHtml)
    $before = $html

    # Nome do arquivo do zip (href do download e o texto do link/instrucao).
    $html = $html -replace "marreira-mcp-builders-$semver\.zip", "marreira-mcp-builders-$version.zip"
    # Badge de versao ao lado do nome, no topo.
    $html = $html -replace "(<span class=`"ver`">v)$semver(</span>)", "`${1}$version`${2}"
    # Badge do shields.io (texto alternativo e a propria URL url-encoded).
    $html = $html -replace "(alt=`"Versao )$semver(`")", "`${1}$version`${2}"
    $html = $html -replace "(vers%C3%A3o-)$semver(-)", "`${1}$version`${2}"
    # Cabecalho da tabela "Antes (2 plugins) / Agora (unificado x.y.z)".
    $html = $html -replace "(unificado )$semver(\))", "`${1}$version`${2}"
    # Rodape.
    $html = $html -replace "(MarreiraMCP Builders $dot v)$semver( $dot)", "`${1}$version`${2}"

    if ($html -ne $before) {
        # UTF8Encoding($false) = sem BOM. O Set-Content -Encoding utf8 do
        # PowerShell 5.1 grava BOM, que sujaria o HTML.
        [System.IO.File]::WriteAllText($IndexHtml, $html, (New-Object System.Text.UTF8Encoding($false)))
        Write-Host "OK: docs/index.html sincronizado para v$version"
    } else {
        Write-Host "docs/index.html ja estava em v$version"
    }

    # Rede de seguranca: se sobrou alguma versao diferente da atual no HTML, e
    # porque surgiu um ponto novo que os regex acima nao cobrem. Avisa em vez de
    # deixar o site sair defasado em silencio.
    $stale = [regex]::Matches($html, "marreira-mcp-builders-$semver\.zip|v$semver|vers%C3%A3o-$semver") |
        Where-Object { $_.Value -notmatch [regex]::Escape($version) } |
        Select-Object -ExpandProperty Value -Unique

    if ($stale) {
        Write-Warning "docs/index.html ainda menciona versao(oes) diferente(s) de $version : $($stale -join ', ')"
    }
} else {
    Write-Warning "docs/index.html nao encontrado: versao do site nao sincronizada."
}

# ---------------------------------------------------------------------------
# Mesma ideia para o README.md do GitHub, que tambem cita a versao na mao (badge
# do shields e tabela de linhagem). Ele ja tinha ficado parado na 1.0.0 enquanto
# o plugin estava na 1.3.1, e o badge de PHP ainda dizia 8.0 depois de o piso
# descer pra 7.4.
#
# O badge do README escreve "versao" acentuado. Esse caractere e montado com
# [char]0xE3, nunca cru no fonte: ver a nota sobre .ps1 sem BOM no bloco do
# docs/index.html acima. O site usa a forma url-encoded (%C3%A3), entao os dois
# formatos entram na alternancia.
# ---------------------------------------------------------------------------
$ReadmeMd = Join-Path $RepoRoot 'README.md'

if (Test-Path $ReadmeMd) {
    $semver = '\d+\.\d+\.\d+'
    $atil   = [char]0xE3   # a com til, do texto "versao" do badge
    $md     = [System.IO.File]::ReadAllText($ReadmeMd)
    $before = $md

    # Badge de versao do shields.io (forma acentuada e forma url-encoded).
    $md = $md -replace "(badge/vers%C3%A3o-|badge/vers${atil}o-)$semver(-)", "`${1}$version`${2}"
    # Linha do plugin na tabela de linhagem: | **MarreiraMCP Builders** | **x.y.z** |
    $md = $md -replace "(\*\*MarreiraMCP Builders\*\*\s*\|\s*\*\*)$semver(\*\*)", "`${1}$version`${2}"

    if ($md -ne $before) {
        [System.IO.File]::WriteAllText($ReadmeMd, $md, (New-Object System.Text.UTF8Encoding($false)))
        Write-Host "OK: README.md sincronizado para v$version"
    } else {
        Write-Host "README.md ja estava em v$version"
    }
} else {
    Write-Warning "README.md nao encontrado: versao do README nao sincronizada."
}

# ---------------------------------------------------------------------------
# Manifesto de atualizacao (docs/update.json), servido pelo GitHub Pages.
#
# E o que o Updater do plugin le pra saber se ha versao nova e oferecer o update
# no wp-admin. Fica aqui, e nao na API do GitHub, porque a API limita a 60
# requisicoes/hora POR IP sem autenticacao — em hospedagem compartilhada varios
# sites saem pelo mesmo IP e a checagem falharia. O Pages serve por CDN, sem
# limite.
#
# `package` aponta pro asset de nome FIXO da release (nao o versionado): assim
# este arquivo nao precisa mudar de URL a cada versao, so de numero. O Updater
# recusa qualquer package fora do prefixo das Releases deste repo.
# ---------------------------------------------------------------------------
$UpdateJson = Join-Path $RepoRoot 'docs/update.json'
$DocsDir    = Join-Path $RepoRoot 'docs'

if (Test-Path $DocsDir) {
    # Le "Requires at least", "Requires PHP" e "Tested up to" das fontes que ja
    # existem, em vez de repetir os numeros aqui.
    $requires    = ($header | Where-Object { $_ -match '^\s*\*\s*Requires at least:\s*(.+)$' } | Select-Object -First 1) -replace '^\s*\*\s*Requires at least:\s*', ''
    $requiresPhp = ($header | Where-Object { $_ -match '^\s*\*\s*Requires PHP:\s*(.+)$' }      | Select-Object -First 1) -replace '^\s*\*\s*Requires PHP:\s*', ''

    $readmeTxt = Join-Path $PluginDir 'readme.txt'
    $tested    = ''
    if (Test-Path $readmeTxt) {
        $testedLine = Get-Content $readmeTxt -TotalCount 20 | Where-Object { $_ -match '^Tested up to:\s*(.+)$' } | Select-Object -First 1
        if ($testedLine) { $tested = ($testedLine -replace '^Tested up to:\s*', '').Trim() }
    }

    $manifest = [ordered]@{
        version      = $version
        package      = 'https://github.com/marreiradigital/mrr-mcp-builders/releases/latest/download/marreira-mcp-builders.zip'
        url          = 'https://marreiradigital.github.io/mrr-mcp-builders/'
        requires     = $requires.Trim()
        requires_php = $requiresPhp.Trim()
        tested       = $tested
    }

    $json = ($manifest | ConvertTo-Json -Depth 3)
    [System.IO.File]::WriteAllText($UpdateJson, $json, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "OK: docs/update.json gerado (v$version, WP $($manifest.requires)+, PHP $($manifest.requires_php)+)"
} else {
    Write-Warning "docs/ nao encontrado: update.json nao gerado."
}
