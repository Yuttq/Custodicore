# Generates BJMP seal placeholder (if missing) and native Expo splash image.
Add-Type -AssemblyName System.Drawing

$ErrorActionPreference = 'Stop'

$root = (Join-Path $PSScriptRoot '..' | Resolve-Path).Path
$assets = Join-Path $root 'assets'
$bjmpOut = Join-Path $assets 'bjmp-logo.png'
$bjmpSource = Join-Path $assets 'bjmp-logo-source.png'
$custodiLogo = Join-Path $assets 'custodicore-logo.png'
$splashOut = Join-Path $assets 'splash.png'

# CustodiCore design tokens
$bg = [System.Drawing.Color]::FromArgb(255, 248, 250, 252)
$navy = [System.Drawing.Color]::FromArgb(255, 15, 61, 122)
$teal = [System.Drawing.Color]::FromArgb(255, 13, 165, 138)
$gold = [System.Drawing.Color]::FromArgb(255, 245, 158, 11)
$textSecondary = [System.Drawing.Color]::FromArgb(255, 107, 114, 128)
$white = [System.Drawing.Color]::White

function Save-BjmpSealPlaceholder {
  param([string]$Path, [int]$Size = 512)
  $bmp = New-Object System.Drawing.Bitmap $Size, $Size
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $g.Clear([System.Drawing.Color]::Transparent)

  $pad = [int]($Size * 0.04)
  $outer = $Size - 2 * $pad
  $ringWidth = [Math]::Max(4, [int]($Size * 0.028))
  $goldPen = New-Object System.Drawing.Pen($gold, $ringWidth)
  $navyBrush = New-Object System.Drawing.SolidBrush $navy
  $g.FillEllipse($navyBrush, $pad + 8, $pad + 8, $outer - 16, $outer - 16)
  $g.DrawEllipse($goldPen, $pad, $pad, $outer, $outer)

  $fontBjmp = New-Object System.Drawing.Font('Segoe UI', [int]($Size * 0.16), [System.Drawing.FontStyle]::Bold)
  $fontRing = New-Object System.Drawing.Font('Segoe UI', [int]($Size * 0.045), [System.Drawing.FontStyle]::Regular)
  $sf = New-Object System.Drawing.StringFormat
  $sf.Alignment = [System.Drawing.StringAlignment]::Center
  $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
  $whiteBrush = New-Object System.Drawing.SolidBrush $white
  $rect = New-Object System.Drawing.RectangleF ($Size * 0.2), ($Size * 0.32), ($Size * 0.6), ($Size * 0.28)
  $g.DrawString('BJMP', $fontBjmp, $whiteBrush, $rect, $sf)

  $ringRect = New-Object System.Drawing.RectangleF 0, 0, $Size, $Size
  $g.DrawString('BUREAU OF JAIL MANAGEMENT AND PENOLOGY', $fontRing, $whiteBrush, $ringRect, $sf)

  $goldPen.Dispose()
  $navyBrush.Dispose()
  $whiteBrush.Dispose()
  $fontBjmp.Dispose()
  $fontRing.Dispose()
  $sf.Dispose()
  $g.Dispose()
  $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
  $bmp.Dispose()
}

function Draw-CenteredImage {
  param(
    [System.Drawing.Graphics]$G,
    [System.Drawing.Image]$Img,
    [int]$CenterX,
    [int]$CenterY,
    [int]$MaxW,
    [int]$MaxH
  )
  $scale = [Math]::Min($MaxW / $Img.Width, $MaxH / $Img.Height)
  $w = [int]($Img.Width * $scale)
  $h = [int]($Img.Height * $scale)
  $x = $CenterX - [int]($w / 2)
  $y = $CenterY - [int]($h / 2)
  $G.DrawImage($Img, $x, $y, $w, $h)
}

function Save-NativeSplash {
  param(
    [string]$Path,
    [string]$BjmpPath,
    [string]$CustodiPath,
    [int]$W = 1284,
    [int]$H = 2778
  )

  $bmp = New-Object System.Drawing.Bitmap $W, $H
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $g.Clear($bg)

  $rect = New-Object System.Drawing.Rectangle 0, 0, $W, $H
  $top = [System.Drawing.Color]::FromArgb(255, 248, 250, 252)
  $bottom = [System.Drawing.Color]::FromArgb(255, 232, 240, 248)
  $gradient = New-Object System.Drawing.Drawing2D.LinearGradientBrush $rect, $top, $bottom, 90
  $g.FillRectangle($gradient, 0, 0, $W, $H)
  $gradient.Dispose()

  $accentH = [int]($H * 0.006)
  $accentBrush = New-Object System.Drawing.SolidBrush $teal
  $g.FillRectangle($accentBrush, 0, 0, $W, $accentH)
  $accentBrush.Dispose()

  $cx = [int]($W / 2)
  $bjmp = [System.Drawing.Image]::FromFile($BjmpPath)
  $custodi = [System.Drawing.Image]::FromFile($CustodiPath)
  try {
    $bjmpY = [int]($H * 0.26)
    $bjmpMax = [int]($W * 0.22)
    Draw-CenteredImage -G $g -Img $bjmp -CenterX $cx -CenterY $bjmpY -MaxW $bjmpMax -MaxH $bjmpMax

    $lineY = [int]($H * 0.34)
    $lineW = [int]($W * 0.18)
    $linePen = New-Object System.Drawing.Pen($teal, 3)
    $g.DrawLine($linePen, $cx - [int]($lineW / 2), $lineY, $cx + [int]($lineW / 2), $lineY)
    $linePen.Dispose()

    $custodiY = [int]($H * 0.46)
    $custodiMaxW = [int]($W * 0.62)
    $custodiMaxH = [int]($H * 0.22)
    Draw-CenteredImage -G $g -Img $custodi -CenterX $cx -CenterY $custodiY -MaxW $custodiMaxW -MaxH $custodiMaxH

    $subY = [int]($H * 0.58)
    $fontSub = New-Object System.Drawing.Font('Segoe UI', [int]($W * 0.028), [System.Drawing.FontStyle]::Regular)
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $sf.Trimming = [System.Drawing.StringTrimming]::None
    $brush = New-Object System.Drawing.SolidBrush $teal
    $subRect = New-Object System.Drawing.RectangleF 0, ($subY - 40), $W, 80
    $g.DrawString('Visitor Management System', $fontSub, $brush, $subRect, $sf)
    $brush.Dispose()
    $fontSub.Dispose()
    $sf.Dispose()
  } finally {
    $bjmp.Dispose()
    $custodi.Dispose()
  }

  $g.Dispose()
  $bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
  $bmp.Dispose()
}

New-Item -ItemType Directory -Force -Path $assets | Out-Null

if (-not (Test-Path $custodiLogo)) {
  throw "Missing CustodiCore logo: $custodiLogo"
}

if (Test-Path $bjmpSource) {
  $src = [System.Drawing.Image]::FromFile($bjmpSource)
  try {
    $size = 512
    $bmp = New-Object System.Drawing.Bitmap $size, $size
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.Clear([System.Drawing.Color]::Transparent)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $scale = [Math]::Min($size / $src.Width, $size / $src.Height)
    $w = [int]($src.Width * $scale)
    $h = [int]($src.Height * $scale)
    $x = [int](($size - $w) / 2)
    $y = [int](($size - $h) / 2)
    $g.DrawImage($src, $x, $y, $w, $h)
    $g.Dispose()
    $bmp.Save($bjmpOut, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
    Write-Host "Wrote bjmp-logo.png from bjmp-logo-source.png"
  } finally {
    $src.Dispose()
  }
} elseif (-not (Test-Path $bjmpOut)) {
  Save-BjmpSealPlaceholder -Path $bjmpOut
  Write-Host "Wrote placeholder bjmp-logo.png (replace with official seal or add bjmp-logo-source.png)"
} else {
  Write-Host "Using existing bjmp-logo.png"
}

Save-NativeSplash -Path $splashOut -BjmpPath $bjmpOut -CustodiPath $custodiLogo
Write-Host "Wrote splash.png under $assets"
