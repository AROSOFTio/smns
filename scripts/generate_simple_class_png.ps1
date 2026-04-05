$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) { New-Item -ItemType Directory -Path $outputDir | Out-Null }
$outputPath = Join-Path $outputDir 'smns-class-diagram-simple.png'

$bmp = New-Object System.Drawing.Bitmap 2200, 1300
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$g.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 28, [System.Drawing.FontStyle]::Bold)
$headFont = New-Object System.Drawing.Font('Segoe UI', 15, [System.Drawing.FontStyle]::Bold)
$bodyFont = New-Object System.Drawing.Font('Segoe UI', 11)
$brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30,41,59))
$pen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(71,85,105), 2)
$arrowPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(71,85,105), 2)
$arrowPen.CustomEndCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(5,5,$true)

function Box([System.Drawing.Graphics]$G,[int]$X,[int]$Y,[int]$W,[int]$H,[string]$Title,[string[]]$Lines,[System.Drawing.Color]$Fill) {
  $fillB = New-Object System.Drawing.SolidBrush($Fill)
  $G.FillRectangle($fillB,$X,$Y,$W,$H)
  $G.DrawRectangle($pen,$X,$Y,$W,$H)
  $G.DrawLine($pen,$X,$Y+36,$X+$W,$Y+36)
  $G.DrawString($Title,$headFont,$brush,$X+10,$Y+8)
  $yy = $Y+46
  foreach($line in $Lines){ $G.DrawString($line,$bodyFont,$brush,$X+10,$yy); $yy += 22 }
  $fillB.Dispose()
}

function Link([System.Drawing.Graphics]$G,[int]$X1,[int]$Y1,[int]$X2,[int]$Y2,[string]$Label='') {
  $G.DrawLine($arrowPen,$X1,$Y1,$X2,$Y2)
  if($Label -ne '') { $G.DrawString($Label,$bodyFont,$brush,[int](($X1+$X2)/2)+8,[int](($Y1+$Y2)/2)-12) }
}

$g.DrawString('SMNS Simple Class Diagram',$titleFont,$brush,50,30)

Box $g 80 140 260 150 'User' @('id','username','email','role') ([System.Drawing.Color]::FromArgb(239,246,255))
Box $g 420 120 280 160 'Student' @('student_id','first_name','last_name','academic_status') ([System.Drawing.Color]::FromArgb(236,253,245))
Box $g 420 340 280 140 'Lecturer' @('first_name','last_name','department') ([System.Drawing.Color]::FromArgb(254,249,195))
Box $g 840 120 260 140 'Program' @('program_code','program_name','duration_years') ([System.Drawing.Color]::FromArgb(243,232,255))
Box $g 840 330 260 140 'Course' @('course_code','course_name','semester_offered') ([System.Drawing.Color]::FromArgb(254,242,242))
Box $g 1260 120 280 160 'Result' @('total_marks','grade','grade_points','status') ([System.Drawing.Color]::FromArgb(239,246,255))
Box $g 1260 360 280 140 'Payment' @('amount_paid','payment_method','payment_status') ([System.Drawing.Color]::FromArgb(250,245,255))
Box $g 1660 220 340 150 'TranscriptIssuance' @('verification_code','verification_token','status') ([System.Drawing.Color]::FromArgb(236,253,245))

Link $g 340 200 420 170 'inherits'
Link $g 340 220 420 390 'inherits'
Link $g 700 180 840 180 'enrolls'
Link $g 700 200 1260 170 'receives'
Link $g 1100 190 1260 190 'belongs to'
Link $g 700 400 1260 180 'enters'
Link $g 700 220 1260 420 'pays'
Link $g 700 230 1660 280 'issued'
Link $g 1100 380 1260 420 'billed by'

$bmp.Save($outputPath,[System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose(); $bmp.Dispose(); $titleFont.Dispose(); $headFont.Dispose(); $bodyFont.Dispose(); $brush.Dispose(); $pen.Dispose(); $arrowPen.Dispose()
Write-Output "PNG generated: $outputPath"
