# Self-test for the host helper's pure functions. No Pester needed, and it runs on any platform with PowerShell 5.1 or 7:
#   powershell -NoProfile -File docker/host-helper/Test-HardwareFingerprint.ps1     (or pwsh)
# Exits 0 when everything passes, 1 otherwise.
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'HardwareFingerprint.ps1')

$script:failures = 0
function Assert-Equal {
    param($Expected, $Actual, [string]$Label)
    if ($Expected -ceq $Actual) { Write-Host "ok   $Label" } else { $script:failures++; Write-Host "FAIL $Label`n     expected: $Expected`n     actual:   $Actual" }
}
function Assert-Throws {
    param([scriptblock]$Block, [string]$Pattern, [string]$Label)
    try { & $Block; $script:failures++; Write-Host "FAIL $Label (did not throw)" }
    catch { if ($_.Exception.Message -match $Pattern) { Write-Host "ok   $Label" } else { $script:failures++; Write-Host "FAIL $Label (wrong message: $($_.Exception.Message))" } }
}

# The same two values the application's and the control plane's golden fixtures use (tests/Fixtures/license-contract).
Assert-Equal '2f61ec04ef9d7e620fbf01b1bb71d332fea413a410ef413a9c401108550d31f7' (Get-ComponentHash -Component 'smbios_uuid' -NormalizedValue 'TEST-UUID') 'smbios_uuid hash matches the shared fixture'
Assert-Equal '9895e89fdcf5e3ee7a31f5339b6cec532598ff20622a428f09785e8d5a6b3e1e' (Get-ComponentHash -Component 'board_serial' -NormalizedValue 'TEST-BOARD') 'board_serial hash matches the shared fixture'

Assert-Equal 'ABC 123' (ConvertTo-NormalizedHardwareValue "  abc`t  123 `r`n") 'white space collapses and case folds'
Assert-Equal '4C4C4544-0042-4410-8051-C7C04F4E4D32' (ConvertTo-NormalizedHardwareValue '{4c4c4544-0042-4410-8051-c7c04f4e4d32}') 'braces are dropped'
Assert-Equal '' (ConvertTo-NormalizedHardwareValue $null) 'null is empty'

foreach ($placeholder in @('', ' ', 'None', 'To be filled by O.E.M.', 'Default string', 'System Serial Number', '0', '00000000-0000-0000-0000-000000000000', 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF', 'N/A', 'Base Board Serial Number', '123456789', 'ab')) {
    Assert-Equal $true (Test-PlaceholderHardwareValue (ConvertTo-NormalizedHardwareValue $placeholder)) "placeholder refused: '$placeholder'"
}
foreach ($real in @('4C4C4544-0042-4410-8051-C7C04F4E4D32', 'PF2ABC12', 'L1HF7AB0012', 'AMI 12.34')) {
    Assert-Equal $false (Test-PlaceholderHardwareValue (ConvertTo-NormalizedHardwareValue $real)) "real value accepted: '$real'"
}

$raw = @{ smbios_uuid = ' test-uuid '; board_serial = 'Test-Board'; bios_serial = 'TEST-BIOS' }
$document = New-HardwareDocument -Raw $raw -GeneratedAt '2026-11-28T09:00:00+08:00' | ConvertFrom-Json
Assert-Equal 1 $document.v 'document version'
Assert-Equal '2026-11-28T09:00:00+08:00' $document.generated_at 'document records the host clock'
Assert-Equal '2f61ec04ef9d7e620fbf01b1bb71d332fea413a410ef413a9c401108550d31f7' $document.fp.smbios_uuid 'normalised before hashing (case and spaces do not change the hash)'
Assert-Equal '9895e89fdcf5e3ee7a31f5339b6cec532598ff20622a428f09785e8d5a6b3e1e' $document.fp.board_serial 'board hash'
Assert-Equal 'smbios_uuid,board_serial,bios_serial' (($document.fp.PSObject.Properties.Name) -join ',') 'components are listed in the documented order'

Assert-Throws { New-HardwareDocument -Raw @{ smbios_uuid = 'TEST-UUID'; board_serial = 'To be filled by O.E.M.'; bios_serial = 'None' } -GeneratedAt 'x' } 'board_serial, bios_serial' 'a machine with placeholder serials is refused and the components are named'
Assert-Throws { New-HardwareDocument -Raw @{ smbios_uuid = 'TEST-UUID'; board_serial = 'X'; bios_serial = 'TEST-BIOS' } -GeneratedAt 'x' } 'board_serial' 'a missing component is refused'

Assert-Equal 'board_serial,bios_serial' (@(Get-UnusableComponents -Raw @{ smbios_uuid = 'TEST-UUID'; board_serial = 'None'; bios_serial = '' }) -join ',') 'the commissioning check names the unusable components, in order'
Assert-Equal 0 @(Get-UnusableComponents -Raw @{ smbios_uuid = 'TEST-UUID'; board_serial = 'TEST-BOARD'; bios_serial = 'TEST-BIOS' }).Count 'and none when all three are usable'

$temp = Join-Path ([System.IO.Path]::GetTempPath()) ("tindaflow-hh-" + [guid]::NewGuid().ToString('N'))
try {
    $path = Join-Path $temp 'host\hardware.json'
    Write-FileAtomically -Path $path -Content '{"first":true}'
    Assert-Equal '{"first":true}' (Get-Content -LiteralPath $path -Raw) 'first write creates the directory and the file'
    Write-FileAtomically -Path $path -Content '{"second":true}'
    Assert-Equal '{"second":true}' (Get-Content -LiteralPath $path -Raw) 'second write replaces it'
    Assert-Equal 1 @(Get-ChildItem -LiteralPath (Split-Path -Parent $path)).Count 'no temporary file is left behind'
    $bytes = [System.IO.File]::ReadAllBytes($path)
    Assert-Equal $false ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) 'UTF-8 without a byte order mark'
}
finally {
    Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
}

if ($script:failures -gt 0) { Write-Host "$($script:failures) failure(s)"; exit 1 }
Write-Host 'all host helper checks passed'
exit 0
