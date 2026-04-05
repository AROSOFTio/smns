$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$outputPath = Join-Path $outputDir 'smns-simple-flowchart.png'

$width = 1400
$height = 1500
$bitmap = New-Object System.Drawing.Bitmap $width, $height
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.Clear([System.Drawing.Color]::White)

$titleFont = New-Object System.Drawing.Font('Segoe UI', 24, [System.Drawing.FontStyle]::Bold)
$labelFont = New-Object System.Drawing.Font('Segoe UI', 14, [System.Drawing.FontStyle]::Regular)
$smallFont = New-Object System.Drawing.Font('Segoe UI', 12, [System.Drawing.FontStyle]::Regular)
$textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30, 41, 59))
$linePen = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(71, 85, 105), 3)
$arrowCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap(6, 6, $true)
$linePen.CustomEndCap = $arrowCap

function Draw-RoundedBox {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [int]$R,
        [System.Drawing.Color]$FillColor,
        [System.Drawing.Color]$StrokeColor,
        [string]$Text
    )

    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $R * 2
    $path.AddArc($X, $Y, $d, $d, 180, 90)
    $path.AddArc($X + $W - $d, $Y, $d, $d, 270, 90)
    $path.AddArc($X + $W - $d, $Y + $H - $d, $d, $d, 0, 90)
    $path.AddArc($X, $Y + $H - $d, $d, $d, 90, 90)
    $path.CloseFigure()

    $fill = New-Object System.Drawing.SolidBrush($FillColor)
    $stroke = New-Object System.Drawing.Pen($StrokeColor, 2)
    $G.FillPath($fill, $path)
    $G.DrawPath($stroke, $path)

    $rect = New-Object System.Drawing.RectangleF([single]$X, [single]$Y, [single]$W, [single]$H)
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $G.DrawString($Text, $labelFont, $textBrush, $rect, $sf)

    $fill.Dispose()
    $stroke.Dispose()
    $path.Dispose()
    $sf.Dispose()
}

function Draw-EllipseNode {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X,
        [int]$Y,
        [int]$W,
        [int]$H,
        [System.Drawing.Color]$FillColor,
        [System.Drawing.Color]$StrokeColor,
        [string]$Text
    )

    $fill = New-Object System.Drawing.SolidBrush($FillColor)
    $stroke = New-Object System.Drawing.Pen($StrokeColor, 3)
    $rect = New-Object System.Drawing.Rectangle($X, $Y, $W, $H)
    $G.FillEllipse($fill, $rect)
    $G.DrawEllipse($stroke, $rect)

    $textRect = New-Object System.Drawing.RectangleF([single]($X + 20), [single]($Y + 15), [single]($W - 40), [single]($H - 30))
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $G.DrawString($Text, $labelFont, $textBrush, $textRect, $sf)

    $fill.Dispose()
    $stroke.Dispose()
    $sf.Dispose()
}

function Draw-Arrow {
    param(
        [System.Drawing.Graphics]$G,
        [int]$X1,
        [int]$Y1,
        [int]$X2,
        [int]$Y2,
        [string]$Label = ''
    )

    $G.DrawLine($linePen, $X1, $Y1, $X2, $Y2)
    if ($Label -ne '') {
        $midX = [int](($X1 + $X2) / 2)
        $midY = [int](($Y1 + $Y2) / 2)
        $G.DrawString($Label, $smallFont, $textBrush, $midX + 8, $midY - 20)
    }
}

$graphics.DrawString('SMNS Simple Flowchart', $titleFont, $textBrush, 40, 25)
$graphics.DrawString('Report-ready PNG export', $smallFont, $textBrush, 46, 68)

$centerX = 430
$nodeW = 420
$nodeH = 70

$nodes = @{
    start   = @{ X = 560; Y = 110; W = 160; H = 60 }
    create  = @{ X = $centerX; Y = 220; W = $nodeW; H = $nodeH }
    login   = @{ X = $centerX; Y = 340; W = $nodeW; H = $nodeH }
    register= @{ X = $centerX; Y = 460; W = $nodeW; H = 80 }
    marks   = @{ X = $centerX; Y = 590; W = $nodeW; H = $nodeH }
    publish = @{ X = $centerX; Y = 710; W = $nodeW; H = $nodeH }
    fees    = @{ X = $centerX; Y = 830; W = $nodeW; H = $nodeH }
    decide  = @{ X = 500; Y = 960; W = 280; H = 150 }
    block   = @{ X = 120; Y = 1180; W = 280; H = $nodeH }
    release = @{ X = 820; Y = 1180; W = 320; H = $nodeH }
    issue   = @{ X = 780; Y = 1310; W = 400; H = 85 }
    end     = @{ X = 900; Y = 1430; W = 160; H = 60 }
}

Draw-EllipseNode -G $graphics -X $nodes.start.X -Y $nodes.start.Y -W $nodes.start.W -H $nodes.start.H -FillColor ([System.Drawing.Color]::FromArgb(221,239,214)) -StrokeColor ([System.Drawing.Color]::FromArgb(130,179,102)) -Text 'Start'
Draw-RoundedBox -G $graphics -X $nodes.create.X -Y $nodes.create.Y -W $nodes.create.W -H $nodes.create.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(218,232,252)) -StrokeColor ([System.Drawing.Color]::FromArgb(108,142,191)) -Text 'Admin creates account'
Draw-RoundedBox -G $graphics -X $nodes.login.X -Y $nodes.login.Y -W $nodes.login.W -H $nodes.login.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(218,232,252)) -StrokeColor ([System.Drawing.Color]::FromArgb(108,142,191)) -Text 'Student logs in'
Draw-RoundedBox -G $graphics -X $nodes.register.X -Y $nodes.register.Y -W $nodes.register.W -H $nodes.register.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(255,242,204)) -StrokeColor ([System.Drawing.Color]::FromArgb(214,182,86)) -Text 'Student registers for semester and courses'
Draw-RoundedBox -G $graphics -X $nodes.marks.X -Y $nodes.marks.Y -W $nodes.marks.W -H $nodes.marks.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(255,242,204)) -StrokeColor ([System.Drawing.Color]::FromArgb(214,182,86)) -Text 'Lecturer enters marks'
Draw-RoundedBox -G $graphics -X $nodes.publish.X -Y $nodes.publish.Y -W $nodes.publish.W -H $nodes.publish.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(248,206,204)) -StrokeColor ([System.Drawing.Color]::FromArgb(184,84,80)) -Text 'Admin approves and publishes results'
Draw-RoundedBox -G $graphics -X $nodes.fees.X -Y $nodes.fees.Y -W $nodes.fees.W -H $nodes.fees.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(225,213,231)) -StrokeColor ([System.Drawing.Color]::FromArgb(150,115,166)) -Text 'Finance verifies fees and payments'
$decideX = 500
$decideY = 960
$decideW = 280
$decideH = 150
$diamondPoints = New-Object 'System.Drawing.Point[]' 4
$diamondPoints[0] = New-Object System.Drawing.Point(640, 960)
$diamondPoints[1] = New-Object System.Drawing.Point(780, 1035)
$diamondPoints[2] = New-Object System.Drawing.Point(640, 1110)
$diamondPoints[3] = New-Object System.Drawing.Point(500, 1035)
$diamondFill = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(218,232,252))
$diamondStroke = New-Object System.Drawing.Pen([System.Drawing.Color]::FromArgb(108,142,191), 2)
$graphics.FillPolygon($diamondFill, $diamondPoints)
$graphics.DrawPolygon($diamondStroke, $diamondPoints)
$diamondRect = New-Object System.Drawing.RectangleF([single]($decideX + 15), [single]($decideY + 20), [single]($decideW - 30), [single]($decideH - 40))
$diamondSf = New-Object System.Drawing.StringFormat
$diamondSf.Alignment = [System.Drawing.StringAlignment]::Center
$diamondSf.LineAlignment = [System.Drawing.StringAlignment]::Center
$graphics.DrawString('Eligible for transcript?', $smallFont, $textBrush, $diamondRect, $diamondSf)
$diamondFill.Dispose()
$diamondStroke.Dispose()
$diamondSf.Dispose()
Draw-RoundedBox -G $graphics -X $nodes.block.X -Y $nodes.block.Y -W $nodes.block.W -H $nodes.block.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(248,206,204)) -StrokeColor ([System.Drawing.Color]::FromArgb(184,84,80)) -Text 'Block transcript'
Draw-RoundedBox -G $graphics -X $nodes.release.X -Y $nodes.release.Y -W $nodes.release.W -H $nodes.release.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(221,239,214)) -StrokeColor ([System.Drawing.Color]::FromArgb(130,179,102)) -Text 'Admin releases transcript'
Draw-RoundedBox -G $graphics -X $nodes.issue.X -Y $nodes.issue.Y -W $nodes.issue.W -H $nodes.issue.H -R 18 -FillColor ([System.Drawing.Color]::FromArgb(218,232,252)) -StrokeColor ([System.Drawing.Color]::FromArgb(108,142,191)) -Text 'System issues transcript with verification'
Draw-EllipseNode -G $graphics -X $nodes.end.X -Y $nodes.end.Y -W $nodes.end.W -H $nodes.end.H -FillColor ([System.Drawing.Color]::FromArgb(221,239,214)) -StrokeColor ([System.Drawing.Color]::FromArgb(130,179,102)) -Text 'End'

Draw-Arrow -G $graphics -X1 640 -Y1 170 -X2 640 -Y2 220
Draw-Arrow -G $graphics -X1 640 -Y1 290 -X2 640 -Y2 340
Draw-Arrow -G $graphics -X1 640 -Y1 410 -X2 640 -Y2 460
Draw-Arrow -G $graphics -X1 640 -Y1 540 -X2 640 -Y2 590
Draw-Arrow -G $graphics -X1 640 -Y1 660 -X2 640 -Y2 710
Draw-Arrow -G $graphics -X1 640 -Y1 780 -X2 640 -Y2 830
Draw-Arrow -G $graphics -X1 640 -Y1 900 -X2 640 -Y2 960

Draw-Arrow -G $graphics -X1 500 -Y1 1035 -X2 260 -Y2 1180 -Label 'No'
Draw-Arrow -G $graphics -X1 780 -Y1 1035 -X2 980 -Y2 1180 -Label 'Yes'
Draw-Arrow -G $graphics -X1 980 -Y1 1250 -X2 980 -Y2 1310
Draw-Arrow -G $graphics -X1 980 -Y1 1395 -X2 980 -Y2 1430

$graphics.DrawLine($linePen, 260, 1250, 260, 1460)
$graphics.DrawLine($linePen, 260, 1460, 900, 1460)

$bitmap.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)

$graphics.Dispose()
$bitmap.Dispose()
$titleFont.Dispose()
$labelFont.Dispose()
$smallFont.Dispose()
$textBrush.Dispose()
$linePen.Dispose()
$arrowCap.Dispose()

Write-Output "PNG generated: $outputPath"
