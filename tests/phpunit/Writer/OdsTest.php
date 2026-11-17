<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Writer;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Ods as OdsReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border as PhpSpreadsheetBorder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\CoversClass;
use SpreadsheetWriter\Exception\WriterException;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Style\HorizontalAlignment;
use SpreadsheetWriter\Style\Style;
use SpreadsheetWriter\Style\VerticalAlignment;
use SpreadsheetWriter\Workbook;
use SpreadsheetWriter\Writer\AbstractWriter;
use SpreadsheetWriter\Writer\Ods;

/**
 * Writes the sample workbook as ODS and checks it.
 *
 * PhpSpreadsheet's ODS reader checks values, merges, alignment, borders and column widths.
 * It ignores row heights, gridlines, tabs, rich text runs, number formats with decimals
 * and the styles of empty cells, so these are checked in the XML.
 */
#[CoversClass(Ods::class)]
#[CoversClass(AbstractWriter::class)]
#[CoversClass(Ods\PackageXml::class)]
#[CoversClass(Ods\SheetXml::class)]
#[CoversClass(Ods\StyleTable::class)]
#[CoversClass(Ods\TextXml::class)]
final class OdsTest extends WriterTestCase
{
    /**
     * the sample workbook as ODS file
     * @var string
     */
    private string $binary;

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
     * XPath of content.xml of the sample workbook
     * @var DOMXPath
     */
    private DOMXPath $content;

    /**
     * Write the sample workbook, read it back and parse content.xml.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->binary = (new Ods())->write(SampleWorkbook::create());
        $path = $this->createTempPath('ods');
        file_put_contents($path, $this->binary);
        $this->spreadsheet = (new OdsReader())->load($path);
        $this->sheet = $this->spreadsheet->getSheet(0);
        $this->content = $this->parsePart('content.xml');
    }

    /**
     * Sheets keep their order and titles, the first one is active.
     *
     * @return void
     */
    public function testWritesSheets(): void
    {
        self::assertSame(['Worksheet'], $this->spreadsheet->getSheetNames());
        self::assertSame('Worksheet', $this->parsePart('settings.xml')->evaluate('string(//config:config-item[@config:name="ActiveTable"])'));
    }

    /**
     * The table starts with an empty <office:forms/>, otherwise LibreOffice cuts off the last line of some wrapped rows.
     *
     * @return void
     */
    public function testWritesFormsElement(): void
    {
        $forms = $this->parsePart('content.xml')->query('//table:table/*[1]');
        self::assertSame(1, $forms->length);
        self::assertSame('office:forms', $forms->item(0)?->nodeName);
        self::assertFalse($forms->item(0)->hasChildNodes());
    }

    /**
     * Text cells are strings, also numeric or formula-like ones; line breaks are kept and invalid XML characters removed.
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
        $this->assertCell("Zeile1\nZeile2 _x0041_ ctl", DataType::TYPE_STRING, 'A4');
        self::assertSame(2, $this->content->query('text:p', $this->findCell(1, 4))->length, 'Each line is a paragraph.');
    }

    /**
     * Spaces and tabs are kept.
     *
     * @return void
     */
    public function testKeepsWhiteSpace(): void
    {
        self::assertSame('  two  spaces  ', $this->sheet->getCell('A8')->getValue());
        self::assertSame('a  b  ', $this->getPlainText($this->sheet, 'A10'));
        self::assertSame(2, $this->content->query('.//text:tab', $this->findCell(1, 9))->length);
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
        self::assertSame('2', $this->getDecimalPlaces($this->findCell(2, 2)));
        self::assertSame('1', $this->getDecimalPlaces($this->findCell(3, 2)));
        self::assertNull($this->getDecimalPlaces($this->findCell(2, 3)), '1e3 has no decimals to keep.');
        self::assertSame('12.30', $this->findCell(2, 2)->textContent, 'The paragraph holds the displayed value.');
        self::assertSame('1000', $this->findCell(2, 3)->textContent, 'A whole float is displayed without decimals.');
        self::assertSame('1000.0', $this->findCell(2, 3)->getAttribute('office:value'));
    }

    /**
     * Rich text runs with a font become spans, which don't inherit the bold font of the cell.
     *
     * @return void
     */
    public function testWritesRichText(): void
    {
        self::assertSame('Einheit: 1000 m³ <&> "q"', $this->getPlainText($this->sheet, 'A1'));
        $a1 = $this->findCell(1, 1);
        $spans = $this->content->query('text:p/text:span', $a1);
        self::assertSame(1, $spans->length);
        $span = $spans->item(0);
        self::assertInstanceOf(DOMElement::class, $span);
        self::assertSame('Einheit', $span->textContent);
        $textProperties = $this->getStyleProperties($span->getAttribute('text:style-name'), 'text-properties');
        self::assertSame('#006268', $textProperties?->getAttribute('fo:color'));
        self::assertSame('normal', $textProperties->getAttribute('fo:font-weight'), 'The span does not inherit the weight of the cell.');
        self::assertSame('bold', $this->getStyleProperties($a1->getAttribute('table:style-name'), 'text-properties')?->getAttribute('fo:font-weight'));
        self::assertFalse($this->findCell(2, 7)->hasAttribute('table:style-name'), 'The caption in B7 is in a plain cell.');
    }

    /**
     * Merged ranges are written as spanned and covered cells.
     *
     * @return void
     */
    public function testWritesMergedCells(): void
    {
        self::assertSame(['A1:D1', 'B8:C9', 'D8:D9'], array_values($this->sheet->getMergeCells()));
        $a1 = $this->findCell(1, 1);
        self::assertSame('4', $a1->getAttribute('table:number-columns-spanned'));
        self::assertSame('1', $a1->getAttribute('table:number-rows-spanned'));
        self::assertSame('table:covered-table-cell', $this->findCell(4, 1)->nodeName);
        self::assertSame('table:covered-table-cell', $this->findCell(3, 9)->nodeName);
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
        self::assertSame('AAAAAA', $b2->getBorders()->getLeft()->getColor()->getRGB());

        $b4 = $this->sheet->getStyle('B4');
        self::assertTrue($b4->getAlignment()->getWrapText());
        self::assertSame(Alignment::VERTICAL_TOP, $b4->getAlignment()->getVertical());
        self::assertSame(Alignment::HORIZONTAL_RIGHT, $b4->getAlignment()->getHorizontal());
        self::assertSame(PhpSpreadsheetBorder::BORDER_THIN, $b4->getBorders()->getBottom()->getBorderStyle());

        $a5 = $this->getStyleProperties($this->findCell(1, 5)->getAttribute('table:style-name'), 'table-cell-properties');
        foreach (['left', 'right', 'top', 'bottom'] as $edge) {
            self::assertSame('0.75pt solid #AAAAAA', $a5?->getAttribute('fo:border-'.$edge), "Border $edge of the empty cell A5");
        }
        self::assertNull($this->findCellOrNull(5, 5), 'Row 5 ends after the styled cell D5.');
    }

    /**
     * Row heights, column widths and gridlines are written.
     *
     * @return void
     */
    public function testWritesDimensionsAndGridlines(): void
    {
        $row = $this->content->query('//table:table[1]/table:table-row[1]')->item(0);
        self::assertInstanceOf(DOMElement::class, $row);
        self::assertSame('24.0pt', $this->getStyleProperties($row->getAttribute('table:style-name'), 'table-row-properties')?->getAttribute('style:row-height'));
        // the width is rounded to whole pixels, and PhpSpreadsheet's reader may truncate it by one more pixel
        // (66pt × 96/72 = 87.99999… → 87 px), so the tolerance is one pixel (1/7 character)
        self::assertSame('105.0pt', $this->getStyleProperties($this->getColumnStyleName(1), 'table-column-properties')?->getAttribute('style:column-width'));
        self::assertSame('66.0pt', $this->getStyleProperties($this->getColumnStyleName(3), 'table-column-properties')?->getAttribute('style:column-width'));
        self::assertEqualsWithDelta(20.0, $this->sheet->getColumnDimension('A')->getWidth(), 0.15);
        self::assertEqualsWithDelta(12.5, $this->sheet->getColumnDimension('C')->getWidth(), 0.15);

        $settings = $this->parsePart('settings.xml');
        $showGrid = 'string(//config:config-item-map-named[@config:name="Tables"]'
            .'/config:config-item-map-entry[@config:name="%s"]/config:config-item[@config:name="ShowGrid"])';
        self::assertSame('false', $settings->evaluate(sprintf($showGrid, 'Worksheet')));
        self::assertSame('false', $settings->evaluate('string(//config:config-item-set[@config:name="ooo:configuration-settings"]/config:config-item[@config:name="ShowGrid"])'));
        // LibreOffice ignores the settings named 'ooo:…' when the namespace of the prefix is not declared
        self::assertSame('http://openoffice.org/2004/office', $settings->document->documentElement?->lookupNamespaceURI('ooo'));
    }

    /**
     * Cells after empty rows and columns end up at the right coordinate.
     *
     * @return void
     */
    public function testWritesCellsAfterGaps(): void
    {
        self::assertEquals(1, $this->sheet->getCell('E12')->getValue());
        self::assertSame('second', $this->sheet->getCell('A7')->getValue());
    }

    /**
     * Vertical centering, explicit "not bold" and "not wrapped" and heights of empty rows.
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
        $this->binary = (new Ods())->write($workbook);
        $this->content = $this->parsePart('content.xml');

        $styleName = $this->findCell(1, 1)->getAttribute('table:style-name');
        $cellProperties = $this->getStyleProperties($styleName, 'table-cell-properties');
        self::assertSame('middle', $cellProperties?->getAttribute('style:vertical-align'));
        self::assertSame('no-wrap', $cellProperties->getAttribute('fo:wrap-option'));
        self::assertSame('normal', $this->getStyleProperties($styleName, 'text-properties')?->getAttribute('fo:font-weight'));

        $row = $this->content->query('//table:table[1]/table:table-row[3]')->item(0);
        self::assertInstanceOf(DOMElement::class, $row);
        self::assertSame('30.0pt', $this->getStyleProperties($row->getAttribute('table:style-name'), 'table-row-properties')?->getAttribute('style:row-height'));
        self::assertSame(1, $this->content->query('table:table-cell', $row)->length, 'An empty row has one cell.');
    }

    /**
     * The mimetype entry comes first and is stored, the manifest lists all parts, all parts are well-formed.
     *
     * @return void
     */
    public function testWritesPackage(): void
    {
        $parts = ['META-INF/manifest.xml', 'meta.xml', 'settings.xml', 'styles.xml', 'content.xml'];
        self::assertSame(['mimetype', ...$parts], $this->listZipEntries($this->binary));
        // local file header: compression method at offset 8, file name at offset 30, content after the name
        self::assertSame(0, unpack('v', substr($this->binary, 8, 2))[1], 'The mimetype entry is stored uncompressed.');
        self::assertSame('mimetypeapplication/vnd.oasis.opendocument.spreadsheet', substr($this->binary, 30, 54));

        $manifest = $this->parsePart('META-INF/manifest.xml');
        foreach (['/', 'content.xml', 'styles.xml', 'meta.xml', 'settings.xml'] as $part) {
            self::assertSame(1, $manifest->query('//manifest:file-entry[@manifest:full-path="'.$part.'"]')->length, "Manifest entry $part");
        }
    }

    /**
     * An empty workbook can be read back.
     *
     * @return void
     */
    public function testWritesEmptyWorkbook(): void
    {
        $spreadsheet = $this->readBack(new Ods(), new Workbook(), new OdsReader());
        self::assertSame(['Worksheet'], $spreadsheet->getSheetNames());
        self::assertNull($spreadsheet->getSheet(0)->getCell('A1')->getValue());
        self::assertStringContainsString(
            '<config:config-item config:name="ShowGrid" config:type="boolean">true</config:config-item>',
            $this->readZipEntry((new Ods())->write(new Workbook()), 'settings.xml'),
            'The gridlines are shown by default.'
        );
    }

    /**
     * Cells at the sheet limits can be written, empty merged ranges are not combined with other cells.
     *
     * @return void
     */
    public function testWritesLastCellAndEmptyMerges(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->mergeCells(2, 2, 4, 3);
        $sheet->mergeCells(5, 2, 6, 2);
        $sheet->setValue(16384, 1048576, 'last');
        $spreadsheet = $this->readBack(new Ods(), $workbook, new OdsReader());
        $readSheet = $spreadsheet->getSheet(0);
        self::assertSame(['B2:D3', 'E2:F2'], array_values($readSheet->getMergeCells()));
        self::assertSame('last', $readSheet->getCell('XFD1048576')->getValue());
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
        $spreadsheet = $this->readBack(new Ods(), $workbook, new OdsReader());
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
        $binary = (new Ods(1700000000))->write(SampleWorkbook::create());

        self::assertSame($binary, (new Ods(1700000000))->write(SampleWorkbook::create()));
        self::assertSame(1700000000, $this->readZipEntryTime($binary, 'content.xml'), 'The timestamp is the modification time of the parts.');
        self::assertNotSame($binary, (new Ods(1600000000))->write(SampleWorkbook::create()));
    }

    /**
     * Centered and bottom aligned cells, the fixed alignment source and the fixed row height.
     *
     * LibreOffice ignores the horizontal alignment without the alignment source 'fix', and it replaces the
     * row height with the optimal height unless the optimal height is switched off.
     *
     * @return void
     */
    public function testWritesAlignmentsAndFixedRowHeights(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setValue(1, 1, 'a');
        $sheet->setValue(2, 1, 'b');
        $sheet->setStyle(1, 1, 1, 1, new Style(horizontal: HorizontalAlignment::Center, vertical: VerticalAlignment::Bottom));
        $sheet->setStyle(2, 1, 2, 1, new Style(vertical: VerticalAlignment::Top));
        $sheet->setRowHeight(1, 30);
        $content = $this->readZipEntry((new Ods())->write($workbook), 'content.xml');

        self::assertStringContainsString(
            '<style:table-cell-properties style:vertical-align="bottom" style:text-align-source="fix"/>'
            .'<style:paragraph-properties fo:text-align="center"/>',
            $content
        );
        self::assertStringContainsString(
            '<style:table-cell-properties style:vertical-align="top"/></style:style>',
            $content,
            'A cell without a horizontal alignment keeps the alignment source of the value type.'
        );
        self::assertStringContainsString('style:row-height="30.0pt" style:use-optimal-row-height="false"', $content);
    }

    /**
     * Saving to a path that can't be written throws.
     *
     * @return void
     */
    public function testThrowsIfFileCannotBeSaved(): void
    {
        $this->expectException(WriterException::class);
        (new Ods())->save(new Workbook(), '/nonexistent/directory/file.ods');
    }

    /**
     * MIME type and file extension.
     *
     * @return void
     */
    public function testReturnsFormatInfo(): void
    {
        self::assertSame('application/vnd.oasis.opendocument.spreadsheet', (new Ods())->getMimeType());
        self::assertSame('ods', (new Ods())->getFileExtension());
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

    /**
     * Return the text of a cell read back by PhpSpreadsheet, without formatting.
     *
     * @param Worksheet $sheet the sheet
     * @param string $reference cell reference, e.g. 'A1'
     *
     * @return string
     */
    private function getPlainText(Worksheet $sheet, string $reference): string
    {
        $value = $sheet->getCell($reference)->getValue();

        return $value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText ? $value->getPlainText() : (string)$value;
    }

    /**
     * Parse a part of the sample workbook and return an XPath with the ODF namespaces registered.
     *
     * @param string $name path of the part in the archive
     *
     * @return DOMXPath
     */
    private function parsePart(string $name): DOMXPath
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($this->readZipEntry($this->binary, $name)), "$name is well-formed.");
        $xpath = new DOMXPath($dom);
        $namespaces = [
            'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
            'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
            'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
            'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
            'number' => 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0',
            'fo' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
            'config' => 'urn:oasis:names:tc:opendocument:xmlns:config:1.0',
            'manifest' => 'urn:oasis:names:tc:opendocument:xmlns:manifest:1.0',
        ];
        foreach ($namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    /**
     * Return the cell element at a coordinate of content.xml and fail if it doesn't exist.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     *
     * @return DOMElement
     */
    private function findCell(int $col, int $row): DOMElement
    {
        $found = $this->findCellOrNull($col, $row);
        self::assertInstanceOf(DOMElement::class, $found, "Cell ($col, $row) exists.");

        return $found;
    }

    /**
     * Return the cell element at a coordinate of content.xml, resolving the repeated rows and cells.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     *
     * @return DOMElement|null null if the row ends before the column or the table before the row
     */
    private function findCellOrNull(int $col, int $row): ?DOMElement
    {
        $found = null;
        $rowStart = 1;
        foreach ($this->content->query('//table:table/table:table-row') as $rowElement) {
            self::assertInstanceOf(DOMElement::class, $rowElement);
            $rowCount = (int)($rowElement->getAttribute('table:number-rows-repeated') ?: 1);
            if ($found === null && $row >= $rowStart && $row < $rowStart + $rowCount) {
                $colStart = 1;
                foreach ($this->content->query('table:table-cell|table:covered-table-cell', $rowElement) as $cellElement) {
                    self::assertInstanceOf(DOMElement::class, $cellElement);
                    $colCount = (int)($cellElement->getAttribute('table:number-columns-repeated') ?: 1);
                    if ($found === null && $col >= $colStart && $col < $colStart + $colCount) {
                        $found = $cellElement;
                    }
                    $colStart += $colCount;
                }
            }
            $rowStart += $rowCount;
        }

        return $found;
    }

    /**
     * Return the style name of a column of the first sheet, resolving the repeated columns.
     *
     * @param int $col 1-based column index
     *
     * @return string an empty string if the column has no style
     */
    private function getColumnStyleName(int $col): string
    {
        $styleName = '';
        $colStart = 1;
        foreach ($this->content->query('//table:table[1]/table:table-column') as $column) {
            self::assertInstanceOf(DOMElement::class, $column);
            $colCount = (int)($column->getAttribute('table:number-columns-repeated') ?: 1);
            if ($col >= $colStart && $col < $colStart + $colCount) {
                $styleName = $column->getAttribute('table:style-name');
            }
            $colStart += $colCount;
        }

        return $styleName;
    }

    /**
     * Return a properties element of an automatic style.
     *
     * @param string $styleName name of the style
     * @param string $properties local name of the properties element, e.g. 'text-properties'
     *
     * @return DOMElement|null
     */
    private function getStyleProperties(string $styleName, string $properties): ?DOMElement
    {
        $element = $this->content->query('//office:automatic-styles/style:style[@style:name="'.$styleName.'"]/style:'.$properties)->item(0);

        return $element instanceof DOMElement ? $element : null;
    }

    /**
     * Return the number of decimals of the number style of a cell.
     *
     * @param DOMElement $cell the cell element
     *
     * @return string|null the decimal places or null if the cell has no number style
     */
    private function getDecimalPlaces(DOMElement $cell): ?string
    {
        $style = $this->content->query('//office:automatic-styles/style:style[@style:name="'.$cell->getAttribute('table:style-name').'"]')->item(0);
        $dataStyle = $style instanceof DOMElement ? $style->getAttribute('style:data-style-name') : '';
        $number = $this->content->query('//number:number-style[@style:name="'.$dataStyle.'"]/number:number')->item(0);

        return $number instanceof DOMElement ? $number->getAttribute('number:decimal-places') : null;
    }
}
