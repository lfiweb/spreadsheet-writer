<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Xlsx;

use SpreadsheetWriter\Internal\Cell;
use SpreadsheetWriter\Internal\CellType;
use SpreadsheetWriter\Internal\Coordinate;
use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\Sheet;

/**
 * Builds the XML of a worksheet of an XLSX file (xl/worksheets/sheet1.xml).
 *
 * @internal
 */
final class SheetXml
{
    /**
     * Create the builder.
     *
     * @param StyleResolver $resolver resolver of the workbook
     * @param StyleTable $styleTable style table of the workbook
     * @param SharedStrings $sharedStrings shared string table of the workbook
     */
    public function __construct(
        private readonly StyleResolver $resolver,
        private readonly StyleTable $styleTable,
        private readonly SharedStrings $sharedStrings,
    ) {
    }

    /**
     * Return the XML of a worksheet.
     *
     * @param Sheet $sheet the sheet
     *
     * @return string
     */
    public function build(Sheet $sheet): string
    {
        $styleIds = $this->resolver->resolveSheet($sheet);
        $rowHeights = $sheet->getRowHeights();
        [$rows, $dimension] = $this->createRows($sheet->getCells(), $styleIds, $rowHeights);

        $sheetView = '<sheetView workbookViewId="0" tabSelected="1"';
        $sheetView .= $sheet->areGridlinesShown() ? '' : ' showGridLines="0"';
        $sheetView .= '/>';

        return Xml::DECLARATION
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="'.$dimension.'"/>'
            .'<sheetViews>'.$sheetView.'</sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .$this->createColumns($sheet->getColumnWidths())
            .'<sheetData>'.$rows.'</sheetData>'
            .$this->createMergedCells($sheet->getMergedRanges())
            .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            .'</worksheet>';
    }

    /**
     * Return the <row> elements and the dimension (used range) of the sheet.
     *
     * A row is written if it has a value, a styled cell or a custom height.
     *
     * @param array<int, array<int, Cell>> $cells cell values indexed by row and column
     * @param array<int, array<int, int>> $styleIds style ids indexed by row and column
     * @param array<int, float> $rowHeights row heights indexed by row
     *
     * @return array{0: string, 1: string} [rows XML, dimension like 'A1:H20']
     */
    private function createRows(array $cells, array $styleIds, array $rowHeights): array
    {
        $rowIndexes = array_keys($cells + $styleIds + $rowHeights);
        sort($rowIndexes);
        $parts = [];
        $minCol = PHP_INT_MAX;
        $maxCol = 0;
        foreach ($rowIndexes as $row) {
            $rowCells = $cells[$row] ?? [];
            $rowStyleIds = $styleIds[$row] ?? [];
            $colIndexes = array_keys($rowCells + $rowStyleIds);
            sort($colIndexes);
            $cellsXml = '';
            foreach ($colIndexes as $col) {
                $cellsXml .= $this->createCell($col, $row, $rowCells[$col] ?? null, $rowStyleIds[$col] ?? StyleResolver::DEFAULT_ID);
            }
            if ($colIndexes !== []) {
                $minCol = min($minCol, $colIndexes[0]);
                $maxCol = max($maxCol, $colIndexes[count($colIndexes) - 1]);
            }
            $attributes = ' r="'.$row.'"';
            if (isset($rowHeights[$row])) {
                $attributes .= ' ht="'.Xml::formatNumber($rowHeights[$row]).'" customHeight="1"';
            }
            $parts[] = $cellsXml === '' ? '<row'.$attributes.'/>' : '<row'.$attributes.'>'.$cellsXml.'</row>';
        }
        $dimension = 'A1';
        if ($maxCol > 0) {
            $dimension = Coordinate::getReference($minCol, $rowIndexes[0])
                .':'.Coordinate::getReference($maxCol, $rowIndexes[count($rowIndexes) - 1]);
        }

        return [implode('', $parts), $dimension];
    }

    /**
     * Return the <c> element of a cell, or an empty string for a cell without a value that has the default format.
     *
     * @param int $col 1-based column index
     * @param int $row 1-based row index
     * @param Cell|null $cell the cell value or null for an empty cell
     * @param int $styleId style id of the cell
     *
     * @return string
     */
    private function createCell(int $col, int $row, ?Cell $cell, int $styleId): string
    {
        $xfIndex = $this->styleTable->getXfIndex($styleId, $cell?->decimals ?? 0);
        $attributes = ' r="'.Coordinate::getReference($col, $row).'"';
        $attributes .= $xfIndex > 0 ? ' s="'.$xfIndex.'"' : '';
        $xml = '';
        // an empty string is written like an empty cell, as PhpSpreadsheet does
        if ($cell === null || $cell->value === '') {
            $xml = $xfIndex > 0 ? '<c'.$attributes.'/>' : '';
        } elseif ($cell->type === CellType::Number) {
            $xml = '<c'.$attributes.'><v>'.Xml::formatNumber($cell->value).'</v></c>';
        } else {
            $xml = '<c'.$attributes.' t="s"><v>'.$this->sharedStrings->add($cell->value).'</v></c>';
        }

        return $xml;
    }

    /**
     * Return the <cols> element with the column widths, or an empty string if no width is set.
     *
     * @param array<int, float> $widths column widths in characters indexed by column
     *
     * @return string
     */
    private function createColumns(array $widths): string
    {
        ksort($widths);
        $xml = '';
        foreach ($widths as $col => $width) {
            $xml .= '<col min="'.$col.'" max="'.$col.'" width="'.Xml::formatNumber($width).'" customWidth="1"/>';
        }

        return $xml === '' ? '' : '<cols>'.$xml.'</cols>';
    }

    /**
     * Return the <mergeCells> element, or an empty string if no cells are merged.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $ranges merged ranges
     *
     * @return string
     */
    private function createMergedCells(array $ranges): string
    {
        $xml = '';
        foreach ($ranges as [$fromCol, $fromRow, $toCol, $toRow]) {
            $xml .= '<mergeCell ref="'.Coordinate::getReference($fromCol, $fromRow)
                .':'.Coordinate::getReference($toCol, $toRow).'"/>';
        }

        return $xml === '' ? '' : '<mergeCells count="'.count($ranges).'">'.$xml.'</mergeCells>';
    }
}
