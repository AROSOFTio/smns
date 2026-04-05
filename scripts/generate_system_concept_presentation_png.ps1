$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$outputPath = Join-Path $outputDir 'smns-system-concept-diagram-presentation.png'

$width = 2600
$height = 1600
$bitmap = New-Object System.Drawing.Bitmap $width, $height
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$graphics.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 34, [System.Drawing.FontStyle]::Bold)
$subtitleFont = New-Object System.Drawing.Font('Segoe UI', 18, [System.Drawing.FontStyle]::Regular)
$moduleFont = New-Object System.Drawing.Font('Segoe UI', 20, [System.Drawing.FontStyle]::Bold)
$itemFont = New-Object System.Drawing.Font('Segoe UI', 14, [System.Drawing.FontStyle]::Regular)
$centerTitleFont = New-Object System.Drawing.Font('Segoe UI', 40, [System.Drawing.FontStyle]::Bold)
$centerSubtitleFont = New-Object System.Drawing.Font('Segoe UI', 20, [System.Drawing.FontStyle]::Regular)
$textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30, 41, 59))
$linePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(100, 116, 139), 4)
$arrowCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(8, 8, $true)
$linePen.CustomEndCap = $arrowCap

function New-RoundedPath {
    param(
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [int]$R
    )

    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $R * 2
    $path.AddArc($X, $Y, $d, $d, 180, 90)
    $path.AddArc($X + $W - $d, $Y, $d, $d, 270, 90)
    $path.AddArc($X + $W - $d, $Y + $H - $d, $d, $d, 0, 90)
    $path.AddArc($X, $Y + $H - $d, $d, $d, 90, 90)
    $path.CloseFigure()
    return $path
}

function Draw-Panel {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [int]$R,
        [System.Drawing.Color]$Fill,
        [System.Drawing.Color]$Stroke,
        [string]$Title,
        [string[]]$Items
    )

    $path = New-RoundedPath -X $X -Y $Y -W $W -H $H -R $R
    $fillBrush = New-Object System.Drawing.SolidBrush($Fill)
    $strokePen = New-Object System.Drawing.Pen($Stroke, 2.5)
    $G.FillPath($fillBrush, $path)
    $G.DrawPath($strokePen, $path)

    $titleRect = New-Object System.Drawing.RectangleF([single]($X + 24), [single]($Y + 18), [single]($W - 48), [single]45)
    $G.DrawString($Title, $moduleFont, $textBrush, $titleRect)

    $itemY = $Y + 80
    foreach ($item in $Items) {
        $bulletBrush = New-Object System.Drawing.SolidBrush($Stroke)
        $G.FillEllipse($bulletBrush, $X + 28, $itemY + 6, 10, 10)
        $bulletBrush.Dispose()
        $itemRect = New-Object System.Drawing.RectangleF([single]($X + 52), [single]$itemY, [single]($W - 70), [single]30)
        $G.DrawString($item, $itemFont, $textBrush, $itemRect)
        $itemY += 38
    }

    $fillBrush.Dispose()
    $strokePen.Dispose()
    $path.Dispose()
}

function Draw-CenterPanel {
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
    $path = New-RoundedPath -X $X -Y $Y -W $W -H $H -R 36
    $fillBrush = New-Object System.Drawing.SolidBrush($fill)
    $strokePen = New-Object System.Drawing.Pen($stroke, 3)
    $G.FillPath($fillBrush, $path)
    $G.DrawPath($strokePen, $path)

    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center

    $titleRect = New-Object System.Drawing.RectangleF([single]$X, [single]($Y + 35), [single]$W, [single]70)
    $subRect = New-Object System.Drawing.RectangleF([single]($X + 30), [single]($Y + 110), [single]($W - 60), [single]90)
    $G.DrawString($Title, $centerTitleFont, $textBrush, $titleRect, $sf)
    $G.DrawString($Subtitle, $centerSubtitleFont, $textBrush, $subRect, $sf)

    $sf.Dispose()
    $fillBrush.Dispose()
    $strokePen.Dispose()
    $path.Dispose()
}

function Draw-Connector {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X1,
        [int]$Y1,
        [int]$X2,
        [int]$Y2
    )

    $G.DrawLine($linePen, $X1, $Y1, $X2, $Y2)
}

$graphics.DrawString('SMNS System Concept Diagram', $titleFont, $textBrush, 60, 28)
$graphics.DrawString('Presentation and print layout with expanded spacing and larger text', $subtitleFont, $textBrush, 66, 86)

$center = @{ X = 910; Y = 580; W = 780; H = 240 }
Draw-CenterPanel -G $graphics -X $center.X -Y $center.Y -W $center.W -H $center.H -Title 'SMNS' -Subtitle 'Seminary Management and Results System'

$modules = @(
    @{
        Title='User Management'; X=70; Y=120; W=500; H=250;
        Fill=[System.Drawing.Color]::FromArgb(221,239,214); Stroke=[System.Drawing.Color]::FromArgb(130,179,102);
        Items=@('Authentication','Roles and permissions','Password and account security','Profile-linked accounts');
        FX=570; FY=330; TX=910; TY=640
    },
    @{
        Title='Student Management'; X=70; Y=430; W=500; H=290;
        Fill=[System.Drawing.Color]::FromArgb(255,242,204); Stroke=[System.Drawing.Color]::FromArgb(214,182,86);
        Items=@('Student profiles','Registration numbers','Guardian and bio-data','Academic and discipline status','Graduation profile');
        FX=570; FY=560; TX=910; TY=700
    },
    @{
        Title='Academic Management'; X=70; Y=790; W=500; H=290;
        Fill=[System.Drawing.Color]::FromArgb(248,206,204); Stroke=[System.Drawing.Color]::FromArgb(184,84,80);
        Items=@('Programs','Courses','Academic years and semesters','Semester registration','Course registration');
        FX=570; FY=920; TX=910; TY=760
    },
    @{
        Title='Communication and Requests'; X=320; Y=1150; W=620; H=260;
        Fill=[System.Drawing.Color]::FromArgb(230,243,255); Stroke=[System.Drawing.Color]::FromArgb(90,144,198);
        Items=@('Notifications','Student requests','Announcements','Operational follow-up');
        FX=940; FY=1150; TX=1100; TY=820
    },
    @{
        Title='Results Management'; X=1840; Y=120; W=500; H=290;
        Fill=[System.Drawing.Color]::FromArgb(225,213,231); Stroke=[System.Drawing.Color]::FromArgb(150,115,166);
        Items=@('Marks entry','Submission, approval and publishing','Published results','GPA and academic standing','Results audit trail');
        FX=1840; FY=330; TX=1690; TY=640
    },
    @{
        Title='Finance Management'; X=1840; Y=470; W=500; H=290;
        Fill=[System.Drawing.Color]::FromArgb(218,232,252); Stroke=[System.Drawing.Color]::FromArgb(108,142,191);
        Items=@('Fee structure','Invoices','Payments','Balances and clearance','Finance verification');
        FX=1840; FY=610; TX=1690; TY=700
    },
    @{
        Title='Transcript Management'; X=1840; Y=820; W=500; H=290;
        Fill=[System.Drawing.Color]::FromArgb(234,242,215); Stroke=[System.Drawing.Color]::FromArgb(126,160,76);
        Items=@('Eligibility checks','Admin-controlled release','Transcript issuance','Verification code and token','QR and public verification');
        FX=1840; FY=960; TX=1690; TY=760
    },
    @{
        Title='Reporting and Audit'; X=1560; Y=1150; W=620; H=260;
        Fill=[System.Drawing.Color]::FromArgb(245,232,255); Stroke=[System.Drawing.Color]::FromArgb(150,100,190);
        Items=@('Reports and analytics','Activity logs','Results audit','Governance and monitoring');
        FX=1560; FY=1150; TX=1500; TY=820
    }
)

foreach ($module in $modules) {
    Draw-Panel -G $graphics -X $module.X -Y $module.Y -W $module.W -H $module.H -R 24 -Fill $module.Fill -Stroke $module.Stroke -Title $module.Title -Items $module.Items
    Draw-Connector -G $graphics -X1 $module.FX -Y1 $module.FY -X2 $module.TX -Y2 $module.TY
}

$footer = 'Recommended order in your report: Concept Diagram, DFD, Flowchart, ERD.'
$graphics.DrawString($footer, $subtitleFont, $textBrush, 66, 1500)

$bitmap.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)

$graphics.Dispose()
$bitmap.Dispose()
$titleFont.Dispose()
$subtitleFont.Dispose()
$moduleFont.Dispose()
$itemFont.Dispose()
$centerTitleFont.Dispose()
$centerSubtitleFont.Dispose()
$textBrush.Dispose()
$linePen.Dispose()
$arrowCap.Dispose()

Write-Output "PNG generated: $outputPath"
