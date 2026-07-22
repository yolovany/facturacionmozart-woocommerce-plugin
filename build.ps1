<#
.SYNOPSIS
    Empaqueta el plugin de WordPress en un .zip versionado y reproducible.

.DESCRIPTION
    Lee la versión desde la cabecera del plugin y genera
    dist/facturacionmozart-woocommerce-plugin-<version>.zip con el layout que
    WordPress espera (carpeta "facturacionmozart-woocommerce-plugin/" en la raíz
    del zip), incluyendo solo los archivos del plugin (no demo/, docker/, docs, LICENSE).

    Cada entrada del zip se crea con separador "/" de forma explícita. Ni
    Compress-Archive ni [ZipFile]::CreateFromDirectory sirven aquí: sobre
    Windows PowerShell 5.1 (.NET Framework) ambos generan entradas con "\", lo que
    rompe la instalación del plugin en WordPress sobre Linux (se extrae como un
    archivo plano en vez de un directorio).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File build.ps1
#>

$ErrorActionPreference = 'Stop'
$slug  = 'facturacionmozart-woocommerce-plugin'
$root  = Split-Path -Parent $MyInvocation.MyCommand.Path
$dist  = Join-Path $root 'dist'

# Leer la versión de la cabecera del plugin.
$main = Join-Path $root "$slug.php"
$linea = Select-String -Path $main -Pattern "^\s*\*\s*Version:\s*(.+)$" | Select-Object -First 1
if (-not $linea) { throw "No se pudo leer la versión del plugin." }
$version = $linea.Matches[0].Groups[1].Value.Trim()

# Archivos a incluir: mapa rutaEnDisco -> rutaDentroDelZip (con "/" y carpeta raíz = slug).
$files = @{}
$files[$main]                        = "$slug/$slug.php"
$files[(Join-Path $root 'readme.txt')] = "$slug/readme.txt"
Get-ChildItem (Join-Path $root 'includes') -Filter *.php | ForEach-Object {
    $files[$_.FullName] = "$slug/includes/$($_.Name)"
}

if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Force $dist | Out-Null
$zip = Join-Path $dist "$slug-$version.zip"

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$fs = [System.IO.File]::Open($zip, [System.IO.FileMode]::Create)
try {
    $archive = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($src in ($files.Keys | Sort-Object { $files[$_] })) {
            $entryName = $files[$src]  # ya usa "/"
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive, $src, $entryName,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally { $archive.Dispose() }
} finally { $fs.Dispose() }

Write-Host ("Generado: {0} ({1:N0} bytes) - version {2}" -f $zip, (Get-Item $zip).Length, $version)
