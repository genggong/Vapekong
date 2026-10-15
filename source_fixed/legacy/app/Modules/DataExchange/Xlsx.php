<?php
require_once __DIR__ . '/SimpleZip.php';

class KpXlsx
{
    public static function download(string $filename, array $sheets): void
    {
        $data = self::build($sheets);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . self::safeFilename($filename, 'xlsx') . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $data;
        exit;
    }

    public static function build(array $sheets): string
    {
        if (!$sheets) {
            throw new InvalidArgumentException('Workbook harus memiliki minimal satu sheet.');
        }

        $zip = new KpSimpleZipWriter();
        $sheetXml = [];
        $sheetNames = [];
        $usedNames = [];

        foreach (array_values($sheets) as $i => $sheet) {
            $name = self::sheetName((string)($sheet['name'] ?? ('Sheet ' . ($i + 1))), $usedNames);
            $sheetNames[] = $name;
            $sheetXml[] = self::worksheetXml($sheet);
        }

        $zip->addFile('[Content_Types].xml', self::contentTypes(count($sheetXml)));
        $zip->addFile('_rels/.rels', self::rootRels());
        $zip->addFile('docProps/app.xml', self::appProps($sheetNames));
        $zip->addFile('docProps/core.xml', self::coreProps());
        $zip->addFile('xl/workbook.xml', self::workbookXml($sheetNames));
        $zip->addFile('xl/_rels/workbook.xml.rels', self::workbookRels(count($sheetXml)));
        $zip->addFile('xl/styles.xml', self::stylesXml());

        foreach ($sheetXml as $i => $xml) {
            $zip->addFile('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }

        return $zip->build();
    }

    public static function readFirstSheet(string $path, int $maxRows = 1001): array
    {
        $zip = new KpSimpleZipReader($path);
        $sheetPath = 'xl/worksheets/sheet1.xml';
        if (!$zip->has($sheetPath)) {
            foreach ($zip->names() as $name) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetPath = $name;
                    break;
                }
            }
        }
        $xml = $zip->get($sheetPath);
        if ($xml === null) {
            throw new RuntimeException('Sheet pertama Excel tidak ditemukan.');
        }

        $sharedStrings = [];
        $sharedXml = $zip->get('xl/sharedStrings.xml');
        if ($sharedXml !== null) {
            if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $sharedXml, $matches)) {
                foreach ($matches[1] as $content) {
                    $sharedStrings[] = self::extractTextTags($content);
                }
            }
        }

        $rows = [];
        if (!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $xml, $rowMatches)) {
            return [];
        }

        foreach ($rowMatches[1] as $rowContent) {
            if (count($rows) >= $maxRows) {
                break;
            }
            $row = [];
            if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowContent, $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cell) {
                    $attrs = $cell[1];
                    $content = $cell[2];
                    $col = count($row);
                    if (preg_match('/\br="([A-Z]+)\d+"/', $attrs, $ref)) {
                        $col = self::columnIndex($ref[1]);
                    }
                    $type = '';
                    if (preg_match('/\bt="([^"]+)"/', $attrs, $typeMatch)) {
                        $type = $typeMatch[1];
                    }

                    if ($type === 'inlineStr') {
                        $value = self::extractTextTags($content);
                    } elseif ($type === 's') {
                        $index = self::extractValue($content);
                        $value = $sharedStrings[(int)$index] ?? '';
                    } elseif ($type === 'b') {
                        $value = self::extractValue($content) === '1' ? '1' : '0';
                    } else {
                        $value = self::extractValue($content);
                    }
                    $row[$col] = $value;
                }
            }
            if ($row) {
                $maxCol = max(array_keys($row));
                $normalized = [];
                for ($i = 0; $i <= $maxCol; $i++) {
                    $normalized[] = $row[$i] ?? '';
                }
                $rows[] = $normalized;
            }
        }
        return $rows;
    }

    private static function worksheetXml(array $sheet): string
    {
        $rows = array_values($sheet['rows'] ?? []);
        $widths = array_values($sheet['widths'] ?? []);
        $freezeRows = max(0, (int)($sheet['freeze_rows'] ?? 0));
        $autoFilter = (bool)($sheet['auto_filter'] ?? false);
        $merges = array_values($sheet['merges'] ?? []);
        $maxCols = 1;
        foreach ($rows as $row) {
            $maxCols = max($maxCols, count($row));
        }
        $maxRows = max(1, count($rows));
        $dimension = 'A1:' . self::columnName($maxCols - 1) . $maxRows;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<dimension ref="' . $dimension . '"/>';
        $xml .= '<sheetViews><sheetView workbookViewId="0">';
        if ($freezeRows > 0) {
            $xml .= '<pane ySplit="' . $freezeRows . '" topLeftCell="A' . ($freezeRows + 1) . '" activePane="bottomLeft" state="frozen"/>';
        }
        $xml .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/>';

        if ($widths) {
            $xml .= '<cols>';
            foreach ($widths as $i => $width) {
                $width = max(6, min(60, (float)$width));
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($rows as $rIndex => $row) {
            $rowNumber = $rIndex + 1;
            $height = 18;
            foreach ($row as $candidate) {
                if (is_array($candidate) && isset($candidate['height'])) {
                    $height = (float)$candidate['height'];
                    break;
                }
            }
            $xml .= '<row r="' . $rowNumber . '" ht="' . $height . '" customHeight="1">';
            foreach (array_values($row) as $cIndex => $cell) {
                $ref = self::columnName($cIndex) . $rowNumber;
                $xml .= self::cellXml($ref, $cell);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($autoFilter && count($rows) > 1) {
            $headerRow = max(1, (int)($sheet['filter_row'] ?? 1));
            $xml .= '<autoFilter ref="A' . $headerRow . ':' . self::columnName($maxCols - 1) . $maxRows . '"/>';
        }
        if ($merges) {
            $xml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $merge) {
                $xml .= '<mergeCell ref="' . self::xml((string)$merge) . '"/>';
            }
            $xml .= '</mergeCells>';
        }
        $xml .= '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>';
        $xml .= '</worksheet>';
        return $xml;
    }

    private static function cellXml(string $ref, $cell): string
    {
        $value = $cell;
        $style = 9;
        $type = null;
        if (is_array($cell)) {
            $value = $cell['value'] ?? '';
            $style = (int)($cell['style'] ?? 9);
            $type = $cell['type'] ?? null;
        }

        if ($type === 'number') {
            $number = is_float($value) ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : (string)$value;
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . self::xml($number) . '</v></c>';
        }

        $text = (string)$value;
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml($text) . '</t></is></c>';
    }

    private static function contentTypes(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $xml . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(array $names): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews><sheets>';
        foreach ($names as $i => $name) {
            $xml .= '<sheet name="' . self::xml($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return $xml . '</sheets></workbook>';
    }

    private static function workbookRels(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $xml . '</Relationships>';
    }

    private static function appProps(array $names): string
    {
        $titles = '';
        foreach ($names as $name) {
            $titles .= '<vt:lpstr>' . self::xml($name) . '</vt:lpstr>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>KasirPro UMKM</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count($names) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($names) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private static function coreProps(): string
    {
        $date = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>KasirPro UMKM</dc:creator><cp:lastModifiedBy>KasirPro UMKM</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $date . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $date . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="[$Rp-421] #,##0"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="16"/><color rgb="FF7F1D1D"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDC2626"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFFECACA"/></left><right style="thin"><color rgb="FFFECACA"/></right><top style="thin"><color rgb="FFFECACA"/></top><bottom style="thin"><color rgb="FFFECACA"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="11">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function extractValue(string $content): string
    {
        if (preg_match('/<v\b[^>]*>(.*?)<\/v>/s', $content, $m)) {
            return self::unxml($m[1]);
        }
        return '';
    }

    private static function extractTextTags(string $content): string
    {
        $parts = [];
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $content, $matches)) {
            foreach ($matches[1] as $part) {
                $parts[] = self::unxml($part);
            }
        }
        return implode('', $parts);
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }

    private static function columnName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = intdiv($index - 1, 26);
        }
        return $name;
    }

    private static function sheetName(string $name, array &$used): string
    {
        $name = preg_replace('~[\\/?*\[\]:]~', ' ', trim($name));
        $name = trim((string)$name) ?: 'Sheet';
        $name = self::utf8Substr($name, 0, 31);
        $base = $name;
        $n = 2;
        while (isset($used[strtolower($name)])) {
            $suffix = ' ' . $n++;
            $name = self::utf8Substr($base, 0, 31 - strlen($suffix)) . $suffix;
        }
        $used[strtolower($name)] = true;
        return $name;
    }

    private static function safeFilename(string $filename, string $extension): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        $filename = trim((string)$filename, '.-');
        if ($filename === '') $filename = 'export';
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $extension) {
            $filename .= '.' . $extension;
        }
        return $filename;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function unxml(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function utf8Substr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }
        preg_match_all('/./us', $value, $chars);
        return implode('', array_slice($chars[0] ?? [], $start, $length));
    }
}
