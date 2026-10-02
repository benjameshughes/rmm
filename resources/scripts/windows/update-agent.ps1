# Runs the agent's own updater via the service's registered path rather than
# PATH: services only see PATH changes after a reboot, and a PATH lookup as
# SYSTEM could be hijacked. The update stops the agent running this script, so
# no result is posted from here: the server completes the command when the
# device reports its new version.

$exe = (Get-CimInstance Win32_Service -Filter "Name='BenJHRMM'").PathName.Trim('"')
& $exe update
exit $LASTEXITCODE
