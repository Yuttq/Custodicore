# Builds assets/splash-icon.png for the native (Android 12+ / iOS) splash screen.
# Takes only the shield + padlock from custodicore-logo.png, removes the white
# background, and centres it on a transparent 1024x1024 canvas so it sits inside
# the Android 12+ safe circle (2/3 of the canvas) and never gets cropped.
# The full wordmark is shown afterwards by src/screens/SplashScreen.js.
#
#   npm run generate-splash-icon   builds both files used by the app:
#     assets/splash-icon.png   native splash (-Backdrop, safe-circle padding)
#     assets/shield-badge.png  Login logo (-Backdrop -CircleFraction 1, no padding)
param(
  [string]$Src = (Join-Path $PSScriptRoot '../assets/custodicore-logo.png'),
  [string]$Out = (Join-Path $PSScriptRoot '../assets/splash-icon.png'),
  [int]$Canvas = 1024,
  [double]$CircleFraction = 0.6667,
  # Draws a white disc behind the shield. Needed on the navy splash/login:
  # the padlock's blue is ~1.03:1 against navy and would vanish without it.
  [switch]$Backdrop
)
Add-Type -AssemblyName System.Drawing
$ErrorActionPreference = 'Stop'

# Crop: shield + padlock only (wordmark begins at y~660)
$cx0 = 196; $cy0 = 108; $cw = 420; $ch = 548
$srcBmp = New-Object System.Drawing.Bitmap $Src
$crop = New-Object System.Drawing.Bitmap $cw, $ch, ([System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
for ($y = 0; $y -lt $ch; $y++) {
  for ($x = 0; $x -lt $cw; $x++) {
    $p = $srcBmp.GetPixel($x + $cx0, $y + $cy0)
    # Un-composite from white: alpha = how far the darkest channel is from white
    $minc = [Math]::Min($p.R, [Math]::Min($p.G, $p.B))
    $a = [Math]::Min(255, [int]((255 - $minc) * 255 / 235))
    if ($a -le 6) { $crop.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(0, 0, 0, 0)); continue }
    $af = $a / 255.0
    $r = [Math]::Max(0, [Math]::Min(255, [int](255 - (255 - $p.R) / $af)))
    $g = [Math]::Max(0, [Math]::Min(255, [int](255 - (255 - $p.G) / $af)))
    $b = [Math]::Max(0, [Math]::Min(255, [int](255 - (255 - $p.B) / $af)))
    $crop.SetPixel($x, $y, [System.Drawing.Color]::FromArgb($a, $r, $g, $b))
  }
}
$srcBmp.Dispose()

# Find the farthest opaque pixel from the crop centre, then scale so it lands
# inside the Android 12+ safe circle (2/3 of the canvas), with ~8% margin.
$ccx = $cw / 2; $ccy = $ch / 2; $maxR = 0
for ($y = 0; $y -lt $ch; $y += 2) { for ($x = 0; $x -lt $cw; $x += 2) {
  if ($crop.GetPixel($x, $y).A -gt 20) { $d = [Math]::Sqrt(($x - $ccx) * ($x - $ccx) + ($y - $ccy) * ($y - $ccy)); if ($d -gt $maxR) { $maxR = $d } } } }
$safeR = $Canvas * $CircleFraction / 2 * 0.92
# With a backdrop the disc fills the safe circle and the shield sits inside it.
$shieldR = if ($Backdrop) { $safeR * 0.8 } else { $safeR }
$scale = $shieldR / $maxR

$outBmp = New-Object System.Drawing.Bitmap $Canvas, $Canvas, ([System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$gr = [System.Drawing.Graphics]::FromImage($outBmp)
$gr.Clear([System.Drawing.Color]::Transparent)
$gr.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$gr.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$gr.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
$w = [int]($cw * $scale); $h = [int]($ch * $scale)
$dx = [int](($Canvas - $w) / 2); $dy = [int](($Canvas - $h) / 2)
if ($Backdrop) {
  # The disc is white anyway, so draw the ORIGINAL pixels (no background
  # removal, which slightly lightens the greens) onto a white layer, then fill
  # an anti-aliased circle with that layer.
  $layer = New-Object System.Drawing.Bitmap $Canvas, $Canvas, ([System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
  $lg = [System.Drawing.Graphics]::FromImage($layer)
  $lg.Clear([System.Drawing.Color]::White)
  $lg.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $lg.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
  $orig = New-Object System.Drawing.Bitmap $Src
  $destRect = New-Object System.Drawing.Rectangle $dx, $dy, $w, $h
  $lg.DrawImage($orig, $destRect, $cx0, $cy0, $cw, $ch, [System.Drawing.GraphicsUnit]::Pixel)
  $orig.Dispose(); $lg.Dispose()
  $tex = New-Object System.Drawing.TextureBrush $layer
  $gr.FillEllipse($tex, [float]($Canvas / 2 - $safeR), [float]($Canvas / 2 - $safeR), [float]($safeR * 2), [float]($safeR * 2))
  $tex.Dispose(); $layer.Dispose()
} else {
  $gr.DrawImage($crop, $dx, $dy, $w, $h)
}
$gr.Dispose(); $crop.Dispose()
$outBmp.Save($Out, [System.Drawing.Imaging.ImageFormat]::Png)
$outBmp.Dispose()
"maxR=$([int]$maxR)px scale=$([Math]::Round($scale,3)) drawn=${w}x${h} on ${Canvas}x${Canvas}"
