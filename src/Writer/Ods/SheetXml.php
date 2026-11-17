<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Ods;

use SpreadsheetWriter\Internal\Cell;
use SpreadsheetWriter\Internal\CellType;
use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\Sheet;

/**
 * Builds the <table:table> element of a sheet of an ODS file.
 *
 * ODS has no cell references: rows and cells are written in order, and gaps are filled with
 * empty rows and cells that use the "repeated" attributes. The top left cell of a merged range
 * gets the "spanned" attributes, the other cells of the range are written as covered cells.
 *
 * @internal
 */
final class SheetXml
{
    /**
     * opening tag of the empty cells collected but not written yet, without the repeat attribute
     * @var string
     */
    private string $pendingCell = '';

    /**
     * number of empty cells collected but not written yet
     * @var int
     */
    private int $pendingCount = 0;

    /**
     * Create the builder.
     *
     * @param StyleTable $styleTable style table of the workbook
     */
    public function __construct(private readonly StyleTable $styleTable)
    {
    }

    /**
     * Return the <table:table> element of a sheet.
     *
     * @param Sheet $sheet the sheet
     *
     * @return string
     */
    public function build(Sheet $sheet): string
    {
        $styleIds = $this->styleTable->getResolver()->resolveSheet($sheet);
        $cells = $sheet->getCells();
        $rowHeights = $sheet->getRowHeights();
        $columnWidths = $sheet->getColumnWidths();
        [$spans, $covered] = $this->indexMergedRanges($sheet->getMergedRanges());

        $rowIndexes = array_keys($cells + $styleIds + $rowHeights + $spans + $covered);
        sort($rowIndexes);
        $numColumns = max(1, $columnWidths === [] ? 0 : max(array_keys($columnWidths)));
        foreach ([$cells, $styleIds, $spans, $covered] as $grid) {
            foreach ($grid as $rowCells) {
                $numColumns = max($numColumns, max(array_keys($rowCells)));
            }
        }

        $rows = '';
        $nextRow = 1;
        foreach ($rowIndexes as $row) {
            if ($row > $nextRow) {
                $rows .= $this->createEmptyRows($row - $nextRow);
            }
            $rows .= $this->createRow(
                $row,
                $cells[$row] ?? [],
                $styleIds[$row] ?? [],
                $spans[$row] ?? [],
                $covered[$row] ?? [],
                $rowHeights[$row] ?? null
            );
            $nextRow = $row + 1;
        }
        if ($rows === '') {
            $rows = $this->createEmptyRows(1);
        }

        // The empty <office:forms/> is written by LibreOffice and PhpSpreadsheet too. Without it, LibreOffice
        // calculates the optimal height of some rows with wrapped text one line too short and cuts off the last line.
        return '<table:table table:name="'.Xml::escape(Sheet::TITLE).'">'
            .'<office:forms/>'
            .$this->createColumns($columnWidths, $numColumns)
            .$rows
            .'</table:table>';
    }

    /**
     * Index the merged ranges by their cells.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $ranges merged ranges
     *
     * @return array{0: array<int, array<int, array{0: int, 1: int}>>, 1: array<int, array<int, true>>}
     *     [[number of columns, number of rows] of each top left cell indexed by row and column,
     *     covered cells indexed by row and column]
     */
    private function indexMergedRanges(array $ranges): array
    {
        $spans = [];
        $covered = [];
        foreach ($ranges as [$fromCol, $fromRow, $toCol, $toRow]) {
            $spans[$fromRow][$fromCol] = [$toCol - $fromCol + 1, $toRow - $fromRow + 1];
            for ($row = $fromRow; $row <= $toRow; $row++) {
                for ($col = $fromCol; $col <= $toCol; $col++) {
                    if ($row !== $fromRow || $col !== $fromCol) {
                        $covered[$row][$col] = true;
                    }
                }
            }
        }

        return [$spans, $covered];
    }

    /**
     * Return the <table:table-column> elements, consecutive columns with the same style combined.
     *
     * @param array<int, float> $widths column widths in characters indexed by column
     * @param int $numColumns number of columns of the sheet
     *
     * @return string
     */
    private function createColumns(array $widths, int $numColumns): string
    {
        $xml = '';
        $previous = null;
        $count = 0;
        for ($col = 1; $col <= $numColumns + 1; $col++) {
            $current = null;
            if ($col <= $numColumns) {
                $current = isset($widths[$col])
                    ? '<table:table-column table:style-name="'.$this->styleTable->getColumnStyleName($widths[$col]).'"'
                    : '<table:table-column';
            }
            if ($current !== $previous && $previous !== null) {
                $xml .= $previous.($count > 1 ? ' table:number-columns-repeated="'.$count.'"' : '').'/>';
                $count = 0;
            }
            $previous = $current;
            ++$count;
        }

        return $xml;
    }

    /**
     * Return a row element that stands for the given number of empty rows.
     *
     * @param int $count number of empty rows
     *
     * @return string
     */
    private function createEmptyRows(int $count): string
    {
        $repeated = $count > 1 ? ' table:number-rows-repeated="'.$count.'"' : '';

        return '<table:table-row'.$repeated.'><table:table-cell/></table:table-row>';
    }

    /**
     * Return the <table:table-row> element of a row.
     *
     * @param int $row 1-based row index
     * @param array<int, Cell> $cells cell values indexed by column
     * @param array<int, int> $styleIds style ids indexed by column
     * @param array<int, array{0: int, 1: int}> $spans [number of columns, number of rows] of merged ranges indexed by column
     * @param array<int, true> $covered covered cells of merged ranges indexed by column
     * @param float|null $height row height in points or null for the default height
     *
     * @return string
     */
    private function createRow(int $row, array $cells, array $styleIds, array $spans, array $covered, ?float $height): string
    {
        $colIndexes = array_keys($cells + $styleIds + $spans + $covered);
        sort($colIndexes);
        $xml = '';
        $nextCol = 1;
        foreach ($colIndexes as $col) {
            if ($col > $nextCol) {
                $xml .= $this->addEmptyCells('<table:table-cell', $col - $nextCol);
            }
            $element = isset($covered[$col]) ? 'table:covered-table-cell' : 'table:table-cell';
            $styleId = $styleIds[$col] ?? StyleResolver::DEFAULT_ID;
            $styleName = $this->styleTable->getCellStyleName($styleId, $cells[$col]->decimals ?? 0);
            $tag = '<'.$element.($styleName === null ? '' : ' table:style-name="'.$styleName.'"');
            if (isset($cells[$col])) {
                $xml .= $this->flushEmptyCells().$this->createCell($tag.$this->createSpanAttributes($spans[$col] ?? null), $element, $cells[$col]);
            } elseif (isset($spans[$col])) {
                // the top left cell of a merged range is never combined with other cells
                $xml .= $this->flushEmptyCells().$tag.$this->createSpanAttributes($spans[$col]).'/>';
            } else {
                $xml .= $this->addEmptyCells($tag, 1);
            }
            $nextCol = $col + 1;
        }
        $xml .= $this->flushEmptyCells();
        if ($xml === '') {
            $xml = '<table:table-cell/>';
        }
        $style = $height === null ? '' : ' table:style-name="'.$this->styleTable->getRowStyleName($height).'"';

        return '<table:table-row'.$style.'>'.$xml.'</table:table-row>';
    }

    /**
     * Return the span attributes of the top left cell of a merged range.
     *
     * @param array{0: int, 1: int}|null $span [number of columns, number of rows] or null if the cell is not merged
     *
     * @return string
     */
    private function createSpanAttributes(?array $span): string
    {
        return $span === null
            ? ''
            : ' table:number-columns-spanned="'.$span[0].'" table:number-rows-spanned="'.$span[1].'"';
    }

    /**
     * Return the element of a cell with a value.
     *
     * @param string $tag the opening tag with the style and span attributes, without '>'
     * @param string $element the element name
     * @param Cell $cell the cell value
     *
     * @return string
     */
    private function createCell(string $tag, string $element, Cell $cell): string
    {
        $xml = '';
        if ($cell->type === CellType::Number) {
            // the paragraph holds the displayed text for applications that don't format the value themselves
            $text = $cell->decimals > 0
                ? number_format((float)$cell->value, $cell->decimals, '.', '')
                : (preg_replace('/\.0$/', '', Xml::formatNumber($cell->value)) ?? '');
            $xml = $tag.' office:value-type="float" office:value="'.Xml::formatNumber($cell->value).'">'
                .'<text:p>'.$text.'</text:p></'.$element.'>';
        } else {
            $xml = $tag.' office:value-type="string">'.TextXml::build($cell->value, $this->styleTable).'</'.$element.'>';
        }

        return $xml;
    }

    /**
     * Collect empty cells, so consecutive empty cells with the same tag are written as one element.
     *
     * @param string $tag the opening tag without '>'
     * @param int $count number of cells
     *
     * @return string the elements of the previously collected cells if they have a different tag, an empty string otherwise
     */
    private function addEmptyCells(string $tag, int $count): string
    {
        $xml = '';
        if ($tag !== $this->pendingCell) {
            $xml = $this->flushEmptyCells();
            $this->pendingCell = $tag;
        }
        $this->pendingCount += $count;

        return $xml;
    }

    /**
     * Return the element of the collected empty cells and reset the collection.
     *
     * @return string an empty string if no cells are collected
     */
    private function flushEmptyCells(): string
    {
        $xml = '';
        if ($this->pendingCount > 0) {
            $repeated = $this->pendingCount > 1 ? ' table:number-columns-repeated="'.$this->pendingCount.'"' : '';
            $xml = $this->pendingCell.$repeated.'/>';
        }
        $this->pendingCell = '';
        $this->pendingCount = 0;

        return $xml;
    }
}
