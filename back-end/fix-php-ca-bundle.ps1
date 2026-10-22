# One-time local PHP fix for VNPay reconciliation.
#
# PHP's cURL build has no CA bundle, so every outbound HTTPS request fails with
# "cURL error 60: unable to get local issuer certificate". That made VNPay
# querydr unreachable, which left every payment stuck in PENDING and showed the
# customer "the restaurant must verify this payment".
#
# Run this PowerShell script as Administrator. It backs up php.ini first.

$ErrorActionPreference = 'Stop'

$phpDir  = Split-Path -Parent (Get-Command php).Source
$ini     = Join-Path $phpDir 'php.ini'
$bundle  = 'D:\LVTN-QLNH-MiCay\LVTN-QLNH\back-end\storage\app\cacert.pem'

if (-not (Test-Path $ini))    { throw "php.ini not found at $ini" }
if (-not (Test-Path $bundle)) { throw "CA bundle missing at $bundle. Download https://curl.se/ca/cacert.pem there first." }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { throw 'Run this script from an Administrator PowerShell prompt.' }

$backup = "$ini.bak-before-vnpay-fix"
if (-not (Test-Path $backup)) {
    Copy-Item $ini $backup
    Write-Host "Backup created: $backup"
}

$content = Get-Content $ini -Raw

foreach ($key in @('curl.cainfo', 'openssl.cafile')) {
    $line = "$key = $bundle"
    if ($content -match "(?m)^\s*;?\s*$([regex]::Escape($key))\s*=.*$") {
        $content = [regex]::Replace($content, "(?m)^\s*;?\s*$([regex]::Escape($key))\s*=.*$", [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $line })
    } else {
        $content = [regex]::Replace($content, '(?m)^\[PHP\]\s*$', "[PHP]`r`n$line")
    }
    Write-Host "Set $key"
}

[System.IO.File]::WriteAllText($ini, $content)
Write-Host "Patched: $ini"

Write-Host ''
Write-Host 'Verifying:'
& php -r "echo 'cainfo = '.var_export(ini_get('curl.cainfo'), true).PHP_EOL; echo 'curl = '.(extension_loaded('curl')?'loaded':'MISSING').PHP_EOL;"
