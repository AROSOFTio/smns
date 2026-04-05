$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) { New-Item -ItemType Directory -Path $outputDir | Out-Null }
$outputPath = Join-Path $outputDir 'smns-use-case-diagram-simple.png'

$bmp = New-Object System.Drawing.Bitmap 2000, 1100
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$g.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 28, [System.Drawing.FontStyle]::Bold)
$labelFont = New-Object System.Drawing.Font('Segoe UI', 14)
$actorFont = New-Object System.Drawing.Font('Segoe UI', 16, [System.Drawing.FontStyle]::Bold)
$brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30,41,59))
$pen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(71,85,105), 3)
$pen.CustomEndCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(6,6,$true)

function DrawActor([System.Drawing.Graphics]$G,[int]$X,[int]$Y,[string]$Text) {
  $p = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(55,65,81),3)
  $G.DrawEllipse($p,$X+32,$Y,36,36)
  $G.DrawLine($p,$X+50,$Y+36,$X+50,$Y+92)
  $G.DrawLine($p,$X+20,$Y+55,$X+80,$Y+55)
  $G.DrawLine($p,$X+50,$Y+92,$X+20,$Y+132)
  $G.DrawLine($p,$X+50,$Y+92,$X+80,$Y+132)
  $G.DrawString($Text,$actorFont,$brush,$X,$Y+145)
  $p.Dispose()
}

function DrawUseCase([System.Drawing.Graphics]$G,[int]$X,[int]$Y,[int]$W,[int]$H,[string]$Text) {
  $fill = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(239,246,255))
  $stroke = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(96,165,250),2)
  $G.FillEllipse($fill,$X,$Y,$W,$H)
  $G.DrawEllipse($stroke,$X,$Y,$W,$H)
  $sf = New-Object System.Drawing.StringFormat
  $sf.Alignment='Center'; $sf.LineAlignment='Center'
  $G.DrawString($Text,$labelFont,$brush,(New-Object System.Drawing.RectangleF([single]$X,[single]$Y,[single]$W,[single]$H)),$sf)
  $fill.Dispose(); $stroke.Dispose(); $sf.Dispose()
}

function Connect([System.Drawing.Graphics]$G,[int]$X1,[int]$Y1,[int]$X2,[int]$Y2) { $G.DrawLine($pen,$X1,$Y1,$X2,$Y2) }

$g.DrawString('SMNS Simple Use Case Diagram',$titleFont,$brush,50,30)
$boundaryPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(148,163,184),3)
$g.DrawRectangle($boundaryPen,420,120,1120,820)
$g.DrawString('SMNS System',$actorFont,$brush,445,135)

DrawActor $g 80 210 'Student'
DrawActor $g 80 440 'Lecturer'
DrawActor $g 80 670 'Admin'
DrawActor $g 1680 430 'Finance'

DrawUseCase $g 620 220 240 90 'Login'
DrawUseCase $g 930 220 320 90 'Register Courses'
DrawUseCase $g 620 400 320 90 'View or Manage Results'
DrawUseCase $g 1020 400 260 90 'Manage Payments'
DrawUseCase $g 700 610 360 90 'View or Release Transcript'
DrawUseCase $g 1120 610 260 90 'Generate Reports'

Connect $g 180 280 620 265
Connect $g 180 280 930 265
Connect $g 180 280 620 445
Connect $g 180 280 700 655

Connect $g 180 510 620 445
Connect $g 180 510 620 265

Connect $g 180 740 620 265
Connect $g 180 740 930 265
Connect $g 180 740 620 445
Connect $g 180 740 700 655
Connect $g 180 740 1120 655

Connect $g 1680 500 1020 445
Connect $g 1680 500 1120 655
Connect $g 1680 500 620 265

$bmp.Save($outputPath,[System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose(); $bmp.Dispose(); $titleFont.Dispose(); $labelFont.Dispose(); $actorFont.Dispose(); $brush.Dispose(); $pen.Dispose(); $boundaryPen.Dispose()
Write-Output "PNG generated: $outputPath"
