<#
.SYNOPSIS
    Installs the TindaFlow host helper as two scheduled tasks that run as SYSTEM (ADR-018 decision 4).

.DESCRIPTION
    Copies the helper to a folder only administrators can change, so SYSTEM never runs code a normal user could have edited; creates
    the shared folder (SYSTEM and administrators write, everyone reads) the application container reads, and registers one task that
    runs at start-up and one that runs every hour. Runs the helper once so the file exists straight away.

    Run from an elevated PowerShell. The default execution policy for the tasks is AllSigned: the scripts must be signed with the
    code-signing certificate arranged in Phase 1. For a rehearsal on an unsigned copy, pass -ExecutionPolicy RemoteSigned or Bypass.
#>
[CmdletBinding()]
param(
    [string]$InstallDirectory = (Join-Path $env:ProgramFiles 'TindaFlow\host-helper'),
    [string]$DataDirectory = (Join-Path $env:ProgramData 'TindaFlow\host'),
    [ValidateSet('AllSigned', 'RemoteSigned', 'Bypass')][string]$ExecutionPolicy = 'AllSigned'
)

$ErrorActionPreference = 'Stop'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated (administrator) PowerShell.'
}

# The code SYSTEM runs: copied somewhere only administrators can write.
New-Item -ItemType Directory -Path $InstallDirectory -Force | Out-Null
foreach ($file in 'HardwareFingerprint.ps1', 'Update-HardwareFile.ps1') {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination $InstallDirectory -Force
}
& icacls $InstallDirectory /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' 'Users:(OI)(CI)RX' | Out-Null

# The data the container reads: SYSTEM writes, everyone reads.
New-Item -ItemType Directory -Path $DataDirectory -Force | Out-Null
& icacls $DataDirectory /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' 'Users:(OI)(CI)RX' | Out-Null

$script = Join-Path $InstallDirectory 'Update-HardwareFile.ps1'
$output = Join-Path $DataDirectory 'hardware.json'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy $ExecutionPolicy -File `"$script`" -OutputPath `"$output`""
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

$atStartup = New-ScheduledTaskTrigger -AtStartup
$hourly = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Hours 1)

Register-ScheduledTask -TaskName 'TindaFlow Host Helper (start-up)' -Action $action -Trigger $atStartup -Principal $principal -Settings $settings -Force | Out-Null
Register-ScheduledTask -TaskName 'TindaFlow Host Helper (hourly)' -Action $action -Trigger $hourly -Principal $principal -Settings $settings -Force | Out-Null

Start-ScheduledTask -TaskName 'TindaFlow Host Helper (start-up)'
Start-Sleep -Seconds 5

if (Test-Path -LiteralPath $output) {
    Write-Host "Installed. The fingerprint file is at $output."
}
else {
    Write-Warning "Installed, but $output was not written. Read hardware-helper.log in $DataDirectory for the reason."
    exit 1
}
