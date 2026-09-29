# Generates Expo / Android launcher icons from the official CustodiCore logo.
Add-Type -AssemblyName System.Drawing

$ErrorActionPreference = 'Stop'

$root = (Join-Path $PSScriptRoot '..' | Resolve-Path).Path
$assets = Join-Path $root 'assets'
$sourceLogo = Join-Path $assets 'custodicore-logo.png'

if (-not (Test-Path $sourceLogo)) {
  throw "Missing source logo: $sourceLogo"
}

function Save-LauncherIcon {
  param(
    [string]$Path,
    [int]$Size,
    [System.Drawing.Color]$Background,
    [double]$PaddingRatio,
    [bool]$TransparentBackground
  )

  $src = [System.Drawing.Image]::FromFile($sourceLogo)
  try {
    $bmp = New-Object System.Drawing.Bitmap $Size, $Size
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality

    if ($TransparentBackground) {
      $g.Clear([System.Drawing.Color]::Transparent)
    } else {
      $g.Clear($Background)
    }

    $pad = [int]($Size * $PaddingRatio)
    $avail = $Size - (2 * $pad)
    $scale = [Math]::Min($avail / $src.Width, $avail / $src.Height)
    $w = [int]($src.Width * $scale)
    $h = [int]($src.Height * $scale)
    $x = [int](($Size - $w) / 2)
    $y = [int](($Size - $h) / 2)
    $g.DrawImage($src, $x, $y, $w, $h)
    $g.Dispose()
    $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
  } finally {
    $src.Dispose()
  }
}

$white = [System.Drawing.Color]::FromArgb(255, 255, 255, 255)

# Full launcher icon (iOS, Android legacy, web)
Save-LauncherIcon -Path (Join-Path $assets 'icon.png') -Size 1024 -Background $white -PaddingRatio 0.12 -TransparentBackground $false

# Adaptive foreground: logo in Android safe zone (~66% center)
Save-LauncherIcon -Path (Join-Path $assets 'adaptive-icon.png') -Size 1024 -Background $white -PaddingRatio 0.17 -TransparentBackground $true

Write-Host "Wrote icon.png and adaptive-icon.png under $assets"
