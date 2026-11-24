# ADR-018 decision 4: the pure parts of the host helper. Dot-source this file; it defines functions and runs nothing.
#
# Reads nothing itself. Update-HardwareFile.ps1 gets the three raw values from Windows and calls these. Everything here is
# deterministic and platform-independent, so it is tested (Test-HardwareFingerprint.ps1) on any machine, and the hashes are the same
# ones the application and the control plane compute: SHA-256 of "tindaflow-fp-v1:" + component + ":" + value, lower-case hex.

Set-StrictMode -Version 2.0

$script:FingerprintPrefix = 'tindaflow-fp-v1'

# The three components, in the order the file lists them. Names match the certificate's `fp` keys.
$script:FingerprintComponents = @('smbios_uuid', 'board_serial', 'bios_serial')

# Values a manufacturer leaves in place of a real serial. Compared after normalisation, so case and spacing do not matter.
$script:PlaceholderValues = @(
    'NONE', 'N/A', 'NA', 'NULL', 'UNKNOWN', 'INVALID', 'DEFAULT', 'DEFAULT STRING', 'TO BE FILLED BY O.E.M.', 'TO BE FILLED BY OEM',
    'O.E.M.', 'OEM', 'NOT SPECIFIED', 'NOT APPLICABLE', 'NOT AVAILABLE', 'SYSTEM SERIAL NUMBER', 'SYSTEM PRODUCT NAME',
    'BASE BOARD SERIAL NUMBER', 'SERIAL NUMBER', 'CHASSIS SERIAL NUMBER', 'BOARD SERIAL NUMBER', '123456789', '0123456789',
    '1234567890', 'TYPE2 - BOARD SERIAL NUMBER', 'TYPE1PRODUCTNAME'
)

function ConvertTo-NormalizedHardwareValue {
    # Trim, collapse runs of white space to one space, drop surrounding braces, and upper-case (invariant culture).
    param([AllowNull()][AllowEmptyString()][string]$Value)

    if ($null -eq $Value) { return '' }
    $text = ($Value -replace '\s+', ' ').Trim().Trim('{', '}').Trim()

    return $text.ToUpperInvariant()
}

function Test-PlaceholderHardwareValue {
    # True when a normalised value cannot identify a machine: empty, very short, one repeated character (all zeros, all F's), or a known filler.
    param([AllowEmptyString()][string]$Normalized)

    if ($Normalized.Length -lt 3) { return $true }
    $withoutSeparators = $Normalized -replace '[-\s]', ''
    if ($withoutSeparators.Length -eq 0) { return $true }
    if (($withoutSeparators.ToCharArray() | Select-Object -Unique | Measure-Object).Count -eq 1) { return $true }

    return $script:PlaceholderValues -contains $Normalized
}

function Get-ComponentHash {
    param([Parameter(Mandatory)][string]$Component, [Parameter(Mandatory)][string]$NormalizedValue)

    $bytes = [System.Text.Encoding]::UTF8.GetBytes("$($script:FingerprintPrefix):${Component}:${NormalizedValue}")
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return (($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join '')
    }
    finally {
        $sha.Dispose()
    }
}

function Get-UnusableComponents {
    # The names of the components this computer cannot supply a real value for, in the documented order. Never returns a value itself.
    param([Parameter(Mandatory)][hashtable]$Raw)

    $unusable = @()
    foreach ($component in $script:FingerprintComponents) {
        if (Test-PlaceholderHardwareValue (ConvertTo-NormalizedHardwareValue ([string]$Raw[$component]))) { $unusable += $component }
    }

    return $unusable
}

function New-HardwareDocument {
    # Builds the file's JSON from the raw values. Refuses (throws, naming the components) when ANY component is a placeholder, because a
    # certificate binds all three and a machine that cannot supply them cannot be activated: that is a commissioning problem to solve
    # at the PC, not something to paper over with an invented value.
    param(
        [Parameter(Mandatory)][hashtable]$Raw,
        [Parameter(Mandatory)][string]$GeneratedAt
    )

    $hashes = [ordered]@{}
    $bad = @()
    foreach ($component in $script:FingerprintComponents) {
        $normalized = ConvertTo-NormalizedHardwareValue ([string]$Raw[$component])
        if (Test-PlaceholderHardwareValue $normalized) {
            $bad += $component
            continue
        }
        $hashes[$component] = Get-ComponentHash -Component $component -NormalizedValue $normalized
    }

    if ($bad.Count -gt 0) {
        throw "This computer reports no usable value for: $($bad -join ', '). It cannot be bound to a license."
    }

    return ([ordered]@{ v = 1; generated_at = $GeneratedAt; fp = $hashes } | ConvertTo-Json -Compress -Depth 4)
}

function Write-FileAtomically {
    # Writes a sibling temporary file, then swaps it into place, so a reader never sees half a file.
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$Content)

    $directory = Split-Path -Parent $Path
    if ($directory -and -not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $temporary = "$Path.$([guid]::NewGuid().ToString('N')).tmp"
    [System.IO.File]::WriteAllText($temporary, $Content, (New-Object System.Text.UTF8Encoding($false)))

    if (Test-Path -LiteralPath $Path) {
        # Windows PowerShell 5.1 (.NET Framework) refuses a null backup name here, so name one and remove it.
        $backup = "$Path.$([guid]::NewGuid().ToString('N')).bak"
        [System.IO.File]::Replace($temporary, $Path, $backup)
        Remove-Item -LiteralPath $backup -Force -ErrorAction SilentlyContinue
    }
    else {
        [System.IO.File]::Move($temporary, $Path)
    }
}
