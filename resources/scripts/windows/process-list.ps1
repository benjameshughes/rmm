Get-Process | Sort-Object CPU -Descending | Select-Object -First 20 Name, CPU, WorkingSet, Id
