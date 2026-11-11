#!/usr/bin/env bash
# Builds dist/techthrill-mcp.zip and dist/techthrill-mcp-files.zip containing only the runtime files.
set -euo pipefail

cd "$(dirname "$0")"

rm -rf dist
mkdir -p dist/techthrill-mcp dist/techthrill-mcp-files

cp -R techthrill-mcp.php uninstall.php readme.txt includes languages dist/techthrill-mcp/
if [ -f LICENSE ]; then
	cp LICENSE dist/techthrill-mcp/
fi

# The File Tools addon.
cp -R addons/techthrill-mcp-files/techthrill-mcp-files.php addons/techthrill-mcp-files/uninstall.php addons/techthrill-mcp-files/readme.txt addons/techthrill-mcp-files/includes dist/techthrill-mcp-files/

cd dist

# Zips a folder next to itself, with forward-slash entry names.
make_zip() {
	local folder="$1"
	if command -v zip >/dev/null 2>&1; then
		zip -r "$folder.zip" "$folder"
	else
		# No zip binary (typical on Windows): fall back to PowerShell. Compress-Archive on
		# Windows PowerShell 5.1 writes backslash entry names that break on Linux hosts,
		# so use it only if the .NET writer below is unavailable.
		powershell -NoProfile -Command "
			\$ErrorActionPreference = 'Stop'
			try {
				Add-Type -AssemblyName System.IO.Compression
				Add-Type -AssemblyName System.IO.Compression.FileSystem
				\$root = (Resolve-Path '$folder').Path
				\$parent = Split-Path \$root -Parent
				\$zip = [System.IO.Compression.ZipFile]::Open((Join-Path \$parent '$folder.zip'), 'Create')
				Get-ChildItem -Path \$root -Recurse -File | ForEach-Object {
					\$entry = \$_.FullName.Substring(\$parent.Length + 1).Replace('\\\\', '/')
					[void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(\$zip, \$_.FullName, \$entry)
				}
				\$zip.Dispose()
			} catch {
				Compress-Archive -Path '$folder' -DestinationPath '$folder.zip' -Force
			}
		"
	fi
}

make_zip techthrill-mcp
make_zip techthrill-mcp-files

echo "Built dist/techthrill-mcp.zip and dist/techthrill-mcp-files.zip"
