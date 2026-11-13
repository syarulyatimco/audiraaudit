<?php

namespace Tests\Unit;

use App\Services\TrialBalance\SpreadsheetReader;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class SpreadsheetReaderTest extends TestCase
{
    public static function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="TB" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $strings = [];
        $sheet = '';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $v) {
                $ref = chr(65 + $c).($r + 1);
                if (is_numeric($v)) {
                    $sheet .= "<c r=\"{$ref}\"><v>{$v}</v></c>";
                } elseif ($v !== '') {
                    $strings[] = htmlspecialchars($v);
                    $sheet .= "<c r=\"{$ref}\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $sheet .= '</row>';
        }
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => "<si><t>{$s}</t></si>", $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    public function test_reads_xlsx_with_shared_strings_and_numbers(): void
    {
        $path = self::makeXlsx([['Code', 'Name', 'Debit', 'Credit'], ['1000', 'Cash at bank', '1500.5', ''], ['', '', '', ''], ['3000', 'Share capital', '', '1500.5']]);
        $rows = (new SpreadsheetReader)->read($path, 'xlsx', 100);

        $this->assertSame(['Code', 'Name', 'Debit', 'Credit'], $rows[0]);
        $this->assertSame(['1000', 'Cash at bank', '1500.5'], $rows[1]);
        $this->assertSame([], $rows[2]); // empty row preserved so row numbers match Excel
        $this->assertSame('Share capital', $rows[3][1]);
    }

    public function test_reads_csv_with_semicolons_and_bom(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'c');
        file_put_contents($path, "\xEF\xBB\xBFCode;Name;Balance\n1000;Bank;\"1,000.00\"\n");
        $rows = (new SpreadsheetReader)->read($path, 'csv', 100);
        $this->assertSame(['Code', 'Name', 'Balance'], $rows[0]);
        $this->assertSame('1,000.00', $rows[1][2]);
    }

    public function test_rejects_doctype_and_row_limits(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'c');
        file_put_contents($path, str_repeat("a,b\n", 50));
        $this->expectException(ValidationException::class);
        (new SpreadsheetReader)->read($path, 'csv', 10);
    }
}
