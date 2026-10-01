# Runs the agent's own updater via the service's registered path rather than
# PATH: services only see PATH changes after a reboot, and a PATH lookup as
# SYSTEM could be hijacked. The agent restarts itself, so this command often
# ends as Timed Out; the "Update available" badge disappearing is the real done.

$exe = (Get-CimInstance Win32_Service -Filter "Name='BenJHRMM'").PathName.Trim('"')
& $exe update
exit $LASTEXITCODE
