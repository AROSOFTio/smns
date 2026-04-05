$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) { New-Item -ItemType Directory -Path $outputDir | Out-Null }
$outputPath = Join-Path $outputDir 'smns-erd-simple.png'

$bmp = New-Object System.Drawing.Bitmap 2400, 1500
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$g.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 30, [System.Drawing.FontStyle]::Bold)
$headFont = New-Object System.Drawing.Font('Segoe UI', 16, [System.Drawing.FontStyle]::Bold)
$bodyFont = New-Object System.Drawing.Font('Segoe UI', 11)
$brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30,41,59))
$linePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(71,85,105), 2)
$arrowPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(71,85,105), 2)
$arrowPen.CustomEndCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(5,5,$true)

function DrawEntity {
  param(
    [System.Drawing.Graphics]$G,[int]$X,[int]$Y,[int]$W,[int]$H,
    [string]$Title,[string[]]$Fields,[System.Drawing.Color]$Fill,[System.Drawing.Color]$Stroke
  )
  $fillB = New-Object System.Drawing.SolidBrush($Fill)
  $strokeP = New-Object System.Drawing.Pen($Stroke,2)
  $G.FillRectangle($fillB,$X,$Y,$W,$H)
  $G.DrawRectangle($strokeP,$X,$Y,$W,$H)
  $G.DrawLine($strokeP,$X,$Y+38,$X+$W,$Y+38)
  $G.DrawString($Title,$headFont,$brush,$X+12,$Y+9)
  $yy = $Y + 50
  foreach($field in $Fields){
    $G.DrawString($field,$bodyFont,$brush,$X+12,$yy)
    $yy += 22
  }
  $fillB.Dispose()
  $strokeP.Dispose()
}

function ConnectEntity {
  param(
    [System.Drawing.Graphics]$G,[int]$X1,[int]$Y1,[int]$X2,[int]$Y2,[string]$Label
  )
  $G.DrawLine($arrowPen,$X1,$Y1,$X2,$Y2)
  if ($Label -ne '') {
    $mx = [int](($X1 + $X2) / 2)
    $my = [int](($Y1 + $Y2) / 2)
    $G.DrawString($Label,$bodyFont,$brush,$mx + 8,$my - 12)
  }
}

$g.DrawString('SMNS ERD (Simple Database Diagram)',$titleFont,$brush,50,28)

DrawEntity $g 70 150 280 170 'USERS' @('PK id','username','email','role','status') ([System.Drawing.Color]::FromArgb(239,246,255)) ([System.Drawing.Color]::FromArgb(96,165,250))
DrawEntity $g 450 90 320 210 'STUDENTS' @('PK id','FK user_id','student_id','admission_number','FK program_id','academic_status','status') ([System.Drawing.Color]::FromArgb(236,253,245)) ([System.Drawing.Color]::FromArgb(34,197,94))
DrawEntity $g 450 380 320 170 'LECTURERS' @('PK id','FK user_id','first_name','last_name','department') ([System.Drawing.Color]::FromArgb(254,249,195)) ([System.Drawing.Color]::FromArgb(202,138,4))
DrawEntity $g 900 90 300 170 'PROGRAMS' @('PK id','program_code','program_name','duration_years','status') ([System.Drawing.Color]::FromArgb(243,232,255)) ([System.Drawing.Color]::FromArgb(147,51,234))
DrawEntity $g 900 360 300 190 'COURSES' @('PK id','course_code','course_name','FK program_id','level_year','semester_offered') ([System.Drawing.Color]::FromArgb(254,242,242)) ([System.Drawing.Color]::FromArgb(239,68,68))
DrawEntity $g 1320 90 320 170 'SEMESTERS' @('PK id','FK academic_year_id','semester_name','semester_number','status') ([System.Drawing.Color]::FromArgb(240,249,255)) ([System.Drawing.Color]::FromArgb(14,165,233))
DrawEntity $g 1320 360 360 210 'COURSE_REGISTRATIONS' @('PK id','FK student_id','FK course_id','FK semester_id','status') ([System.Drawing.Color]::FromArgb(250,245,255)) ([System.Drawing.Color]::FromArgb(168,85,247))
DrawEntity $g 1810 90 320 210 'RESULTS' @('PK id','FK student_id','FK course_id','FK semester_id','FK entered_by','total_marks','grade','status') ([System.Drawing.Color]::FromArgb(239,246,255)) ([System.Drawing.Color]::FromArgb(59,130,246))
DrawEntity $g 1810 390 320 170 'PAYMENTS' @('PK id','FK student_id','FK semester_id','amount_paid','payment_method','payment_status') ([System.Drawing.Color]::FromArgb(253,242,248)) ([System.Drawing.Color]::FromArgb(236,72,153))
DrawEntity $g 930 760 380 170 'TRANSCRIPT_ISSUANCES' @('PK id','FK student_id','transcript_hash','verification_code','verification_token','status') ([System.Drawing.Color]::FromArgb(236,253,245)) ([System.Drawing.Color]::FromArgb(22,163,74))

ConnectEntity $g 350 200 450 160 '1:1'
ConnectEntity $g 350 240 450 450 '1:1'
ConnectEntity $g 770 170 900 170 '1:M'
ConnectEntity $g 770 200 1810 160 '1:M'
ConnectEntity $g 770 230 1810 450 '1:M'
ConnectEntity $g 1200 180 1320 180 '1:M'
ConnectEntity $g 1200 450 1320 450 '1:M'
ConnectEntity $g 1640 190 1810 190 '1:M'
ConnectEntity $g 1640 470 1810 470 '1:M'
ConnectEntity $g 1200 450 1810 190 '1:M'
ConnectEntity $g 770 250 930 830 '1:M'

$legendY = 1320
$g.DrawString('Main entities and key foreign-key relationships for the SMNS database.',$bodyFont,$brush,60,$legendY)

$bmp.Save($outputPath,[System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose(); $bmp.Dispose(); $titleFont.Dispose(); $headFont.Dispose(); $bodyFont.Dispose(); $brush.Dispose(); $linePen.Dispose(); $arrowPen.Dispose()
Write-Output "PNG generated: $outputPath"
