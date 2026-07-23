<?php
declare(strict_types=1);

function xlsx_xml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_column(int $number): string
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function xlsx_cell(int $column, int $row, mixed $value, int $style = 0, bool $numeric = false): string
{
    $reference = xlsx_column($column) . $row;
    if ($numeric) return '<c r="' . $reference . '" s="' . $style . '"><v>' . (float) $value . '</v></c>';
    return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml($value) . '</t></is></c>';
}

function xlsx_row(int $number, array $cells, ?float $height = null): string
{
    return '<row r="' . $number . '"' . ($height ? ' ht="' . $height . '" customHeight="1"' : '') . '>' . implode('', $cells) . '</row>';
}

function xlsx_zip(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', (string) $name);
        $content = (string) $content;
        $compressed = gzdeflate($content, 6);
        $crc = crc32($content);
        $nameLength = strlen($name);
        $compressedLength = strlen($compressed);
        $contentLength = strlen($content);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, $compressedLength, $contentLength, $nameLength, 0)
            . $name . $compressed;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, $compressedLength, $contentLength,
            $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
        $data .= $local;
        $offset += strlen($local);
        $count++;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
}

function xlsx_package(string $sheet, string $sheetName = 'Reporte de asistencia'): string
{
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsx_xml($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FF10203D"/><sz val="16"/><name val="Arial"/></font><font><b/><color rgb="FF10203D"/><sz val="10"/><name val="Arial"/></font></fonts><fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF10203D"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEFF5FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEDFBF4"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF8E5"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFEFF1"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF4F7FC"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFD7E0EC"/></left><right style="thin"><color rgb="FFD7E0EC"/></right><top style="thin"><color rgb="FFD7E0EC"/></top><bottom style="thin"><color rgb="FFD7E0EC"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="10"><xf fontId="0" fillId="0" borderId="0"/><xf fontId="1" fillId="2" borderId="1" applyAlignment="1"><alignment vertical="center"/></xf><xf fontId="2" fillId="0" borderId="0"/><xf fontId="3" fillId="3" borderId="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf fontId="3" fillId="4" borderId="1"/><xf fontId="3" fillId="5" borderId="1"/><xf fontId="3" fillId="6" borderId="1"/><xf fontId="3" fillId="7" borderId="1"/><xf fontId="3" fillId="8" borderId="1"/><xf fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $styles = str_replace(
        [
            '<xf fontId="1" fillId="2" borderId="1"',
            '<xf fontId="3" fillId="3" borderId="1"',
            '<xf fontId="3" fillId="4" borderId="1"',
            '<xf fontId="3" fillId="5" borderId="1"',
            '<xf fontId="3" fillId="6" borderId="1"',
            '<xf fontId="3" fillId="7" borderId="1"',
            '<xf fontId="3" fillId="8" borderId="1"',
        ],
        [
            '<xf fontId="1" fillId="2" borderId="0"',
            '<xf fontId="3" fillId="0" borderId="1"',
            '<xf fontId="3" fillId="0" borderId="1"',
            '<xf fontId="3" fillId="0" borderId="1"',
            '<xf fontId="3" fillId="0" borderId="1"',
            '<xf fontId="3" fillId="0" borderId="1"',
            '<xf fontId="3" fillId="0" borderId="1"',
        ],
        $styles
    );
    return xlsx_zip(['[Content_Types].xml' => $types, '_rels/.rels' => $rels, 'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels, 'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet]);
}
