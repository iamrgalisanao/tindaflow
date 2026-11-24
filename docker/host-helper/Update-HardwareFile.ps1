<#
.SYNOPSIS
    Writes the hardware fingerprint file the TindaFlow application reads (ADR-018 decision 4).

.DESCRIPTION
    Reads the SMBIOS UUID, the base board serial and the BIOS serial from Windows, normalises them, refuses placeholder values, hashes
    each as SHA-256("tindaflow-fp-v1:" component ":" value), and writes hardware.json atomically. The application holds only the hashes.
    Run by a scheduled task as SYSTEM at start-up and every hour (Install-HostHelper.ps1).

    On any failure the previous file is left in place (it simply ages: the application treats a file older than 26 hours as missing),
    the reason is appended to hardware-helper.log beside it, and the script exits 1.
#>
[CmdletBinding()]
param(
    [string]$OutputPath = (Join-Path $env:ProgramData 'TindaFlow\host\hardware.json'),
    # Commissioning check: says which of the three components this computer can supply, writes nothing, prints no values. Exit 0 = all three usable.
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'HardwareFingerprint.ps1')

$logPath = Join-Path (Split-Path -Parent $OutputPath) 'hardware-helper.log'

function Write-HelperLog {
    param([string]$Message)

    try {
        $directory = Split-Path -Parent $logPath
        if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -Force | Out-Null }
        $line = "{0} {1}" -f (Get-Date -Format "yyyy-MM-dd'T'HH:mm:sszzz"), $Message
        $existing = if (Test-Path -LiteralPath $logPath) { @(Get-Content -LiteralPath $logPath -Tail 199) } else { @() }
        Set-Content -LiteralPath $logPath -Value ($existing + $line) -Encoding UTF8
    }
    catch {
        # Logging must never be the reason the helper fails.
    }
}

try {
    if (-not (Get-Command Get-CimInstance -ErrorAction SilentlyContinue)) {
        throw 'This script reads Windows hardware information and must run on Windows.'
    }

    $raw = @{
        smbios_uuid  = (Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID
        board_serial = (Get-CimInstance -ClassName Win32_BaseBoard).SerialNumber
        bios_serial  = (Get-CimInstance -ClassName Win32_BIOS).SerialNumber
    }

    if ($Check) {
        $unusable = @(Get-UnusableComponents -Raw $raw)
        foreach ($component in 'smbios_uuid', 'board_serial', 'bios_serial') {
            Write-Host ("{0,-13} {1}" -f $component, $(if ($unusable -contains $component) { 'PLACEHOLDER or missing' } else { 'usable' }))
        }
        if ($unusable.Count -gt 0) { Write-Host 'This computer cannot be bound to a license as it is.'; exit 1 }
        Write-Host 'All three components are usable.'
        exit 0
    }

    $generatedAt = Get-Date -Format "yyyy-MM-dd'T'HH:mm:sszzz"
    Write-FileAtomically -Path $OutputPath -Content (New-HardwareDocument -Raw $raw -GeneratedAt $generatedAt)
}
catch {
    Write-HelperLog "FAILED: $($_.Exception.Message)"
    Write-Error $_.Exception.Message
    exit 1
}
