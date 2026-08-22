# Builds a production zip of WP MCP Suite.
# Usage: .\build.ps1 [-Version 0.4.0]
param(
	[string]$Version = ""
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
if ("" -eq $Version) {
	$content = Get-Content (Join-Path $root "wpmcp.php") -Raw
	if ($content -match "(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)") {
		$Version = $Matches[1]
	} else {
		Write-Error "Could not parse version from wpmcp.php"
	}
}

$dist = Join-Path $root "dist"
New-Item -ItemType Directory -Path $dist -Force | Out-Null
$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("wpmcp-build-" + [guid]::NewGuid().ToString("N"))
$dest = Join-Path $dist ("wpmcp-" + $Version + ".zip")

if (Test-Path $dest) { Remove-Item $dest -Force }
New-Item -ItemType Directory -Path $stage\wpmcp | Out-Null

$excludeDirs = @(".wp-core", ".wp-env", ".git", "node_modules", "dist", "tests", "docs", ".opencode")
Copy-Item -Path (Join-Path $root "*") -Destination $stage\wpmcp -Recurse -Force `
	| Out-Null
foreach ($dir in $excludeDirs) {
	$p = Join-Path $stage\wpmcp $dir
	if (Test-Path $p) { Remove-Item $p -Recurse -Force }
}
Remove-Item (Join-Path $stage\wpmcp ".wp-env.json") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $stage\wpmcp "README.md") -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $stage\wpmcp "build.ps1") -Force -ErrorAction SilentlyContinue
Get-ChildItem $stage\wpmcp -Recurse -File | Where-Object { $_.Name -match "^debug-|^mint-code" } | Remove-Item -Force

if (Test-Path $dest) { Remove-Item $dest -Force }
tar -caf $dest -C $stage wpmcp
if ($LASTEXITCODE -ne 0) { Write-Error "tar failed to create the zip" }
Remove-Item $stage -Recurse -Force

$size = "{0:N0}" -f (Get-Item $dest).Length
Write-Output "Built $dest ($size bytes)"
