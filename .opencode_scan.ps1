param(
  [Parameter(Mandatory=$true)][string]$File,
  [string[]]$Patterns,
  [Parameter(Mandatory=$true)][ValidateSet('lines','grep','all')]$Mode,
  [int]$Start = 1,
  [int]$End = 2147483647
)
$c = Get-Content -LiteralPath $File -Encoding UTF8
$out = @()
$lo = $Start; $hi = [Math]::Min($End, $c.Count)
for ($i = $lo; $i -le $hi; $i++) {
  $l = $c[$i - 1]
  if ($Mode -eq 'all') {
    $t = $l
    if ($t.Length -gt 120) { $t = $t.Substring(0, 120) + " ..." }
    $t = $t -replace '^\s+', ''
    $out += ("{0}: {1}" -f $i, $t)
    continue
  }
  foreach ($p in $Patterns) {
    $found = $false
    if ($Mode -eq 'grep') {
      $found = $l.IndexOf($p, [System.StringComparison]::OrdinalIgnoreCase) -ge 0
    } else { # lines: simple substring
      $found = $l.IndexOf($p, [System.StringComparison]::Ordinal) -ge 0
    }
    if ($found) {
      $t = $l -replace '^\s+', ''
      if ($t.Length -gt 110) { $t = $t.Substring(0, 110) + " ..." }
      $out += ("{0}: [{1}] {2}" -f $i, $p, $t)
      break
    }
  }
}
$out
