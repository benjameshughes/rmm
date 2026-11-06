# Installs USMT (scanstate, loadstate, usmtutils) into the RMM's USMT folder
# from the Windows ADK, installing the ADK's User State Migration Tool
# feature first when the PC does not have it. A PC whose copy already checks
# out is left alone. The Migrate User scripts do the same before each run.

Use-Usmt

$verdict = if ($usmtWasInstalled) { "OK: USMT installed into $usmtDir" } else { "OK: USMT is already installed in $usmtDir" }
Write-Output $verdict
Write-Output (ConvertTo-Json -InputObject @{ status = 'ok'; exit_code = 0; usmt_dir = $usmtDir; installed = $usmtWasInstalled } -Compress)
exit 0
