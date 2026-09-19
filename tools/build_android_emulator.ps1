param(
    [ValidateSet('debug', 'profile', 'release')]
    [string]$Mode = 'debug',

    [string]$ApiBaseUrl = 'http://10.0.2.2:8000',

    [string]$AppInstanceKey = $env:APP_INSTANCE_KEY
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($AppInstanceKey)) {
    throw 'APP_INSTANCE_KEY must be provided through the environment or -AppInstanceKey.'
}

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

$flutter = Get-Command flutter -ErrorAction Stop

$definesPath = Join-Path ([System.IO.Path]::GetTempPath()) (
    'storeapp-dart-defines-' + [Guid]::NewGuid().ToString('N') + '.json'
)

$defines = @{
    APP_ENV = 'development'
    API_BASE_URL = $ApiBaseUrl
    APP_INSTANCE_KEY = $AppInstanceKey.Trim()
} | ConvertTo-Json -Compress

try {
    [System.IO.File]::WriteAllText(
        $definesPath,
        $defines,
        [System.Text.UTF8Encoding]::new($false)
    )

    & $flutter.Source build apk "--$Mode" "--dart-define-from-file=$definesPath"

    if ($LASTEXITCODE -ne 0) {
        throw "Flutter APK build failed with exit code $LASTEXITCODE."
    }
}
finally {
    if (Test-Path $definesPath) {
        Remove-Item -Force $definesPath
    }
}
