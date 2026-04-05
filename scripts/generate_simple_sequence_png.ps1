$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) { New-Item -ItemType Directory -Path $outputDir | Out-Null }
$outputPath = Join-Path $outputDir 'smns-sequence-diagram-simple.png'

$bmp = New-Object System.Drawing.Bitmap 2200, 1300
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$g.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 28, [System.Drawing.FontStyle]::Bold)
$headFont = New-Object System.Drawing.Font('Segoe UI', 14, [System.Drawing.FontStyle]::Bold)
$bodyFont = New-Object System.Drawing.Font('Segoe UI', 11)
$brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30,41,59))
$linePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(148,163,184),2)
$msgPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(71,85,105),2)
$msgPen.CustomEndCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(5,5,$true)

function Head([System.Drawing.Graphics]$G,[int]$X,[string]$Text,[System.Drawing.Color]$Fill) {
  $fb = New-Object System.Drawing.SolidBrush($Fill)
  $G.FillRectangle($fb,$X-90,110,180,52)
  $G.DrawRectangle($msgPen,$X-90,110,180,52)
  $G.DrawString($Text,$headFont,$brush,$X-75,126)
  $G.DrawLine($linePen,$X,162,$X,1180)
  $fb.Dispose()
}

function Msg([System.Drawing.Graphics]$G,[int]$X1,[int]$X2,[int]$Y,[string]$Text) {
  $G.DrawLine($msgPen,$X1,$Y,$X2,$Y)
  $G.DrawString($Text,$bodyFont,$brush,[int](($X1+$X2)/2)-90,$Y-22)
}

$g.DrawString('SMNS Simple Sequence Diagram',$titleFont,$brush,50,30)
Head $g 220 'Student' ([System.Drawing.Color]::FromArgb(236,253,245))
Head $g 760 'Admin' ([System.Drawing.Color]::FromArgb(254,242,242))
Head $g 1300 'SMNS' ([System.Drawing.Color]::FromArgb(239,246,255))
Head $g 1820 'Database' ([System.Drawing.Color]::FromArgb(250,245,255))

Msg $g 220 1300 240 'Request transcript'
Msg $g 1300 1820 340 'Check results, balance, and status'
Msg $g 1820 1300 440 'Return eligibility data'
Msg $g 760 1300 560 'Grant transcript rights'
Msg $g 1300 1820 660 'Save transcript release'
Msg $g 220 1300 780 'Open transcript'
Msg $g 1300 1820 900 'Read transcript data'
Msg $g 1820 1300 1020 'Return transcript details'
Msg $g 1300 220 1140 'Show transcript with verification'

$altBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255,251,235))
$g.FillRectangle($altBrush,90,500,2020,700)
$g.DrawRectangle($linePen,90,500,2020,700)
$g.DrawString('Alt: eligible and released flow',$headFont,$brush,110,515)
$altBrush.Dispose()

$bmp.Save($outputPath,[System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose(); $bmp.Dispose(); $titleFont.Dispose(); $headFont.Dispose(); $bodyFont.Dispose(); $brush.Dispose(); $linePen.Dispose(); $msgPen.Dispose()
Write-Output "PNG generated: $outputPath"
