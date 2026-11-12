# Installs home-helper-agent as a Windows service (agent/docs/service.md).
# From an elevated PowerShell (Run as administrator):
#
#   .\agent\packaging\windows\install.ps1 -Server https://home.example.org -Code K7QM-2XPA
#
# Run it again after a new build to replace the binary.
param(
    [string]$Server,
    [string]$Code,
    [string]$Binary = (Join-Path $PSScriptRoot '..\..\build\home-helper-agent.exe')
)
$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated PowerShell (Run as administrator).'
}
if (-not (Test-Path $Binary)) { throw "No binary at $Binary; build it first (agent\scripts\build.bat Release)." }

$dir = Join-Path $env:ProgramFiles 'HomeHelperAgent'
$exe = Join-Path $dir 'home-helper-agent.exe'
New-Item -ItemType Directory -Force $dir | Out-Null

# A running service holds the exe open: stop it before replacing it
$service = Get-Service -Name HomeHelperAgent -ErrorAction SilentlyContinue
if ($service -and $service.Status -eq 'Running') { & $exe service stop }
Copy-Item $Binary $exe -Force

$config = Join-Path $env:ProgramData 'HomeHelperAgent\agent.json'
if (-not (Test-Path $config)) {
    if (-not $Server) { throw 'First install: pass -Server https://your-home-helper' }
    & $exe init --server $Server
    Write-Host "Review the commands in $config before going further: they are the only things Home Helper can run here."
}
if ($Code) { & $exe enroll --code $Code }

& $exe service install
& $exe status
