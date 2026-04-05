$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $root 'docs\diagrams\exports'
if (-not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$outputPath = Join-Path $outputDir 'smns-simple-dfd.png'

$width = 1600
$height = 900
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

    $xVal = [int]$X
    $yVal = [int]$Y
    $wVal = [int]$W
    $hVal = [int]$H
    $rVal = [int]$R
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $rVal * 2
    $path.AddArc($xVal, $yVal, $d, $d, 180, 90)
    $path.AddArc($xVal + $wVal - $d, $yVal, $d, $d, 270, 90)
    $path.AddArc($xVal + $wVal - $d, $yVal + $hVal - $d, $d, $d, 0, 90)
    $path.AddArc($xVal, $yVal + $hVal - $d, $d, $d, 90, 90)
    $path.CloseFigure()

    $fill = New-Object System.Drawing.SolidBrush($FillColor)
    $stroke = New-Object System.Drawing.Pen($StrokeColor, 2)
    $G.FillPath($fill, $path)
    $G.DrawPath($stroke, $path)

    $rect = New-Object System.Drawing.RectangleF([single]$xVal, [single]$yVal, [single]$wVal, [single]$hVal)
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $G.DrawString($Text, $labelFont, $textBrush, $rect, $sf)

    $fill.Dispose()
    $stroke.Dispose()
    $path.Dispose()
    $sf.Dispose()
}

function Draw-DataStore {
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

    $xVal = [int]$X
    $yVal = [int]$Y
    $wVal = [int]$W
    $hVal = [int]$H
    $fill = New-Object System.Drawing.SolidBrush($FillColor)
    $stroke = New-Object System.Drawing.Pen($StrokeColor, 2)
    $rect = New-Object System.Drawing.Rectangle($xVal, $yVal, $wVal, $hVal)

    $G.FillRectangle($fill, $rect)
    $G.DrawRectangle($stroke, $rect)
    $G.DrawLine($stroke, $xVal + 12, $yVal, $xVal + 12, $yVal + $hVal)
    $G.DrawLine($stroke, $xVal + $wVal - 12, $yVal, $xVal + $wVal - 12, $yVal + $hVal)

    $textRect = New-Object System.Drawing.RectangleF([single]($xVal + 20), [single]($yVal + 8), [single]($wVal - 40), [single]($hVal - 16))
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $G.DrawString($Text, $smallFont, $textBrush, $textRect, $sf)

    $fill.Dispose()
    $stroke.Dispose()
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

    $xVal = [int]$X
    $yVal = [int]$Y
    $wVal = [int]$W
    $hVal = [int]$H
    $fill = New-Object System.Drawing.SolidBrush($FillColor)
    $stroke = New-Object System.Drawing.Pen($StrokeColor, 3)
    $rect = New-Object System.Drawing.Rectangle($xVal, $yVal, $wVal, $hVal)
    $G.FillEllipse($fill, $rect)
    $G.DrawEllipse($stroke, $rect)

    $textRect = New-Object System.Drawing.RectangleF([single]($xVal + 20), [single]($yVal + 20), [single]($wVal - 40), [single]($hVal - 40))
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
        [int]$Y2
    )

    $G.DrawLine($linePen, $X1, $Y1, $X2, $Y2)
}

$graphics.DrawString('SMNS Simple Data Flow Diagram', $titleFont, $textBrush, 40, 25)
$graphics.DrawString('Report-ready PNG export', $smallFont, $textBrush, 46, 68)

$actorColors = @{
    Student = @{ Fill = [System.Drawing.Color]::FromArgb(221, 239, 214); Stroke = [System.Drawing.Color]::FromArgb(130, 179, 102) }
    Lecturer = @{ Fill = [System.Drawing.Color]::FromArgb(255, 242, 204); Stroke = [System.Drawing.Color]::FromArgb(214, 182, 86) }
    Admin = @{ Fill = [System.Drawing.Color]::FromArgb(248, 206, 204); Stroke = [System.Drawing.Color]::FromArgb(184, 84, 80) }
    Finance = @{ Fill = [System.Drawing.Color]::FromArgb(225, 213, 231); Stroke = [System.Drawing.Color]::FromArgb(150, 115, 166) }
    Verifier = @{ Fill = [System.Drawing.Color]::FromArgb(218, 232, 252); Stroke = [System.Drawing.Color]::FromArgb(108, 142, 191) }
}

$actors = @(
    @{ Name = 'Student'; X = 70; Y = 150 },
    @{ Name = 'Lecturer'; X = 70; Y = 255 },
    @{ Name = 'Admin'; X = 70; Y = 360 },
    @{ Name = 'Finance'; X = 70; Y = 465 },
    @{ Name = 'Verifier'; X = 70; Y = 570 }
)

foreach ($actor in $actors) {
    $color = $actorColors[$actor.Name]
    Draw-RoundedBox -G $graphics -X $actor.X -Y $actor.Y -W 170 -H 60 -R 16 -FillColor $color.Fill -StrokeColor $color.Stroke -Text $actor.Name
}

$systemX = 520
$systemY = 300
$systemW = 300
$systemH = 160
Draw-EllipseNode -G $graphics -X $systemX -Y $systemY -W $systemW -H $systemH -FillColor ([System.Drawing.Color]::FromArgb(218, 232, 252)) -StrokeColor ([System.Drawing.Color]::FromArgb(108, 142, 191)) -Text "SMNS`nSystem"

$stores = @(
    @{ Name = 'Student Records'; X = 1120; Y = 120; Fill = [System.Drawing.Color]::FromArgb(221, 239, 214); Stroke = [System.Drawing.Color]::FromArgb(130, 179, 102) },
    @{ Name = 'Academic Records'; X = 1120; Y = 235; Fill = [System.Drawing.Color]::FromArgb(255, 242, 204); Stroke = [System.Drawing.Color]::FromArgb(214, 182, 86) },
    @{ Name = 'Results Records'; X = 1120; Y = 350; Fill = [System.Drawing.Color]::FromArgb(248, 206, 204); Stroke = [System.Drawing.Color]::FromArgb(184, 84, 80) },
    @{ Name = 'Finance Records'; X = 1120; Y = 465; Fill = [System.Drawing.Color]::FromArgb(225, 213, 231); Stroke = [System.Drawing.Color]::FromArgb(150, 115, 166) },
    @{ Name = 'Transcript Records'; X = 1120; Y = 580; Fill = [System.Drawing.Color]::FromArgb(218, 232, 252); Stroke = [System.Drawing.Color]::FromArgb(108, 142, 191) }
)

foreach ($store in $stores) {
    Draw-DataStore -G $graphics -X $store.X -Y $store.Y -W 250 -H 70 -FillColor $store.Fill -StrokeColor $store.Stroke -Text $store.Name
}

foreach ($actor in $actors) {
    Draw-Arrow -G $graphics -X1 ($actor.X + 170) -Y1 ($actor.Y + 30) -X2 $systemX -Y2 ($systemY + ($systemH / 2))
}

foreach ($store in $stores) {
    Draw-Arrow -G $graphics -X1 ($systemX + $systemW) -Y1 ($systemY + ($systemH / 2)) -X2 $store.X -Y2 ($store.Y + 35)
}

$legendY = 760
$graphics.DrawString('Legend:', $labelFont, $textBrush, 60, $legendY)
Draw-RoundedBox -G $graphics -X 170 -Y ($legendY - 5) -W 120 -H 40 -R 10 -FillColor ([System.Drawing.Color]::FromArgb(221, 239, 214)) -StrokeColor ([System.Drawing.Color]::FromArgb(130, 179, 102)) -Text 'External Entity'
Draw-EllipseNode -G $graphics -X 330 -Y ($legendY - 15) -W 120 -H 60 -FillColor ([System.Drawing.Color]::FromArgb(218, 232, 252)) -StrokeColor ([System.Drawing.Color]::FromArgb(108, 142, 191)) -Text 'Process'
Draw-DataStore -G $graphics -X 500 -Y ($legendY - 5) -W 150 -H 40 -FillColor ([System.Drawing.Color]::FromArgb(225, 213, 231)) -StrokeColor ([System.Drawing.Color]::FromArgb(150, 115, 166)) -Text 'Data Store'

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
