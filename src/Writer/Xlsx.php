<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer;

use SpreadsheetWriter\Exception\WriterException;
use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\Internal\ZipBuilder;
use SpreadsheetWriter\Workbook;
use SpreadsheetWriter\Writer\Xlsx\PackageXml;
use SpreadsheetWriter\Writer\Xlsx\SharedStrings;
use SpreadsheetWriter\Writer\Xlsx\SheetXml;
use SpreadsheetWriter\Writer\Xlsx\StyleTable;

/**
 * Writes a workbook as an Office Open XML spreadsheet (.xlsx).
 *
 * The file is built in memory, no temp files are used.
 */
final class Xlsx extends AbstractWriter
{
    /**
     * MIME type of XLSX files
     */
    public const string MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Return the file as a binary string.
     *
     * @param Workbook $workbook the workbook to write
     *
     * @return string
     * @throws WriterException if the file can't be created
     */
    public function write(Workbook $workbook): string
    {
        $resolver = new StyleResolver();
        $styleTable = new StyleTable($resolver);
        $sharedStrings = new SharedStrings();
        $sheetXml = new SheetXml($resolver, $styleTable, $sharedStrings);
        // the sheet must be built first, because it fills the style table and the shared strings
        $sheetPart = $sheetXml->build($workbook->getActiveSheet());

        $zip = new ZipBuilder($this->timestamp);
        $zip->addFile('[Content_Types].xml', PackageXml::buildContentTypes());
        $zip->addFile('_rels/.rels', PackageXml::buildRootRelationships());
        $zip->addFile('xl/workbook.xml', PackageXml::buildWorkbook());
        $zip->addFile('xl/_rels/workbook.xml.rels', PackageXml::buildWorkbookRelationships());
        $zip->addFile('xl/styles.xml', $styleTable->buildXml());
        $zip->addFile('xl/sharedStrings.xml', $sharedStrings->buildXml());
        $zip->addFile('xl/worksheets/sheet1.xml', $sheetPart);

        return $zip->build();
    }

    /**
     * Return the MIME type of XLSX files.
     *
     * @return string
     */
    public function getMimeType(): string
    {
        return self::MIME_TYPE;
    }

    /**
     * Return the file extension of XLSX files.
     *
     * @return string
     */
    public function getFileExtension(): string
    {
        return 'xlsx';
    }
}
