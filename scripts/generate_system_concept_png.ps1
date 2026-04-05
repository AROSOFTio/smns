$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$outputPath = Join-Path $outputDir 'smns-system-concept-diagram.png'

$width = 2200
$height = 1400
$bitmap = New-Object System.Drawing.Bitmap $width, $height
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$graphics.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 28, [System.Drawing.FontStyle]::Bold)
$subtitleFont = New-Object System.Drawing.Font('Segoe UI', 14, [System.Drawing.FontStyle]::Regular)
$moduleFont = New-Object System.Drawing.Font('Segoe UI', 15, [System.Drawing.FontStyle]::Bold)
$itemFont = New-Object System.Drawing.Font('Segoe UI', 11, [System.Drawing.FontStyle]::Regular)
$textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30, 41, 59))
$linePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(100, 116, 139), 3)
$arrowCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(6, 6, $true)
$linePen.CustomEndCap = $arrowCap

function Draw-RoundedRectangle {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [int]$R,
        [System.Drawing.Color]$Fill,
        [System.Drawing.Color]$Stroke
    )

    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $R * 2
    $path.AddArc($X, $Y, $d, $d, 180, 90)
    $path.AddArc($X + $W - $d, $Y, $d, $d, 270, 90)
    $path.AddArc($X + $W - $d, $Y + $H - $d, $d, $d, 0, 90)
    $path.AddArc($X, $Y + $H - $d, $d, $d, 90, 90)
    $path.CloseFigure()

    $fillBrush = New-Object System.Drawing.SolidBrush($Fill)
    $strokePen = New-Object System.Drawing.Pen($Stroke, 2)
    $G.FillPath($fillBrush, $path)
    $G.DrawPath($strokePen, $path)

    $fillBrush.Dispose()
    $strokePen.Dispose()
    return $path
}

function Draw-Module {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [string]$Title,
        [string[]]$Items,
        [System.Drawing.Color]$Fill,
        [System.Drawing.Color]$Stroke
    )

    $path = Draw-RoundedRectangle -G $G -X $X -Y $Y -W $W -H $H -R 22 -Fill $Fill -Stroke $Stroke
    $headerRect = New-Object System.Drawing.RectangleF([single]($X + 18), [single]($Y + 14), [single]($W - 36), [single]40)
    $G.DrawString($Title, $moduleFont, $textBrush, $headerRect)

    $yPos = $Y + 62
    foreach ($item in $Items) {
        $bulletRect = New-Object System.Drawing.RectangleF([single]($X + 22), [single]$yPos, [single]18, [single]18)
        $G.FillEllipse((New-Object System.Drawing.SolidBrush($Stroke)), $bulletRect)
        $textRect = New-Object System.Drawing.RectangleF([single]($X + 48), [single]($yPos - 3), [single]($W - 62), [single]28)
        $G.DrawString($item, $itemFont, $textBrush, $textRect)
        $yPos += 32
    }

    $path.Dispose()
}

function Draw-CenterNode {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [string]$Title,
        [string]$Subtitle
    )

    $fill = [System.Drawing.Color]::FromArgb(218, 232, 252)
    $stroke = [System.Drawing.Color]::FromArgb(108, 142, 191)
    $path = Draw-RoundedRectangle -G $G -X $X -Y $Y -W $W -H $H -R 30 -Fill $fill -Stroke $stroke

    $titleRect = New-Object System.Drawing.RectangleF([single]($X + 30), [single]($Y + 35), [single]($W - 60), [single]50)
    $subRect = New-Object System.Drawing.RectangleF([single]($X + 30), [single]($Y + 95), [single]($W - 60), [single]80)
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $G.DrawString($Title, $titleFont, $textBrush, $titleRect, $sf)
    $G.DrawString($Subtitle, $subtitleFont, $textBrush, $subRect, $sf)
    $sf.Dispose()
    $path.Dispose()
}

function Draw-Link {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X1,
        [int]$Y1,
        [int]$X2,
        [int]$Y2
    )

    $G.DrawLine($linePen, $X1, $Y1, $X2, $Y2)
}

$graphics.DrawString('SMNS Full System Concept Diagram', $titleFont, $textBrush, 50, 25)
$graphics.DrawString('Complete high-level view of the Seminary Management and Results System', $subtitleFont, $textBrush, 55, 78)

$center = @{ X = 760; Y = 490; W = 680; H = 220 }
Draw-CenterNode -G $graphics -X $center.X -Y $center.Y -W $center.W -H $center.H -Title 'SMNS' -Subtitle 'Seminary Management and Results System'

$modules = @(
    @{
        Title = 'User Management'
        X = 60; Y = 90; W = 420; H = 220
        Fill = [System.Drawing.Color]::FromArgb(221,239,214)
        Stroke = [System.Drawing.Color]::FromArgb(130,179,102)
        Items = @('Authentication', 'Roles and permissions', 'Password and account security', 'Profile-linked user accounts')
        LinkX = 480; LinkY = 310; TargetX = 760; TargetY = 520
    },
    @{
        Title = 'Student Management'
        X = 60; Y = 370; W = 420; H = 260
        Fill = [System.Drawing.Color]::FromArgb(255,242,204)
        Stroke = [System.Drawing.Color]::FromArgb(214,182,86)
        Items = @('Student profiles', 'Registration numbers', 'Guardian and bio-data', 'Academic and discipline status', 'Graduation profile')
        LinkX = 480; LinkY = 500; TargetX = 760; TargetY = 570
    },
    @{
        Title = 'Academic Management'
        X = 60; Y = 690; W = 420; H = 260
        Fill = [System.Drawing.Color]::FromArgb(248,206,204)
        Stroke = [System.Drawing.Color]::FromArgb(184,84,80)
        Items = @('Programs', 'Courses', 'Academic years and semesters', 'Semester registration', 'Course registration')
        LinkX = 480; LinkY = 780; TargetX = 760; TargetY = 640
    },
    @{
        Title = 'Results Management'
        X = 1720; Y = 90; W = 420; H = 260
        Fill = [System.Drawing.Color]::FromArgb(225,213,231)
        Stroke = [System.Drawing.Color]::FromArgb(150,115,166)
        Items = @('Marks entry', 'Submission, approval and publishing', 'Published results', 'GPA and academic standing', 'Results audit trail')
        LinkX = 1720; LinkY = 310; TargetX = 1440; TargetY = 520
    },
    @{
        Title = 'Finance Management'
        X = 1720; Y = 410; W = 420; H = 260
        Fill = [System.Drawing.Color]::FromArgb(218,232,252)
        Stroke = [System.Drawing.Color]::FromArgb(108,142,191)
        Items = @('Fee structure', 'Invoices', 'Payments', 'Balances and clearance', 'Finance verification')
        LinkX = 1720; LinkY = 540; TargetX = 1440; TargetY = 580
    },
    @{
        Title = 'Transcript Management'
        X = 1720; Y = 730; W = 420; H = 260
        Fill = [System.Drawing.Color]::FromArgb(234, 242, 215)
        Stroke = [System.Drawing.Color]::FromArgb(126, 160, 76)
        Items = @('Eligibility checks', 'Admin-controlled release', 'Transcript issuance', 'Verification code and token', 'QR and public verification')
        LinkX = 1720; LinkY = 820; TargetX = 1440; TargetY = 640
    },
    @{
        Title = 'Communication and Requests'
        X = 300; Y = 1040; W = 520; H = 240
        Fill = [System.Drawing.Color]::FromArgb(230, 243, 255)
        Stroke = [System.Drawing.Color]::FromArgb(90, 144, 198)
        Items = @('Notifications', 'Student requests', 'Announcements', 'Operational follow-up')
        LinkX = 820; LinkY = 1040; TargetX = 930; TargetY = 710
    },
    @{
        Title = 'Reporting and Audit'
        X = 1380; Y = 1040; W = 520; H = 240
        Fill = [System.Drawing.Color]::FromArgb(245, 232, 255)
        Stroke = [System.Drawing.Color]::FromArgb(150, 100, 190)
        Items = @('Reports and analytics', 'Activity logs', 'Results audit', 'Governance and monitoring')
        LinkX = 1380; LinkY = 1040; TargetX = 1270; TargetY = 710
    }
)

foreach ($module in $modules) {
    Draw-Module -G $graphics -X $module.X -Y $module.Y -W $module.W -H $module.H -Title $module.Title -Items $module.Items -Fill $module.Fill -Stroke $module.Stroke
    Draw-Link -G $graphics -X1 $module.LinkX -Y1 $module.LinkY -X2 $module.TargetX -Y2 $module.TargetY
}

$footer = 'Use this concept diagram before the DFD, Flowchart, and ERD in your report.'
$graphics.DrawString($footer, $subtitleFont, $textBrush, 55, 1330)

$bitmap.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)

$graphics.Dispose()
$bitmap.Dispose()
$titleFont.Dispose()
$subtitleFont.Dispose()
$moduleFont.Dispose()
$itemFont.Dispose()
$textBrush.Dispose()
$linePen.Dispose()
$arrowCap.Dispose()

Write-Output "PNG generated: $outputPath"
