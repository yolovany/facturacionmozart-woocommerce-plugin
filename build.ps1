<#
.SYNOPSIS
    Empaqueta el plugin de WordPress en un .zip versionado y reproducible.

.DESCRIPTION
    Lee la versión desde la cabecera del plugin y genera dist/facturacion-cfdi-<version>.zip
    con el layout que WordPress espera (carpeta "facturacion-cfdi/" en la raíz del zip),
    incluyendo solo los archivos del plugin (no demo/, docker/, docs, LICENSE).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File build.ps1
#>

$ErrorActionPreference = 'Stop'
$root  = Split-Path -Parent $MyInvocation.MyCommand.Path
$dist  = Join-Path $root 'dist'
$stage = Join-Path $dist 'facturacion-cfdi'

# Leer la versión de la cabecera del plugin.
$main = Join-Path $root 'facturacion-cfdi.php'
$linea = Select-String -Path $main -Pattern "^\s*\*\s*Version:\s*(.+)$" | Select-Object -First 1
if (-not $linea) { throw "No se pudo leer la versión del plugin." }
$version = $linea.Matches[0].Groups[1].Value.Trim()

if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Force $stage | Out-Null

Copy-Item (Join-Path $root 'facturacion-cfdi.php') $stage
Copy-Item (Join-Path $root 'readme.txt') $stage
Copy-Item (Join-Path $root 'includes') $stage -Recurse

$zip = Join-Path $dist "facturacion-cfdi-$version.zip"
Compress-Archive -Path $stage -DestinationPath $zip
Remove-Item $stage -Recurse -Force

Write-Host ("Generado: {0} ({1:N0} bytes) - version {2}" -f $zip, (Get-Item $zip).Length, $version)
