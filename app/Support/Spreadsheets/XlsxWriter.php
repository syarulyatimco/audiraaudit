<?php

namespace App\Support\Spreadsheets;

use RuntimeException;
use ZipArchive;

/**
 * Minimal, dependency-free XLSX writer (Office Open XML SpreadsheetML).
 * Writes one worksheet of text cells with a bold, frozen header row, wrapped text, column
 * widths and optional hidden columns. Values are written as inline strings, so nothing a
 * user typed is ever interpreted as a formula when the file is opened.
 */
class XlsxWriter
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|null>>  $rows
     * @param  list<float>  $widths  column widths in Excel character units
     * @param  list<int>  $hidden  zero-based indexes of hidden columns
     * @param  list<string>  $title  optional lines written above the header (e.g. client, engagement)
     */
    public function write(string $path, string $sheetName, array $headers, array $rows, array $widths = [], array $hidden = [], array $title = []): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the workbook.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($headers, $rows, $widths, $hidden, $title));
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>AUDIRA</Application></Properties>');
        $zip->close();
    }

    public static function column(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)).$s;
        }

        return $s;
    }

    private function sheet(array $headers, array $rows, array $widths, array $hidden, array $title): string
    {
        $cols = '';
        foreach ($headers as $i => $_) {
            $w = $widths[$i] ?? 18;
            $h = in_array($i, $hidden, true) ? ' hidden="1"' : '';
            $cols .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"%s/>', $i + 1, $i + 1, $w, $h);
        }

        $data = '';
        $r = 0;
        foreach ($title as $line) {
            $r++;
            $data .= "<row r=\"{$r}\">".$this->cell(0, $r, $line, 3).'</row>';
        }
        if ($title) {
            $r++; // blank spacer row
        }
        $headerRow = ++$r;
        $data .= "<row r=\"{$r}\">";
        foreach ($headers as $i => $h) {
            $data .= $this->cell($i, $r, $h, 1);
        }
        $data .= '</row>';
        foreach ($rows as $row) {
            $r++;
            $data .= "<row r=\"{$r}\">";
            foreach (array_values($row) as $i => $v) {
                if ($v !== null && $v !== '') {
                    $data .= $this->cell($i, $r, (string) $v, 2);
                }
            }
            $data .= '</row>';
        }
        $last = self::column(max(0, count($headers) - 1));
        $pane = sprintf('<sheetViews><sheetView workbookViewId="0"><pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>', $headerRow, $headerRow + 1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$pane.'<sheetFormatPr defaultRowHeight="15"/><cols>'.$cols.'</cols><sheetData>'.$data.'</sheetData>'
            .sprintf('<autoFilter ref="A%d:%s%d"/>', $headerRow, $last, max($headerRow, $r))
            .'</worksheet>';
    }

    private function cell(int $col, int $row, string $value, int $style): string
    {
        // Strip characters XML 1.0 cannot carry.
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
        $value = mb_substr($value, 0, 32767);

        return sprintf('<c r="%s%d" t="inlineStr" s="%d"><is><t xml:space="preserve">%s</t></is></c>', self::column($col), $row, $style, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function workbook(string $name): string
    {
        $name = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $name) ?: 'Sheet1', 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function styles(): string
    {
        // 0 default · 1 header (bold, navy fill, white) · 2 body (wrap, top) · 3 title (bold)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E2F5C"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD4DAE6"/></left><right style="thin"><color rgb="FFD4DAE6"/></right><top style="thin"><color rgb="FFD4DAE6"/></top><bottom style="thin"><color rgb="FFD4DAE6"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
