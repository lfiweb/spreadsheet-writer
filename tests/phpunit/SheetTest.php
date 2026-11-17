<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Exception\InvalidArgumentException;
use SpreadsheetWriter\Internal\Cell;
use SpreadsheetWriter\Internal\CellType;
use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Sheet;
use SpreadsheetWriter\Style\Style;

/**
 * Tests the sheet model: values, merges, styles, dimensions, gridlines and validation.
 */
#[CoversClass(Sheet::class)]
final class SheetTest extends TestCase
{
    /**
     * Return calls with invalid arguments.
     *
     * @return array<string, array{0: Closure(Sheet): void}>
     */
    public static function provideInvalidCalls(): array
    {
        return [
            'column 0' => [static fn(Sheet $sheet) => $sheet->setValue(0, 1, 'x')],
            'row too large' => [static fn(Sheet $sheet) => $sheet->setString(1, 1048577, 'x')],
            'INF' => [static fn(Sheet $sheet) => $sheet->setValue(1, 1, INF)],
            'NAN' => [static fn(Sheet $sheet) => $sheet->setValue(1, 1, NAN)],
            'rich text too long' => [static fn(Sheet $sheet) => $sheet->setString(1, 1, (new RichText())->addText(str_repeat('x', 20000))->addText(str_repeat('y', 12768)))],
            'negative row height' => [static fn(Sheet $sheet) => $sheet->setRowHeight(1, -1)],
            'row height too large' => [static fn(Sheet $sheet) => $sheet->setRowHeight(1, 409.5)],
            'row height of row 0' => [static fn(Sheet $sheet) => $sheet->setRowHeight(0, 10)],
            'negative column width' => [static fn(Sheet $sheet) => $sheet->setColumnWidth(1, -1)],
            'column width too large' => [static fn(Sheet $sheet) => $sheet->setColumnWidth(1, 255.5)],
            'column width of column 0' => [static fn(Sheet $sheet) => $sheet->setColumnWidth(0, 10)],
        ];
    }

    /**
     * Numeric strings become numbers with their decimals, other values keep their type.
     *
     * @return void
     */
    public function testStoresValues(): void
    {
        $sheet = new Sheet();
        $sheet->setValue(1, 1, '12.30');
        $sheet->setValue(2, 1, 'text');
        $sheet->setValue(3, 1, 42);
        $sheet->setValue(4, 1, 3.25);
        $sheet->setValue(5, 1, 1000000000000000);
        $sheet->setValue(6, 1, '=1+1');
        $sheet->setValue(7, 1, '007');
        $cells = $sheet->getCells()[1];

        self::assertEquals(new Cell(CellType::Number, 12.3, 2), $cells[1]);
        self::assertEquals(new Cell(CellType::Text, 'text'), $cells[2]);
        self::assertEquals(new Cell(CellType::Number, 42), $cells[3]);
        self::assertEquals(new Cell(CellType::Number, 3.25), $cells[4]);
        self::assertEquals(new Cell(CellType::Text, '1000000000000000'), $cells[5], 'Integers too large are text.');
        self::assertEquals(new Cell(CellType::Text, '=1+1'), $cells[6], 'No formulas.');
        self::assertEquals(new Cell(CellType::Text, '007'), $cells[7]);
    }

    /**
     * setString() always stores text and copies rich text.
     *
     * @return void
     */
    public function testStoresStrings(): void
    {
        $sheet = new Sheet();
        $sheet->setString(1, 1, '12.30');
        $richText = (new RichText())->addText('a');
        $sheet->setString(2, 1, $richText);
        $richText->addText('b');
        $sheet->setString(1, 2, str_repeat('ä', 32767));
        $cells = $sheet->getCells();

        self::assertEquals(new Cell(CellType::Text, '12.30'), $cells[1][1]);
        self::assertSame(CellType::RichText, $cells[1][2]->type);
        self::assertInstanceOf(RichText::class, $cells[1][2]->value);
        self::assertSame('a', $cells[1][2]->value->getPlainText(), 'Later changes to the rich text are not stored.');
        self::assertSame(CellType::Text, $cells[2][1]->type, 'The maximum length is counted in characters.');
        self::assertSame(str_repeat('ä', 32767), $cells[2][1]->value, 'A text of the maximum length is kept.');
    }

    /**
     * Texts longer than a cell can hold are cut off, as PhpSpreadsheet does.
     *
     * @return void
     */
    public function testCutsOffLongTexts(): void
    {
        $sheet = new Sheet();
        $sheet->setString(1, 1, str_repeat('ä', 32767).'xyz');
        $sheet->setValue(2, 1, str_repeat('x', 40000));
        $cells = $sheet->getCells();

        self::assertSame(str_repeat('ä', 32767), $cells[1][1]->value);
        self::assertSame(str_repeat('x', 32767), $cells[1][2]->value);
    }

    /**
     * The length of a rich text is counted in characters, not in bytes.
     *
     * @return void
     */
    public function testAcceptsRichTextAtTheLimit(): void
    {
        $sheet = new Sheet();
        $sheet->setString(1, 1, (new RichText())->addText(str_repeat('ä', 32767)));

        self::assertSame(32767, mb_strlen($sheet->getCells()[1][1]->value->getPlainText()));
    }

    /**
     * Null clears a cell, a later value replaces an earlier one.
     *
     * @return void
     */
    public function testClearsAndReplacesValues(): void
    {
        $sheet = new Sheet();
        $sheet->setValue(1, 1, 'a');
        $sheet->setValue(2, 1, 'b');
        $sheet->setValue(2, 1, 'c');
        $sheet->setValue(1, 1, null);
        $sheet->setValue(3, 3, null);

        self::assertEquals([1 => [2 => new Cell(CellType::Text, 'c')]], $sheet->getCells());

        $sheet->setValue(2, 1, null);
        self::assertSame([], $sheet->getCells(), 'A row without cells is removed.');
    }

    /**
     * Merged ranges are stored, single cells are ignored.
     *
     * @return void
     */
    public function testMergesCells(): void
    {
        $sheet = new Sheet();
        $sheet->mergeCells(1, 1, 3, 1);
        $sheet->mergeCells(5, 5, 5, 5);
        $sheet->mergeCells(1, 2, 1, 4);

        self::assertSame([[1, 1, 3, 1], [1, 2, 1, 4]], $sheet->getMergedRanges());
    }

    /**
     * Reversed ranges are corrected, as in PhpSpreadsheet.
     *
     * @return void
     */
    public function testCorrectsReversedRanges(): void
    {
        $sheet = new Sheet();
        $style = new Style(textWrapped: true);
        $sheet->mergeCells(3, 2, 1, 1);
        $sheet->mergeCells(1, 1, 3, 2);
        $sheet->setStyle(5, 4, 6, 3, $style);

        self::assertSame([[1, 1, 3, 2]], $sheet->getMergedRanges(), 'The reversed range is the same range, merging it again has no effect.');
        self::assertSame([[[5, 3, 6, 4], $style]], $sheet->getStyledRanges());
    }

    /**
     * Merging the same range again has no effect, as in PhpSpreadsheet.
     *
     * @return void
     */
    public function testIgnoresRepeatedMerge(): void
    {
        $sheet = new Sheet();
        $sheet->mergeCells(2, 2, 4, 4);
        $sheet->mergeCells(1, 6, 2, 6);
        $sheet->mergeCells(2, 2, 4, 4);

        self::assertSame([[2, 2, 4, 4], [1, 6, 2, 6]], $sheet->getMergedRanges());
    }

    /**
     * Overlapping merged ranges throw.
     *
     * @return void
     */
    public function testRejectsOverlappingMerges(): void
    {
        $sheet = new Sheet();
        $sheet->mergeCells(2, 2, 4, 4);
        $this->expectException(InvalidArgumentException::class);
        $sheet->mergeCells(4, 4, 5, 5);
    }

    /**
     * A failed merge doesn't change the merged ranges.
     *
     * @return void
     */
    public function testKeepsMergesAfterRejectedMerge(): void
    {
        $sheet = new Sheet();
        $sheet->mergeCells(2, 2, 3, 3);
        try {
            $sheet->mergeCells(1, 1, 2, 2);
        } catch (InvalidArgumentException) {
            // expected
        }
        $sheet->mergeCells(1, 1, 1, 2);

        self::assertSame([[2, 2, 3, 3], [1, 1, 1, 2]], $sheet->getMergedRanges());
    }

    /**
     * Styled ranges keep their order.
     *
     * @return void
     */
    public function testStoresStyledRanges(): void
    {
        $sheet = new Sheet();
        $first = new Style(textWrapped: true);
        $second = new Style(textWrapped: false);
        $sheet->setStyle(1, 1, 2, 2, $first);
        $sheet->setStyle(2, 2, 2, 2, $second);

        self::assertSame([[[1, 1, 2, 2], $first], [[2, 2, 2, 2], $second]], $sheet->getStyledRanges());
    }

    /**
     * Row heights, column widths and gridlines are stored.
     *
     * @return void
     */
    public function testStoresDimensionsAndGridlines(): void
    {
        $sheet = new Sheet();
        $sheet->setRowHeight(3, 409);
        $sheet->setRowHeight(5, 0);
        $sheet->setColumnWidth(2, 0);
        $sheet->setColumnWidth(4, 255);

        self::assertSame([3 => 409.0, 5 => 0.0], $sheet->getRowHeights());
        self::assertSame([2 => 0.0, 4 => 255.0], $sheet->getColumnWidths());
        self::assertSame(255.0, $sheet->getColumnWidth(4));
        self::assertNull($sheet->getColumnWidth(1));
        self::assertTrue($sheet->areGridlinesShown());
        $sheet->setShowGridlines(false);
        self::assertFalse($sheet->areGridlinesShown());
    }

    /**
     * Invalid arguments throw.
     *
     * @param Closure(Sheet): void $call the call
     *
     * @return void
     */
    #[DataProvider('provideInvalidCalls')]
    public function testRejectsInvalidArguments(Closure $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $call(new Sheet());
    }
}
