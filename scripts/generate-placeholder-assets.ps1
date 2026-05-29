Add-Type -AssemblyName System.Drawing
$root = Join-Path $PSScriptRoot '..\src\assets' | Resolve-Path
$projectAssets = Join-Path $PSScriptRoot '..\assets' | Resolve-Path
$icons = Join-Path $root 'icons'
New-Item -ItemType Directory -Force -Path $icons | Out-Null
New-Item -ItemType Directory -Force -Path $projectAssets | Out-Null

function Save-SquarePng {
  param([string]$Path, [int]$W, [int]$H, [System.Drawing.Color]$Color)
  $bmp = New-Object System.Drawing.Bitmap $W, $H
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.Clear($Color)
  $g.Dispose()
  $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
  $bmp.Dispose()
}

function Save-CirclePng {
  param([string]$Path, [int]$Size, [System.Drawing.Color]$Fill)
  $bmp = New-Object System.Drawing.Bitmap $Size, $Size
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $g.Clear([System.Drawing.Color]::Transparent)
  $pad = [int]($Size * 0.12)
  $d = $Size - 2 * $pad
  $brush = New-Object System.Drawing.SolidBrush $Fill
  $g.FillEllipse($brush, $pad, $pad, $d, $d)
  $brush.Dispose()
  $g.Dispose()
  $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
  $bmp.Dispose()
}

function Save-SplashCorridorJpg {
  param([string]$Path, [int]$W = 1080, [int]$H = 1920)
  $bmp = New-Object System.Drawing.Bitmap $W, $H
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $rect = New-Object System.Drawing.Rectangle 0, 0, $W, $H
  $top = [System.Drawing.Color]::FromArgb(255, 232, 238, 244)
  $bottom = [System.Drawing.Color]::FromArgb(255, 198, 210, 224)
  $gradient = New-Object System.Drawing.Drawing2D.LinearGradientBrush $rect, $top, $bottom, 90
  $g.FillRectangle($gradient, 0, 0, $W, $H)
  $gradient.Dispose()
  $bandBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(18, 180, 195, 210))
  for ($x = 180; $x -lt $W; $x += 220) {
    $g.FillRectangle($bandBrush, $x, 0, 80, $H)
  }
  $bandBrush.Dispose()
  $g.Dispose()
  $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Jpeg)
  $bmp.Dispose()
}

# CustodiCore primary blue #1e3a5f, gray #9ca3af
Save-SquarePng -Path (Join-Path $root 'logo.png') -W 128 -H 128 -Color ([System.Drawing.Color]::FromArgb(255, 30, 58, 95))
Save-SquarePng -Path (Join-Path $root 'empty-state.png') -W 160 -H 160 -Color ([System.Drawing.Color]::FromArgb(255, 156, 163, 175))

Save-CirclePng -Path (Join-Path $icons 'home.png') -Size 64 -Fill ([System.Drawing.Color]::FromArgb(255, 30, 58, 95))
Save-CirclePng -Path (Join-Path $icons 'schedule.png') -Size 64 -Fill ([System.Drawing.Color]::FromArgb(255, 16, 185, 129))
Save-CirclePng -Path (Join-Path $icons 'qr.png') -Size 64 -Fill ([System.Drawing.Color]::FromArgb(255, 99, 102, 241))
Save-CirclePng -Path (Join-Path $icons 'notifications.png') -Size 64 -Fill ([System.Drawing.Color]::FromArgb(255, 245, 158, 11))
Save-CirclePng -Path (Join-Path $icons 'profile.png') -Size 64 -Fill ([System.Drawing.Color]::FromArgb(255, 75, 85, 99))

Save-SplashCorridorJpg -Path (Join-Path $projectAssets 'splash-corridor.jpg')

Write-Host "Placeholder assets written under $root and $projectAssets"
