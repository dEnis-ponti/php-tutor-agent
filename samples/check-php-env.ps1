$env:Path = [Environment]::GetEnvironmentVariable('Path','Machine') + ';' + [Environment]::GetEnvironmentVariable('Path','User')
Write-Output "=== where php ==="
(Get-Command php -ErrorAction SilentlyContinue).Source
Write-Output "=== модули curl/mbstring ==="
php -m 2>&1 | Select-String -Pattern 'curl|mbstring'
Write-Output "=== Loaded Configuration ==="
php -i 2>&1 | Select-String 'Loaded Configuration'
Write-Output "=== PHPRC ==="
[Environment]::GetEnvironmentVariable('PHPRC','Machine')
[Environment]::GetEnvironmentVariable('PHPRC','User')
