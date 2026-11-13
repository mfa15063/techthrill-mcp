# Builds techthrill-mcp.zip (main plugin) and techthrill-mcp-files.zip (File Tools addon).
# Run it through build-plugin.bat, or directly: powershell -ExecutionPolicy Bypass -File build-plugin.ps1
$ErrorActionPreference = 'Stop'

# Where the finished zips are saved. Empty = the "dist" folder inside this repo.
# To use another folder (for example D:\TechThrill Codes\Wordpress Plugins\TechThrill MCP Zips) create a file
# named build-plugin.config.ps1 next to this script with one line:
#     $OutputFolder = 'D:\TechThrill Codes\Wordpress Plugins\TechThrill MCP Zips'
# That file is ignored by git, so git pull never touches your own setting.
$OutputFolder = ''
$configFile = Join-Path $PSScriptRoot 'build-plugin.config.ps1'
if (Test-Path $configFile) { . $configFile }

try {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $root = $PSScriptRoot
    Set-Location $root

    Write-Host '============================================================'
    Write-Host '  TechThrill MCP - build both plugin zips'
    Write-Host '============================================================'
    Write-Host ''

    # 1. Latest code from GitHub main.
    Write-Host '[1/3] Getting the latest code from GitHub main...'
    if ((Test-Path (Join-Path $root '.git')) -and (Get-Command git -ErrorAction SilentlyContinue)) {
        & git checkout main
        & git pull origin main
        if ($LASTEXITCODE -ne 0) {
            Write-Host '  WARNING: git pull did not finish. Building from the files that are here now.' -ForegroundColor Yellow
        }
    } else {
        Write-Host '  Skipped: this folder is not a git repository, or git is not installed.'
    }
    Write-Host ''

    # 2. Build.
    Write-Host '[2/3] Building the zips...'
    if ([string]::IsNullOrWhiteSpace($OutputFolder)) { $OutputFolder = Join-Path $root 'dist' }
    $stage = Join-Path ([System.IO.Path]::GetTempPath()) ('techthrill-mcp-build-' + [guid]::NewGuid().ToString('N'))
    $mainDir = Join-Path $stage 'techthrill-mcp'
    $addDir = Join-Path $stage 'techthrill-mcp-files'
    $addSrc = Join-Path $root 'addons\techthrill-mcp-files'

    if (-not (Test-Path (Join-Path $root 'techthrill-mcp.php'))) {
        throw 'techthrill-mcp.php not found. Keep build-plugin.ps1 in the plugin folder (the one that has techthrill-mcp.php).'
    }
    if (-not (Test-Path $addSrc)) {
        throw 'The addons\techthrill-mcp-files folder is missing. Run git pull on the main branch first.'
    }

    New-Item -ItemType Directory -Force -Path $OutputFolder | Out-Null
    $OutputFolder = (Resolve-Path $OutputFolder).Path
    New-Item -ItemType Directory -Force -Path $mainDir, $addDir | Out-Null

    # Main plugin: runtime files only.
    foreach ($item in 'techthrill-mcp.php', 'uninstall.php', 'readme.txt', 'includes', 'languages', 'LICENSE') {
        $p = Join-Path $root $item
        if (Test-Path $p) { Copy-Item $p $mainDir -Recurse -Force }
    }
    # File Tools addon: runtime files only.
    foreach ($item in 'techthrill-mcp-files.php', 'uninstall.php', 'readme.txt', 'includes') {
        Copy-Item (Join-Path $addSrc $item) $addDir -Recurse -Force
    }

    # Zip with forward-slash entry names so WordPress on Linux hosts can unpack it.
    function New-PluginZip([string]$folder, [string]$outDir) {
        $parent = Split-Path $folder -Parent
        $zipPath = Join-Path $outDir ((Split-Path $folder -Leaf) + '.zip')
        if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
        $zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
        try {
            Get-ChildItem -Path $folder -Recurse -File | ForEach-Object {
                $name = $_.FullName.Substring($parent.Length + 1).Replace('\', '/')
                [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $name, [System.IO.Compression.CompressionLevel]::Optimal)
            }
        } finally {
            $zip.Dispose()
        }
        return $zipPath
    }

    foreach ($pair in @(@($mainDir, 'techthrill-mcp.php'), @($addDir, 'techthrill-mcp-files.php'))) {
        $zipPath = New-PluginZip $pair[0] $OutputFolder
        $header = Get-Content (Join-Path $pair[0] $pair[1]) -TotalCount 30 | Out-String
        $version = if ($header -match 'Version:\s*([0-9][0-9A-Za-z.\-]*)') { $Matches[1] } else { '?' }
        $kb = [math]::Round((Get-Item $zipPath).Length / 1KB)
        Write-Host ('  Built ' + (Split-Path $zipPath -Leaf) + '   version ' + $version + '   (' + $kb + ' KB)')
    }

    Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue

    # 3. Done.
    Write-Host ''
    Write-Host '[3/3] Done. The zips are in:'
    Write-Host ('  ' + $OutputFolder)
    Write-Host ''
    Write-Host '  techthrill-mcp.zip        = main plugin, install this first'
    Write-Host '  techthrill-mcp-files.zip  = File Tools addon, install it only on sites that need it'
    Invoke-Item $OutputFolder
    exit 0
} catch {
    Write-Host ''
    Write-Host ('BUILD FAILED: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
