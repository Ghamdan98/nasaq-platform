param([int]$Port = 8000)
$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$uploadDirectory = Join-Path $projectRoot 'storage\framework\upload-tmp'
New-Item -ItemType Directory -Path $uploadDirectory -Force | Out-Null
Set-Location -LiteralPath (Join-Path $projectRoot 'public')
$phpExecutable = 'C:\xampp\php\php.exe'
$routerPath = Join-Path $projectRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
# Keep PHP startup diagnostics out of JSON and use project-local writable upload storage.
& $phpExecutable -d "upload_tmp_dir=$uploadDirectory" -d 'display_errors=0' -d 'log_errors=1' -d "error_log=$projectRoot\storage\logs\php-server.log" -S "127.0.0.1:$Port" -t (Join-Path $projectRoot 'public') $routerPath
exit $LASTEXITCODE
