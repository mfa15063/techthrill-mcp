#!/usr/bin/env bash
# Builds dist/techthrill-mcp.zip containing only the runtime files.
set -euo pipefail

cd "$(dirname "$0")"

rm -rf dist
mkdir -p dist/techthrill-mcp

cp -R techthrill-mcp.php uninstall.php readme.txt includes languages dist/techthrill-mcp/
if [ -f LICENSE ]; then
	cp LICENSE dist/techthrill-mcp/
fi

cd dist
if command -v zip >/dev/null 2>&1; then
	zip -r techthrill-mcp.zip techthrill-mcp
else
	# No zip binary (typical on Windows): fall back to PowerShell. Compress-Archive on
	# Windows PowerShell 5.1 writes backslash entry names that break on Linux hosts,
	# so use it only if the .NET writer below is unavailable.
	powershell -NoProfile -Command "
		\$ErrorActionPreference = 'Stop'
		try {
			Add-Type -AssemblyName System.IO.Compression
			Add-Type -AssemblyName System.IO.Compression.FileSystem
			\$root = (Resolve-Path 'techthrill-mcp').Path
			\$parent = Split-Path \$root -Parent
			\$zip = [System.IO.Compression.ZipFile]::Open((Join-Path \$parent 'techthrill-mcp.zip'), 'Create')
			Get-ChildItem -Path \$root -Recurse -File | ForEach-Object {
				\$entry = \$_.FullName.Substring(\$parent.Length + 1).Replace('\\', '/')
				[void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(\$zip, \$_.FullName, \$entry)
			}
			\$zip.Dispose()
		} catch {
			Compress-Archive -Path 'techthrill-mcp' -DestinationPath 'techthrill-mcp.zip' -Force
		}
	"
fi

echo "Built dist/techthrill-mcp.zip"
