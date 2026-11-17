<?php

declare(strict_types=1);

namespace DynaPulse\Service;

use RuntimeException;
use ZipArchive;

/**
 * Small, dependency free XLSX writer. Produces a valid OOXML workbook with a
 * styled header row, frozen panes and sensible column widths — enough for
 * call/alerts exports without pulling in PhpSpreadsheet.
 */
final class XlsxWriter
{
    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     * @param list<int|float> $widths
     */
    public static function build(string $sheetName, array $headers, array $rows, array $widths = []): string
    {
        if (!self::available()) {
            throw new RuntimeException('The PHP zip extension is required for XLSX exports. Use CSV instead.');
        }

        $sheetName = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName) ?: 'Sheet1', 0, 31);
        $sheetXml = self::sheet($headers, $rows, $widths);

        $files = [
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::rootRels(),
            'docProps/app.xml' => self::appProps(),
            'docProps/core.xml' => self::coreProps(),
            'xl/workbook.xml' => self::workbook($sheetName),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ];

        $path = tempnam(sys_get_temp_dir(), 'dpxlsx');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file for the XLSX export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Cannot create the XLSX archive.');
        }

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    /** @param list<string> $headers @param list<list<string|int|float|null>> $rows @param list<int|float> $widths */
    private static function sheet(array $headers, array $rows, array $widths): string
    {
        $cols = '';
        if ($widths !== []) {
            $cols .= '<cols>';
            foreach ($widths as $index => $width) {
                $cols .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $index + 1, $index + 1, (string) $width);
            }
            $cols .= '</cols>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="16"/>'
            . $cols
            . '<sheetData>';

        $xml .= '<row r="1">';
        foreach ($headers as $index => $header) {
            $xml .= self::cell(self::column($index) . '1', $header, 1);
        }
        $xml .= '</row>';

        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 2;
            $xml .= '<row r="' . $number . '">';
            foreach (array_values($row) as $colIndex => $value) {
                $xml .= self::cell(self::column($colIndex) . $number, $value, 0);
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData><autoFilter ref="A1:' . self::column(max(0, count($headers) - 1)) . max(1, count($rows) + 1) . '"/></worksheet>';

        return $xml;
    }

    private static function cell(string $reference, mixed $value, int $style): string
    {
        if ($value === null || $value === '') {
            return sprintf('<c r="%s" s="%d"/>', $reference, $style);
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $style, (string) $value);
        }

        $text = self::clean((string) $value);

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $reference,
            $style,
            htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8'),
        );
    }

    private static function clean(string $value): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return mb_substr($value, 0, 32000);
    }

    private static function column(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }

        return $letter;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
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

    private static function workbook(string $sheetName): string
    {
        $name = htmlspecialchars($sheetName, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<calcPr calcId="191029"/></workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>DynaPulse</Application></Properties>';
    }

    private static function coreProps(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>DynaPulse</dc:creator><cp:lastModifiedBy>DynaPulse</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }
}
