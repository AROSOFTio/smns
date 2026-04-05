$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}
$outputPath = Join-Path $outputDir 'smns-erd-full-report.png'

$width = 4200
$height = 2600
$bmp = New-Object System.Drawing.Bitmap $width, $height
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$g.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 40, [System.Drawing.FontStyle]::Bold)
$subtitleFont = New-Object System.Drawing.Font('Segoe UI', 20, [System.Drawing.FontStyle]::Regular)
$groupFont = New-Object System.Drawing.Font('Segoe UI', 18, [System.Drawing.FontStyle]::Bold)
$headFont = New-Object System.Drawing.Font('Segoe UI', 16, [System.Drawing.FontStyle]::Bold)
$bodyFont = New-Object System.Drawing.Font('Segoe UI', 11, [System.Drawing.FontStyle]::Regular)
$smallFont = New-Object System.Drawing.Font('Segoe UI', 10, [System.Drawing.FontStyle]::Regular)
$textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30, 41, 59))
$mutedBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(71, 85, 105))
$whiteBrush = [System.Drawing.Brushes]::White

function New-LinePen([System.Drawing.Color]$color) {
    $pen = New-Object System.Drawing.Pen($color, 2.2)
    $pen.CustomEndCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(6, 6, $true)
    return $pen
}

$bluePen = New-LinePen ([System.Drawing.Color]::FromArgb(59, 130, 246))
$greenPen = New-LinePen ([System.Drawing.Color]::FromArgb(34, 197, 94))
$amberPen = New-LinePen ([System.Drawing.Color]::FromArgb(217, 119, 6))
$purplePen = New-LinePen ([System.Drawing.Color]::FromArgb(147, 51, 234))
$redPen = New-LinePen ([System.Drawing.Color]::FromArgb(220, 38, 38))
$pinkPen = New-LinePen ([System.Drawing.Color]::FromArgb(236, 72, 153))
$cyanPen = New-LinePen ([System.Drawing.Color]::FromArgb(14, 165, 233))
$slatePen = New-LinePen ([System.Drawing.Color]::FromArgb(71, 85, 105))
$groupPen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(203, 213, 225), 2)

$entityMap = @{}

function Draw-Group {
    param(
        [int]$X, [int]$Y, [int]$W, [int]$H,
        [string]$Title
    )
    $fill = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(248, 250, 252))
    $g.FillRectangle($fill, $X, $Y, $W, $H)
    $g.DrawRectangle($groupPen, $X, $Y, $W, $H)
    $g.DrawString($Title, $groupFont, $textBrush, $X + 16, $Y + 12)
    $fill.Dispose()
}

function Draw-Entity {
    param(
        [string]$Name,
        [int]$X, [int]$Y, [int]$W, [int]$H,
        [string[]]$Fields,
        [System.Drawing.Color]$Fill,
        [System.Drawing.Color]$Header
    )

    $fillBrush = New-Object System.Drawing.SolidBrush($Fill)
    $headerBrush = New-Object System.Drawing.SolidBrush($Header)
    $borderPen = New-Object System.Drawing.Pen($Header, 2)
    $g.FillRectangle($fillBrush, $X, $Y, $W, $H)
    $g.DrawRectangle($borderPen, $X, $Y, $W, $H)
    $g.FillRectangle($headerBrush, $X, $Y, $W, 42)
    $g.DrawString($Name, $headFont, $whiteBrush, $X + 12, $Y + 9)

    $yy = $Y + 54
    foreach ($field in $Fields) {
        $g.DrawString($field, $bodyFont, $textBrush, $X + 12, $yy)
        $yy += 20
    }

    $entityMap[$Name] = @{
        X = $X; Y = $Y; W = $W; H = $H
        L = $X
        R = $X + $W
        T = $Y
        B = $Y + $H
        MX = $X + [int]($W / 2)
        MY = $Y + [int]($H / 2)
    }

    $fillBrush.Dispose()
    $headerBrush.Dispose()
    $borderPen.Dispose()
}

function Draw-Route {
    param(
        [System.Drawing.Pen]$Pen,
        [int[]]$Points,
        [string]$Label = ''
    )

    for ($i = 0; $i -lt ($Points.Length - 2); $i += 2) {
        $g.DrawLine($Pen, $Points[$i], $Points[$i + 1], $Points[$i + 2], $Points[$i + 3])
    }

    if ($Label -ne '') {
        $lx = $Points[2]
        $ly = $Points[3]
        $g.FillRectangle([System.Drawing.Brushes]::White, $lx - 10, $ly - 14, 80, 20)
        $g.DrawString($Label, $smallFont, $mutedBrush, $lx, $ly - 12)
    }
}

$g.DrawString('SMNS Full ERD For Report', $titleFont, $textBrush, 60, 24)
$g.DrawString('Redesigned with more spacing and separated routing lanes for easier explanation and printing', $subtitleFont, $mutedBrush, 66, 84)

Draw-Group 40 150 930 1060 'Identity and People'
Draw-Group 1020 150 1120 1060 'Academic Structure and Registration'
Draw-Group 2190 150 930 1060 'Results and Transcript'
Draw-Group 3170 150 980 1060 'Finance and Support'

Draw-Entity 'USERS' 90 220 280 170 @(
    'PK id',
    'username',
    'email',
    'role',
    'status',
    'require_password_change'
) ([System.Drawing.Color]::FromArgb(239, 246, 255)) ([System.Drawing.Color]::FromArgb(59, 130, 246))

Draw-Entity 'ADMINS' 470 220 280 150 @(
    'PK id',
    'FK user_id',
    'first_name',
    'last_name',
    'email'
) ([System.Drawing.Color]::FromArgb(254, 242, 242)) ([System.Drawing.Color]::FromArgb(220, 38, 38))

Draw-Entity 'LECTURERS' 470 440 300 170 @(
    'PK id',
    'FK user_id',
    'first_name',
    'last_name',
    'department',
    'status'
) ([System.Drawing.Color]::FromArgb(254, 249, 195)) ([System.Drawing.Color]::FromArgb(217, 119, 6))

Draw-Entity 'FINANCE_STAFF' 470 690 300 150 @(
    'PK id',
    'FK user_id',
    'first_name',
    'last_name',
    'status'
) ([System.Drawing.Color]::FromArgb(250, 245, 255)) ([System.Drawing.Color]::FromArgb(147, 51, 234))

Draw-Entity 'STUDENTS' 90 470 320 250 @(
    'PK id',
    'FK user_id',
    'student_id',
    'admission_number',
    'FK program_id',
    'current_semester',
    'academic_status',
    'discipline_status',
    'status',
    'entry_year'
) ([System.Drawing.Color]::FromArgb(236, 253, 245)) ([System.Drawing.Color]::FromArgb(34, 197, 94))

Draw-Entity 'PROGRAMS' 1090 220 320 170 @(
    'PK id',
    'program_code',
    'program_name',
    'duration_years',
    'credits_required',
    'status'
) ([System.Drawing.Color]::FromArgb(243, 232, 255)) ([System.Drawing.Color]::FromArgb(147, 51, 234))

Draw-Entity 'ACADEMIC_YEARS' 1540 220 320 150 @(
    'PK id',
    'year_name',
    'start_date',
    'end_date',
    'status'
) ([System.Drawing.Color]::FromArgb(240, 249, 255)) ([System.Drawing.Color]::FromArgb(14, 165, 233))

Draw-Entity 'SEMESTERS' 1540 470 320 190 @(
    'PK id',
    'FK academic_year_id',
    'semester_name',
    'semester_number',
    'start_date',
    'end_date',
    'status'
) ([System.Drawing.Color]::FromArgb(240, 249, 255)) ([System.Drawing.Color]::FromArgb(14, 165, 233))

Draw-Entity 'COURSES' 1090 470 340 210 @(
    'PK id',
    'course_code',
    'course_name',
    'FK program_id',
    'level_year',
    'semester_offered',
    'credit_hours',
    'status'
) ([System.Drawing.Color]::FromArgb(254, 242, 242)) ([System.Drawing.Color]::FromArgb(220, 38, 38))

Draw-Entity 'COURSE_ASSIGNMENTS' 1090 760 340 170 @(
    'PK id',
    'FK lecturer_id',
    'FK course_id',
    'FK semester_id',
    'status'
) ([System.Drawing.Color]::FromArgb(254, 249, 195)) ([System.Drawing.Color]::FromArgb(217, 119, 6))

Draw-Entity 'SEMESTER_REGISTRATIONS' 2270 220 340 170 @(
    'PK id',
    'FK student_id',
    'FK semester_id',
    'year_of_study',
    'status'
) ([System.Drawing.Color]::FromArgb(236, 253, 245)) ([System.Drawing.Color]::FromArgb(34, 197, 94))

Draw-Entity 'COURSE_REGISTRATIONS' 2270 470 360 190 @(
    'PK id',
    'FK student_id',
    'FK course_id',
    'FK semester_id',
    'status',
    'approved_by'
) ([System.Drawing.Color]::FromArgb(250, 245, 255)) ([System.Drawing.Color]::FromArgb(147, 51, 234))

Draw-Entity 'RESULTS' 2270 760 360 210 @(
    'PK id',
    'FK student_id',
    'FK course_id',
    'FK semester_id',
    'FK entered_by',
    'FK approved_by',
    'total_marks',
    'grade',
    'status'
) ([System.Drawing.Color]::FromArgb(239, 246, 255)) ([System.Drawing.Color]::FromArgb(59, 130, 246))

Draw-Entity 'STUDENT_GPAS' 2690 760 300 170 @(
    'PK id',
    'FK student_id',
    'FK semester_id',
    'semester_gpa',
    'cumulative_gpa'
) ([System.Drawing.Color]::FromArgb(236, 253, 245)) ([System.Drawing.Color]::FromArgb(34, 197, 94))

Draw-Entity 'TRANSCRIPT_RIGHTS' 2690 220 300 150 @(
    'PK id',
    'FK student_id',
    'status',
    'verified_by_user_id'
) ([System.Drawing.Color]::FromArgb(236, 253, 245)) ([System.Drawing.Color]::FromArgb(34, 197, 94))

Draw-Entity 'TRANSCRIPT_ISSUANCES' 2690 470 340 190 @(
    'PK id',
    'FK student_id',
    'transcript_hash',
    'verification_code',
    'verification_token',
    'status'
) ([System.Drawing.Color]::FromArgb(236, 253, 245)) ([System.Drawing.Color]::FromArgb(34, 197, 94))

Draw-Entity 'FEES_STRUCTURE' 3260 220 320 170 @(
    'PK id',
    'FK program_id',
    'fee_type',
    'amount',
    'status'
) ([System.Drawing.Color]::FromArgb(253, 242, 248)) ([System.Drawing.Color]::FromArgb(236, 72, 153))

Draw-Entity 'INVOICES' 3260 470 320 170 @(
    'PK id',
    'FK student_id',
    'FK semester_id',
    'total_amount',
    'status'
) ([System.Drawing.Color]::FromArgb(253, 242, 248)) ([System.Drawing.Color]::FromArgb(236, 72, 153))

Draw-Entity 'PAYMENTS' 3720 470 300 170 @(
    'PK id',
    'FK student_id',
    'FK semester_id',
    'amount_paid',
    'payment_status'
) ([System.Drawing.Color]::FromArgb(253, 242, 248)) ([System.Drawing.Color]::FromArgb(236, 72, 153))

Draw-Entity 'STUDENT_BALANCES' 3260 760 340 170 @(
    'PK id',
    'FK student_id',
    'FK semester_id',
    'total_fees',
    'total_paid',
    'balance'
) ([System.Drawing.Color]::FromArgb(253, 242, 248)) ([System.Drawing.Color]::FromArgb(236, 72, 153))

Draw-Entity 'STUDENT_REQUESTS' 3720 760 300 170 @(
    'PK id',
    'FK student_id',
    'FK user_id',
    'request_type',
    'status'
) ([System.Drawing.Color]::FromArgb(240, 249, 255)) ([System.Drawing.Color]::FromArgb(14, 165, 233))

Draw-Entity 'NOTIFICATIONS' 3260 1010 320 150 @(
    'PK id',
    'FK user_id',
    'title',
    'type',
    'created_at'
) ([System.Drawing.Color]::FromArgb(240, 249, 255)) ([System.Drawing.Color]::FromArgb(14, 165, 233))

Draw-Entity 'ACTIVITY_LOGS' 3720 1010 300 150 @(
    'PK id',
    'FK user_id',
    'action',
    'module',
    'created_at'
) ([System.Drawing.Color]::FromArgb(240, 249, 255)) ([System.Drawing.Color]::FromArgb(14, 165, 233))

# Identity lanes
Draw-Route $bluePen @(370,265,420,265,420,295,470,295) '1:1'
Draw-Route $amberPen @(370,285,430,285,430,525,470,525) '1:1'
Draw-Route $purplePen @(370,305,440,305,440,765,470,765) '1:1'
Draw-Route $greenPen @(370,325,410,325,410,595,90,595) '1:1'

# Academic lanes
Draw-Route $greenPen @(410,565,1010,565,1010,305,1090,305) 'M:1'
Draw-Route $purplePen @(1410,305,1490,305,1490,295,1540,295) '1:M'
Draw-Route $redPen @(1430,575,1495,575,1495,565,1540,565) '1:M'
Draw-Route $amberPen @(770,525,980,525,980,845,1090,845) '1:M'

# Registration lanes with separated corridors
Draw-Route $greenPen @(410,620,2130,620,2130,305,2270,305) '1:M'
Draw-Route $greenPen @(410,660,2180,660,2180,565,2270,565) '1:M'
Draw-Route $purplePen @(1430,610,2190,610,2190,545,2270,545) '1:M'
Draw-Route $redPen @(1860,600,2220,600,2220,585,2270,585) '1:M'

# Results lanes
Draw-Route $greenPen @(410,700,2190,700,2190,845,2270,845) '1:M'
Draw-Route $redPen @(1430,640,2210,640,2210,885,2270,885) '1:M'
Draw-Route $purplePen @(1860,630,2230,630,2230,905,2270,905) '1:M'
Draw-Route $amberPen @(1430,840,1840,840,1840,925,2270,925) '1:M'
Draw-Route $bluePen @(750,295,2190,295,2190,945,2270,945) '1:M'

# Transcript lanes
Draw-Route $greenPen @(410,740,2640,740,2640,295,2690,295) '1:1'
Draw-Route $greenPen @(410,780,2645,780,2645,565,2690,565) '1:M'
Draw-Route $bluePen @(2990,565,3140,565,3140,555,3260,555) '1:M'

# GPA lane
Draw-Route $greenPen @(2630,865,2660,865,2660,845,2690,845) '1:1'

# Finance lanes
Draw-Route $greenPen @(410,820,3210,820,3210,555,3260,555) '1:M'
Draw-Route $greenPen @(410,860,3660,860,3660,555,3720,555) '1:M'
Draw-Route $greenPen @(410,900,3210,900,3210,845,3260,845) '1:M'
Draw-Route $greenPen @(410,940,3660,940,3660,845,3720,845) '1:M'
Draw-Route $purplePen @(1860,590,3200,590,3200,555,3260,555) '1:M'
Draw-Route $purplePen @(1860,620,3190,620,3190,845,3260,845) '1:M'
Draw-Route $greenPen @(2990,295,3210,295,3210,305,3260,305) '1:M'

# Support lanes
Draw-Route $bluePen @(370,345,3210,345,3210,1085,3260,1085) '1:M'
Draw-Route $bluePen @(370,365,3660,365,3660,1085,3720,1085) '1:M'

$g.DrawString('Legend', $groupFont, $textBrush, 60, 1270)
$g.DrawString('Green: student-centered relationships   Blue: users, results, notifications   Purple: registration and finance staff', $smallFont, $mutedBrush, 60, 1308)
$g.DrawString('Red: academic structure   Amber: lecturer teaching links   Pink finance entities are kept in a separate right-side lane', $smallFont, $mutedBrush, 60, 1332)
$g.DrawString('This version uses wider spacing and distinct routing corridors so arrows are easier to follow in a printed report.', $smallFont, $mutedBrush, 60, 1356)

$bmp.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)

$g.Dispose()
$bmp.Dispose()
$titleFont.Dispose()
$subtitleFont.Dispose()
$groupFont.Dispose()
$headFont.Dispose()
$bodyFont.Dispose()
$smallFont.Dispose()
$textBrush.Dispose()
$mutedBrush.Dispose()
$bluePen.Dispose()
$greenPen.Dispose()
$amberPen.Dispose()
$purplePen.Dispose()
$redPen.Dispose()
$pinkPen.Dispose()
$cyanPen.Dispose()
$slatePen.Dispose()
$groupPen.Dispose()

Write-Output "PNG generated: $outputPath"
