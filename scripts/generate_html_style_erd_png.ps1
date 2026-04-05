$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}
$outputPath = Join-Path $outputDir 'smns-erd-with-arrows.png'

$width = 4300
$height = 1850
$bmp = New-Object System.Drawing.Bitmap $width, $height
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$g.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 34, [System.Drawing.FontStyle]::Bold)
$subFont = New-Object System.Drawing.Font('Segoe UI', 18, [System.Drawing.FontStyle]::Regular)
$headFont = New-Object System.Drawing.Font('Consolas', 13, [System.Drawing.FontStyle]::Bold)
$bodyFont = New-Object System.Drawing.Font('Consolas', 10, [System.Drawing.FontStyle]::Regular)
$labelFont = New-Object System.Drawing.Font('Consolas', 9, [System.Drawing.FontStyle]::Regular)
$textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(31, 41, 55))
$mutedBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(75, 85, 99))
$linePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(99, 102, 241), 2)
$linePen.CustomEndCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(5, 5, $true)
$boxStroke = [System.Drawing.Color]::FromArgb(139, 92, 246)
$boxFill = [System.Drawing.Color]::FromArgb(245, 243, 255)
$headerFill = [System.Drawing.Color]::FromArgb(237, 233, 254)
$entityMap = @{}

function Draw-Entity {
    param(
        [string]$Name,
        [int]$X,[int]$Y,[int]$W,[int]$H,
        [string[]]$Fields
    )
    $fillBrush = New-Object System.Drawing.SolidBrush($boxFill)
    $strokePen = New-Object System.Drawing.Pen($boxStroke, 2)
    $headerBrush = New-Object System.Drawing.SolidBrush($headerFill)
    $g.FillRectangle($fillBrush, $X, $Y, $W, $H)
    $g.DrawRectangle($strokePen, $X, $Y, $W, $H)
    $g.FillRectangle($headerBrush, $X, $Y, $W, 32)
    $g.DrawLine($strokePen, $X, $Y + 32, $X + $W, $Y + 32)
    $g.DrawString($Name, $headFont, $textBrush, $X + 8, $Y + 7)

    $yy = $Y + 42
    foreach ($field in $Fields) {
        $g.DrawString($field, $bodyFont, $mutedBrush, $X + 8, $yy)
        $yy += 17
    }

    $entityMap[$Name] = @{
        L = $X; R = $X + $W; T = $Y; B = $Y + $H
        MX = $X + [int]($W / 2); MY = $Y + [int]($H / 2)
    }

    $fillBrush.Dispose()
    $strokePen.Dispose()
    $headerBrush.Dispose()
}

function Route-Line {
    param(
        [int[]]$Pts,
        [string]$Label = ''
    )
    for ($i = 0; $i -lt ($Pts.Length - 2); $i += 2) {
        $g.DrawLine($linePen, $Pts[$i], $Pts[$i+1], $Pts[$i+2], $Pts[$i+3])
    }
    if ($Label -ne '') {
        $lx = $Pts[2]
        $ly = $Pts[3]
        $g.FillRectangle([System.Drawing.Brushes]::White, $lx - 6, $ly - 12, 90, 18)
        $g.DrawString($Label, $labelFont, $mutedBrush, $lx, $ly - 11)
    }
}

$g.DrawString('SMNS - Full Entity Relationship Diagram', $titleFont, $textBrush, 50, 25)
$g.DrawString('All tables with crow-foot relationship arrows', $subFont, $mutedBrush, 55, 82)

# Top band
Draw-Entity 'ACADEMIC_YEARS' 1120 150 210 150 @('uuid    id           PK','string  year_name','date    start_date','enum    status')
Draw-Entity 'USERS' 1770 130 210 170 @('uuid    id           PK','string  username','string  email','enum    role','enum    status')
Draw-Entity 'PROGRAMS' 2090 130 290 190 @('uuid    id           PK','string  program_code','string  program_name','int     duration_years','int     total_credits_required')

# Middle band
Draw-Entity 'LECTURERS' 60 530 210 180 @('uuid    id           PK','uuid    user_id      FK','string  department','enum    status')
Draw-Entity 'ADMINS' 400 530 210 180 @('uuid    id           PK','uuid    user_id      FK','string  first_name','string  email')
Draw-Entity 'COURSES' 760 490 220 220 @('uuid    id           PK','uuid    program_id   FK','string  course_code','string  course_name','int     credit_hours','enum    status')
Draw-Entity 'FINANCE_STAFF' 1110 530 210 180 @('uuid    id           PK','uuid    user_id      FK','string  first_name','enum    status')
Draw-Entity 'SEMESTERS' 1800 470 220 220 @('uuid    id           PK','uuid    academic_year_id FK','string  semester_name','date    start_date','date    end_date','enum    status')
Draw-Entity 'STUDENTS' 2440 470 240 220 @('uuid    id           PK','uuid    user_id      FK','uuid    program_id   FK','string  student_id','string  admission_number','enum    academic_status','int     entry_year')
Draw-Entity 'FEES_STRUCTURE' 3370 500 220 180 @('uuid    id           PK','uuid    program_id   FK','enum    fee_type','decimal amount','enum    status')
Draw-Entity 'ACTIVITY_LOGS' 3720 500 220 180 @('uuid    id           PK','uuid    user_id      FK','string  action','string  module','timestamp created_at')

# Bottom band
Draw-Entity 'COURSE_ASSIGNMENTS' 150 960 230 190 @('uuid    id           PK','uuid    lecturer_id  FK','uuid    course_id    FK','uuid    semester_id  FK','enum    status')
Draw-Entity 'SEMESTER_REGISTRATIONS' 490 960 240 190 @('uuid    id           PK','uuid    student_id   FK','uuid    semester_id  FK','enum    status')
Draw-Entity 'COURSE_REGISTRATIONS' 850 930 250 220 @('uuid    id           PK','uuid    student_id   FK','uuid    course_id    FK','uuid    semester_id  FK','enum    status','uuid    approved_by   FK')
Draw-Entity 'RESULTS' 1270 900 220 250 @('uuid    id           PK','uuid    student_id   FK','uuid    course_id    FK','uuid    semester_id  FK','decimal total_marks','string  grade','uuid    entered_by   FK','uuid    approved_by  FK','enum    status')
Draw-Entity 'STUDENT_GPAS' 1650 980 220 170 @('uuid    id           PK','uuid    student_id   FK','uuid    semester_id  FK','decimal semester_gpa','decimal cumulative_gpa')
Draw-Entity 'TRANSCRIPT_RIGHTS' 2050 1000 220 150 @('uuid    id           PK','uuid    student_id   FK','enum    status','uuid    verified_by_user_id FK')
Draw-Entity 'TRANSCRIPT_ISSUANCES' 2440 960 250 190 @('uuid    id           PK','uuid    student_id   FK','string  transcript_hash','string  verification_code','string  verification_token','enum    status')
Draw-Entity 'INVOICES' 2950 980 220 170 @('uuid    id           PK','uuid    student_id   FK','uuid    semester_id  FK','decimal total_amount','enum    status')
Draw-Entity 'STUDENT_BALANCES' 3270 980 240 170 @('uuid    id           PK','uuid    student_id   FK','uuid    semester_id  FK','decimal total_fees','decimal balance')
Draw-Entity 'STUDENT_REQUESTS' 3600 980 220 170 @('uuid    id           PK','uuid    student_id   FK','enum    request_type','enum    status')
Draw-Entity 'NOTIFICATIONS' 3890 930 230 220 @('uuid    id           PK','uuid    student_id   FK','uuid    user_id      FK','string  title','enum    type','timestamp created_at')
Draw-Entity 'PAYMENTS' 4010 1360 220 170 @('uuid    id           PK','uuid    student_id   FK','uuid    invoice_id   FK','decimal amount_paid','enum    payment_status')

# Cleaner wide routes similar to screenshot but spaced
Route-Line @(270,620,270,420,850,420,1770,220) 'is'
Route-Line @(610,620,610,390,1550,390,1770,240) 'is'
Route-Line @(1320,620,1320,360,1820,360,1770,260) 'is'
Route-Line @(2560,560,2560,330,1960,330,1980,230) 'has'
Route-Line @(1980,220,2080,220,2080,220,2090,220) 'has'

Route-Line @(2560,590,2560,760,2560,760,610,1040) 'registers'
Route-Line @(2560,610,2560,790,960,790,960,930) 'registers'
Route-Line @(2560,630,2560,820,1380,820,1380,900) 'has'
Route-Line @(2560,650,2560,840,1760,840,1760,980) 'has'
Route-Line @(2560,670,2560,870,2170,870,2170,1000) 'has'
Route-Line @(2560,690,2560,900,2550,900,2550,960) 'receives'
Route-Line @(2560,710,2560,930,3060,930,3060,980) 'billed'
Route-Line @(2560,730,2560,960,3390,960,3390,980) 'has'
Route-Line @(2560,750,2560,990,3700,990,3700,980) 'submits'
Route-Line @(2560,770,2560,1020,3990,1020,3990,930) 'notified'

Route-Line @(2380,220,3380,220,3380,220,3370,220) 'has'
Route-Line @(2380,240,3890,240,3890,240,3890,1040) 'generates'
Route-Line @(1330,220,1900,220,1900,520,1800,520) 'has'
Route-Line @(1330,240,1810,240,1810,240,60,620) 'is'
Route-Line @(1330,260,1815,260,1815,260,1110,620) 'is'

Route-Line @(980,600,980,860,1500,860,1800,560) 'has'
Route-Line @(980,620,980,880,980,880,150,1040) 'assigned via'
Route-Line @(980,640,980,900,980,900,850,1040) 'taken via'
Route-Line @(980,660,980,920,1380,920,1270,1020) 'graded in'

Route-Line @(2020,560,2020,880,610,880,610,960) 'registers'
Route-Line @(2020,580,2020,900,1760,900,1760,980) 'records'
Route-Line @(2020,600,2020,920,960,920,960,930) 'has'
Route-Line @(2020,620,2020,940,1380,940,1380,900) 'has'
Route-Line @(2020,640,2020,960,3060,960,3060,980) 'generates'
Route-Line @(2020,660,2020,980,3390,980,3390,980) 'tracks'

Route-Line @(270,710,270,850,270,850,150,1040) 'assigned'
Route-Line @(3170,1060,4010,1060,4010,1445,4010,1445) 'paid via'
Route-Line @(3990,1150,3990,1250,4120,1250,4120,1445) 'pays'
Route-Line @(3890,1040,3890,830,3890,830,3940,620) 'receives'

$g.DrawString('Free PNG export created from the ERD layout with cleaner spacing for report visibility.', $subFont, $mutedBrush, 55, 1765)

$bmp.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)

$g.Dispose()
$bmp.Dispose()
$titleFont.Dispose()
$subFont.Dispose()
$headFont.Dispose()
$bodyFont.Dispose()
$labelFont.Dispose()
$textBrush.Dispose()
$mutedBrush.Dispose()
$linePen.Dispose()

Write-Output "PNG generated: $outputPath"
