<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Writer;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText as PhpSpreadsheetRichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border as PhpSpreadsheetBorder;
use PhpOffice\PhpSpreadsheet\Style\Font as PhpSpreadsheetFont;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\CoversClass;
use SpreadsheetWriter\Exception\WriterException;
use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Style\HorizontalAlignment;
use SpreadsheetWriter\Style\Style;
use SpreadsheetWriter\Style\VerticalAlignment;
use SpreadsheetWriter\Workbook;
use SpreadsheetWriter\Writer\AbstractWriter;
use SpreadsheetWriter\Writer\Xlsx;

/**
 * Writes the sample workbook as XLSX and reads it back with PhpSpreadsheet.
 */
#[CoversClass(Xlsx::class)]
#[CoversClass(AbstractWriter::class)]
#[CoversClass(Xlsx\PackageXml::class)]
#[CoversClass(Xlsx\SheetXml::class)]
#[CoversClass(Xlsx\SharedStrings::class)]
#[CoversClass(Xlsx\StyleTable::class)]
final class XlsxTest extends WriterTestCase
{
    /**
     * the sample workbook read back by PhpSpreadsheet
     * @var Spreadsheet
     */
    private Spreadsheet $spreadsheet;

    /**
     * the first sheet of the sample workbook read back by PhpSpreadsheet
     * @var Worksheet
     */
    private Worksheet $sheet;

    /**
     * Write the sample workbook and read it back.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->spreadsheet = $this->readBack(new Xlsx(), SampleWorkbook::create(), new XlsxReader());
        $this->sheet = $this->spreadsheet->getSheet(0);
    }

    /**
     * Sheets keep their order and titles, the first one is active.
     *
     * @return void
     */
    public function testWritesSheets(): void
    {
        self::assertSame(['Worksheet'], $this->spreadsheet->getSheetNames());
        self::assertSame(0, $this->spreadsheet->getActiveSheetIndex());
    }

    /**
     * Text cells are strings, also numeric or formula-like ones.
     *
     * @return void
     */
    public function testWritesText(): void
    {
        $this->assertCell('1990', DataType::TYPE_STRING, 'A2');
        $this->assertCell('.', DataType::TYPE_STRING, 'D2');
        $this->assertCell('007', DataType::TYPE_STRING, 'A3');
        $this->assertCell('=SUM(A1:A2)', DataType::TYPE_STRING, 'B4');
        $this->assertCell('1234567890123456789', DataType::TYPE_STRING, 'C4');
    }

    /**
     * Line breaks are kept, invalid XML characters are removed and '_xHHHH_' is not decoded.
     *
     * @return void
     */
    public function testEscapesText(): void
    {
        $this->assertCell("Zeile1\nZeile2 _x0041_ \x01ctl", DataType::TYPE_STRING, 'A4');
        $strings = $this->readZipEntry((new Xlsx())->write(SampleWorkbook::create()), 'xl/sharedStrings.xml');
        self::assertStringContainsString('Zeile2 _x005F_x0041_ _x0001_ctl', $strings, 'The control character is written as Excel does.');
    }

    /**
     * Spaces and tabs are kept.
     *
     * @return void
     */
    public function testKeepsWhiteSpace(): void
    {
        self::assertSame('  two  spaces  ', $this->sheet->getCell('A8')->getValue());
        self::assertSame("tab\there \t x", $this->sheet->getCell('A9')->getValue());
        $richText = $this->sheet->getCell('A10')->getValue();
        self::assertInstanceOf(PhpSpreadsheetRichText::class, $richText);
        self::assertSame('a  b  ', $richText->getPlainText());
    }

    /**
     * Numbers and numeric strings are numbers, the decimals of numeric strings are shown.
     *
     * @return void
     */
    public function testWritesNumbers(): void
    {
        $this->assertCell(12.3, DataType::TYPE_NUMERIC, 'B2');
        $this->assertCell(-5, DataType::TYPE_NUMERIC, 'C2');
        $this->assertCell(1000, DataType::TYPE_NUMERIC, 'B3');
        $this->assertCell(42, DataType::TYPE_NUMERIC, 'C3');
        $this->assertCell(3.25, DataType::TYPE_NUMERIC, 'D3');
        self::assertSame('12.30', $this->sheet->getCell('B2')->getFormattedValue());
        self::assertSame('-5.0', $this->sheet->getCell('C2')->getFormattedValue());
        self::assertSame('1000', $this->sheet->getCell('B3')->getFormattedValue());
    }

    /**
     * Rich text keeps its runs, a run with a font doesn't inherit the bold cell font, as in PhpSpreadsheet.
     *
     * @return void
     */
    public function testWritesRichText(): void
    {
        $caption = $this->sheet->getCell('A1')->getValue();
        self::assertInstanceOf(PhpSpreadsheetRichText::class, $caption);
        self::assertSame('Einheit: 1000 m³ <&> "q"', $caption->getPlainText());
        $font = $caption->getRichTextElements()[0]->getFont();
        self::assertInstanceOf(PhpSpreadsheetFont::class, $font);
        self::assertSame('006268', $font->getColor()->getRGB());
        self::assertFalse($font->getBold(), 'The colored run does not inherit the bold cell font.');
        self::assertNull($caption->getRichTextElements()[1]->getFont(), 'The run without a font uses the bold cell font.');
        self::assertTrue($this->sheet->getStyle('A1')->getFont()->getBold());

        $plainCellCaption = $this->sheet->getCell('B7')->getValue();
        self::assertInstanceOf(PhpSpreadsheetRichText::class, $plainCellCaption);
        $font = $plainCellCaption->getRichTextElements()[0]->getFont();
        self::assertInstanceOf(PhpSpreadsheetFont::class, $font);
        self::assertSame('006268', $font->getColor()->getRGB());
        self::assertFalse($font->getBold(), 'The same rich text in a plain cell is not bold.');
    }

    /**
     * A run whose font sets no property is not bold and black in a bold, colored cell, as in PhpSpreadsheet.
     * A run without a font uses the cell font.
     *
     * @return void
     */
    public function testWritesRunsWithEmptyFont(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setString(1, 1, (new RichText())->addText('own font', new Font())->addText(' cell font'));
        $sheet->setStyle(1, 1, 1, 1, new Style(font: new Font(bold: true, color: 'AAAAAA')));
        $value = $this->readBack(new Xlsx(), $workbook, new XlsxReader())->getSheet(0)->getCell('A1')->getValue();

        self::assertInstanceOf(PhpSpreadsheetRichText::class, $value);
        $font = $value->getRichTextElements()[0]->getFont();
        self::assertInstanceOf(PhpSpreadsheetFont::class, $font);
        self::assertFalse($font->getBold());
        self::assertSame('000000', $font->getColor()->getRGB());
        self::assertNull($value->getRichTextElements()[1]->getFont());
    }

    /**
     * Merged ranges are written.
     *
     * @return void
     */
    public function testWritesMergedCells(): void
    {
        self::assertSame(['A1:D1', 'B8:C9', 'D8:D9'], array_values($this->sheet->getMergeCells()));
    }

    /**
     * Layered styles are combined per cell, styles of empty cells are written.
     *
     * @return void
     */
    public function testWritesStyles(): void
    {
        self::assertTrue($this->sheet->getStyle('A1')->getFont()->getBold());
        self::assertSame(Alignment::VERTICAL_TOP, $this->sheet->getStyle('A1')->getAlignment()->getVertical());

        $b2 = $this->sheet->getStyle('B2');
        self::assertSame(Alignment::HORIZONTAL_RIGHT, $b2->getAlignment()->getHorizontal());
        self::assertSame(PhpSpreadsheetBorder::BORDER_THIN, $b2->getBorders()->getLeft()->getBorderStyle());

        $b4 = $this->sheet->getStyle('B4');
        self::assertTrue($b4->getAlignment()->getWrapText());
        self::assertSame(Alignment::VERTICAL_TOP, $b4->getAlignment()->getVertical());
        self::assertSame(Alignment::HORIZONTAL_RIGHT, $b4->getAlignment()->getHorizontal());
        self::assertSame(PhpSpreadsheetBorder::BORDER_THIN, $b4->getBorders()->getBottom()->getBorderStyle());

        $a5 = $this->sheet->getStyle('A5')->getBorders();
        foreach ([$a5->getLeft(), $a5->getRight(), $a5->getTop(), $a5->getBottom()] as $edge) {
            self::assertSame(PhpSpreadsheetBorder::BORDER_THIN, $edge->getBorderStyle());
            self::assertSame('AAAAAA', $edge->getColor()->getRGB());
        }
        self::assertSame(PhpSpreadsheetBorder::BORDER_NONE, $this->sheet->getStyle('E5')->getBorders()->getLeft()->getBorderStyle());
    }

    /**
     * Row heights, column widths and gridlines are written.
     *
     * @return void
     */
    public function testWritesDimensionsAndGridlines(): void
    {
        self::assertEqualsWithDelta(24.0, $this->sheet->getRowDimension(1)->getRowHeight(), 0.001);
        self::assertEqualsWithDelta(20.0, $this->sheet->getColumnDimension('A')->getWidth(), 0.001);
        self::assertEqualsWithDelta(12.5, $this->sheet->getColumnDimension('C')->getWidth(), 0.001);
        self::assertFalse($this->sheet->getShowGridlines());
    }

    /**
     * Cells after empty rows and columns end up at the right coordinate.
     *
     * @return void
     */
    public function testWritesCellsAfterGaps(): void
    {
        self::assertSame(1, $this->sheet->getCell('E12')->getValue());
        self::assertSame('second', $this->sheet->getCell('A7')->getValue());
    }

    /**
     * Vertical centering, explicit "not bold" and "not wrapped", heights of empty rows and empty cell fonts.
     *
     * @return void
     */
    public function testWritesRemainingStyleOptions(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setValue(1, 1, 'centered');
        $sheet->setStyle(1, 1, 1, 1, new Style(font: new Font(bold: false), vertical: VerticalAlignment::Center, textWrapped: false));
        $sheet->setRowHeight(3, 30);
        $richText = (new RichText())->addText('a', new Font(color: 'FF0000'));
        $sheet->setString(1, 2, $richText);
        $sheet->setString(2, 2, $richText);
        $sheet->setStyle(1, 2, 1, 2, new Style(font: new Font(), horizontal: HorizontalAlignment::Left));
        $binary = (new Xlsx())->write($workbook);
        $path = $this->createTempPath('xlsx');
        file_put_contents($path, $binary);
        $readSheet = (new XlsxReader())->load($path)->getSheet(0);

        $a1 = $readSheet->getStyle('A1');
        self::assertSame(Alignment::VERTICAL_CENTER, $a1->getAlignment()->getVertical());
        self::assertFalse($a1->getAlignment()->getWrapText());
        self::assertFalse($a1->getFont()->getBold());
        self::assertEqualsWithDelta(30.0, $readSheet->getRowDimension(3)->getRowHeight(), 0.001);
        self::assertStringContainsString(
            'uniqueCount="2"',
            $this->readZipEntry($binary, 'xl/sharedStrings.xml'),
            'The same rich text is one shared string, whatever the cell font is.'
        );
    }

    /**
     * The package contains the expected parts.
     *
     * @return void
     */
    public function testWritesPackageParts(): void
    {
        $entries = $this->listZipEntries((new Xlsx())->write(SampleWorkbook::create()));
        self::assertSame([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/sharedStrings.xml',
            'xl/worksheets/sheet1.xml',
        ], $entries);
    }

    /**
     * An empty workbook can be read back.
     *
     * @return void
     */
    public function testWritesEmptyWorkbook(): void
    {
        $spreadsheet = $this->readBack(new Xlsx(), new Workbook(), new XlsxReader());
        self::assertSame(['Worksheet'], $spreadsheet->getSheetNames());
        self::assertSame('A1', $spreadsheet->getSheet(0)->getHighestColumn().$spreadsheet->getSheet(0)->getHighestRow());
        self::assertNull($spreadsheet->getSheet(0)->getCell('A1')->getValue());
        self::assertTrue($spreadsheet->getSheet(0)->getShowGridlines(), 'The gridlines are shown by default.');
    }

    /**
     * Cells at the sheet limits can be written.
     *
     * @return void
     */
    public function testWritesLastCell(): void
    {
        $workbook = new Workbook();
        $workbook->getActiveSheet()->setValue(16384, 1048576, 'last');
        $spreadsheet = $this->readBack(new Xlsx(), $workbook, new XlsxReader());
        self::assertSame('last', $spreadsheet->getSheet(0)->getCell('XFD1048576')->getValue());
    }

    /**
     * An empty string is written like an empty cell, as PhpSpreadsheet does, and keeps its style.
     *
     * @return void
     */
    public function testWritesEmptyStringsAsEmptyCells(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setValue(1, 1, '');
        $sheet->setString(2, 1, '');
        $sheet->setStyle(2, 1, 2, 1, new Style(font: new Font(bold: true)));
        $sheet->setValue(3, 1, 'x');
        $binary = (new Xlsx())->write($workbook);
        $path = $this->createTempPath('xlsx');
        file_put_contents($path, $binary);
        $readSheet = (new XlsxReader())->load($path)->getSheet(0);

        self::assertNull($readSheet->getCell('A1')->getValue());
        self::assertNull($readSheet->getCell('B1')->getValue());
        self::assertTrue($readSheet->getStyle('B1')->getFont()->getBold(), 'The style of the empty string cell is kept.');
        self::assertStringContainsString('uniqueCount="1"', $this->readZipEntry($binary, 'xl/sharedStrings.xml'));
    }

    /**
     * A cell cleared with null leaves nothing behind in the file.
     *
     * @return void
     */
    public function testWritesClearedCells(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setValue(1, 1, 'kept');
        $sheet->setValue(2, 5, 'cleared');
        $sheet->setValue(2, 5, null);
        $spreadsheet = $this->readBack(new Xlsx(), $workbook, new XlsxReader());
        self::assertSame('kept', $spreadsheet->getSheet(0)->getCell('A1')->getValue());
        self::assertSame(1, $spreadsheet->getSheet(0)->getHighestDataRow());
    }

    /**
     * The same timestamp gives the same file.
     *
     * @return void
     */
    public function testWritesReproducibleOutput(): void
    {
        $binary = (new Xlsx(1700000000))->write(SampleWorkbook::create());

        self::assertSame($binary, (new Xlsx(1700000000))->write(SampleWorkbook::create()));
        self::assertSame(1700000000, $this->readZipEntryTime($binary, 'xl/workbook.xml'), 'The timestamp is the modification time of the parts.');
        self::assertNotSame($binary, (new Xlsx(1600000000))->write(SampleWorkbook::create()));
    }

    /**
     * Rows, cells and columns are written in ascending order, whatever the order of the calls is.
     * Excel reports a file with unordered rows or cells as damaged.
     *
     * @return void
     */
    public function testWritesSortedSheetData(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setValue(4, 5, 1);
        $sheet->setValue(2, 5, 2);
        $sheet->setValue(3, 2, 3);
        $sheet->setRowHeight(3, 20);
        $sheet->setColumnWidth(4, 10);
        $sheet->setColumnWidth(2, 12);
        $sheet->mergeCells(2, 6, 3, 6);
        $sheet->mergeCells(2, 7, 3, 7);
        $xml = $this->readZipEntry((new Xlsx())->write($workbook), 'xl/worksheets/sheet1.xml');

        preg_match_all('/<row r="(\d+)"/', $xml, $rows);
        self::assertSame(['2', '3', '5'], $rows[1]);
        preg_match_all('/<c r="([A-Z]+\d+)"/', $xml, $cells);
        self::assertSame(['C2', 'B5', 'D5'], $cells[1]);
        preg_match_all('/<col min="(\d+)"/', $xml, $columns);
        self::assertSame(['2', '4'], $columns[1]);
        self::assertStringContainsString('<dimension ref="B2:D5"/>', $xml, 'The dimension starts at the first used column, not at A.');
        self::assertStringContainsString('<mergeCells count="2">', $xml);
    }

    /**
     * The shared strings count every reference, and a plain text and a rich text with the same content are two items.
     *
     * @return void
     */
    public function testWritesSharedStrings(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setString(1, 1, 'same');
        $sheet->setString(1, 2, 'same');
        $sheet->setString(1, 3, (new RichText())->addText('same'));
        $sheet->setString(1, 4, (new RichText())->addText('same', new Font()));
        $binary = (new Xlsx())->write($workbook);
        $xml = $this->readZipEntry($binary, 'xl/sharedStrings.xml');
        $path = $this->createTempPath('xlsx');
        file_put_contents($path, $binary);
        $readSheet = (new XlsxReader())->load($path)->getSheet(0);

        self::assertStringContainsString('count="4" uniqueCount="3"', $xml);
        self::assertSame('same', $readSheet->getCell('A2')->getValue());
        self::assertInstanceOf(PhpSpreadsheetRichText::class, $readSheet->getCell('A3')->getValue());
        self::assertSame(1, substr_count($xml, '<rPr>'), 'Only the run with a font has run properties.');
        self::assertStringContainsString('<color rgb="FF000000"/>', $xml, 'A run with a font is black if the font has no color.');
    }

    /**
     * Centered and bottom aligned cells, and cell fonts with a color.
     *
     * @return void
     */
    public function testWritesAlignmentsAndFontColors(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setValue(1, 1, 'a');
        $sheet->setValue(2, 1, 'b');
        $sheet->setValue(3, 1, 'c');
        $sheet->setStyle(1, 1, 1, 1, new Style(font: new Font(color: '006268'), horizontal: HorizontalAlignment::Center, vertical: VerticalAlignment::Bottom));
        $sheet->setStyle(2, 1, 2, 1, new Style(font: new Font(color: 'FF0000')));
        $sheet->setStyle(3, 1, 3, 1, new Style(font: new Font(bold: true, color: 'FF0000')));
        $readSheet = $this->readBack(new Xlsx(), $workbook, new XlsxReader())->getSheet(0);

        self::assertSame(Alignment::HORIZONTAL_CENTER, $readSheet->getStyle('A1')->getAlignment()->getHorizontal());
        self::assertSame(Alignment::VERTICAL_BOTTOM, $readSheet->getStyle('A1')->getAlignment()->getVertical());
        self::assertSame('006268', $readSheet->getStyle('A1')->getFont()->getColor()->getRGB());
        self::assertSame('FF0000', $readSheet->getStyle('B1')->getFont()->getColor()->getRGB());
        self::assertFalse($readSheet->getStyle('B1')->getFont()->getBold());
        self::assertSame('FF0000', $readSheet->getStyle('C1')->getFont()->getColor()->getRGB());
        self::assertTrue($readSheet->getStyle('C1')->getFont()->getBold());
    }

    /**
     * Saving to a path that can't be written throws.
     *
     * @return void
     */
    public function testThrowsIfFileCannotBeSaved(): void
    {
        $this->expectException(WriterException::class);
        (new Xlsx())->save(new Workbook(), '/nonexistent/directory/file.xlsx');
    }

    /**
     * MIME type and file extension.
     *
     * @return void
     */
    public function testReturnsFormatInfo(): void
    {
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (new Xlsx())->getMimeType());
        self::assertSame('xlsx', (new Xlsx())->getFileExtension());
    }

    /**
     * Assert the value and data type of a cell of the first sheet.
     *
     * @param int|float|string $expected expected value, numbers are compared by value
     * @param string $dataType expected PhpSpreadsheet data type
     * @param string $reference cell reference, e.g. 'B2'
     *
     * @return void
     */
    private function assertCell(int|float|string $expected, string $dataType, string $reference): void
    {
        $cell = $this->sheet->getCell($reference);
        self::assertSame($dataType, $cell->getDataType(), "Data type of $reference");
        if (is_string($expected)) {
            self::assertSame($expected, $cell->getValue(), "Value of $reference");
        } else {
            self::assertEqualsWithDelta($expected, $cell->getValue(), 1e-9, "Value of $reference");
        }
    }
}
