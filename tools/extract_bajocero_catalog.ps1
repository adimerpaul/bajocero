param(
    [Parameter(Mandatory = $true)]
    [string] $WorkbookPath,
    [Parameter(Mandatory = $true)]
    [string] $OutputPath
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$archive = [System.IO.Compression.ZipFile]::OpenRead($WorkbookPath)

function Read-ArchiveText([string] $entryName) {
    $entry = $archive.GetEntry($entryName)
    if (-not $entry) {
        throw "No se encontró $entryName en el libro."
    }

    $reader = [System.IO.StreamReader]::new($entry.Open())
    try {
        return $reader.ReadToEnd()
    }
    finally {
        $reader.Dispose()
    }
}

try {
    [xml] $sharedXml = Read-ArchiveText 'xl/sharedStrings.xml'
    $sharedStrings = @()
    foreach ($item in $sharedXml.sst.si) {
        $sharedStrings += $item.InnerText
    }

    [xml] $sheetXml = Read-ArchiveText 'xl/worksheets/sheet2.xml'

    function Get-CellValue($cell) {
        if ($cell.t -eq 's') {
            return $sharedStrings[[int] $cell.v]
        }
        if ($cell.t -eq 'inlineStr') {
            return $cell.is.InnerText
        }
        return [string] $cell.v
    }

    $productsByBarcode = [ordered]@{}
    foreach ($row in ($sheetXml.worksheet.sheetData.row | Select-Object -Skip 1)) {
        $cells = @{}
        foreach ($cell in $row.c) {
            $column = $cell.r -replace '\d', ''
            $cells[$column] = Get-CellValue $cell
        }

        $barcode = ([string] $cells.C).Trim()
        if (-not $barcode -or $productsByBarcode.Contains($barcode)) {
            continue
        }

        $category = ([string] $cells.E).Trim().ToUpperInvariant()
        $category = switch ($category) {
            'CONDIMENT' { 'CONDIMENTOS' }
            'PESCADO' { 'PESCADOS' }
            default { $category }
        }

        $productsByBarcode[$barcode] = [ordered]@{
            codigo         = $barcode
            codigo_barras  = $barcode
            nombre         = ([string] $cells.F).Trim().ToUpperInvariant()
            categoria      = $category
            unidad         = ([string] $cells.J).Trim().ToUpperInvariant()
            precio_compra  = [math]::Round([decimal] $cells.H, 2)
            precio_venta   = [math]::Round([decimal] $cells.I, 2)
            stock_inicial  = [int] [decimal] $cells.G
        }
    }

    $payload = [ordered]@{
        source = [System.IO.Path]::GetFileName($WorkbookPath)
        imported_at = '2026-07-28'
        products = @($productsByBarcode.Values)
    }

    $parent = Split-Path -Parent $OutputPath
    if ($parent) {
        [System.IO.Directory]::CreateDirectory($parent) | Out-Null
    }
    $json = $payload | ConvertTo-Json -Depth 5
    [System.IO.File]::WriteAllText($OutputPath, $json, [System.Text.UTF8Encoding]::new($false))

    Write-Output "Catálogo generado: $($productsByBarcode.Count) productos únicos."
}
finally {
    $archive.Dispose()
}
