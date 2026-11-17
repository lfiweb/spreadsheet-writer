<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer;

use SpreadsheetWriter\Exception\WriterException;
use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\Internal\ZipBuilder;
use SpreadsheetWriter\Workbook;
use SpreadsheetWriter\Writer\Ods\PackageXml;
use SpreadsheetWriter\Writer\Ods\SheetXml;
use SpreadsheetWriter\Writer\Ods\StyleTable;

/**
 * Writes a workbook as an OpenDocument spreadsheet (.ods).
 *
 * The file is built in memory, no temp files are used.
 */
final class Ods extends AbstractWriter
{
    /**
     * MIME type of ODS files
     */
    public const string MIME_TYPE = 'application/vnd.oasis.opendocument.spreadsheet';

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
        $styleTable = new StyleTable(new StyleResolver());
        $sheetXml = new SheetXml($styleTable);
        $sheet = $workbook->getActiveSheet();

        // the table must be built first, because it fills the automatic styles
        $table = $sheetXml->build($sheet);

        // the mimetype entry must be the first one and uncompressed, so applications can detect the format
        $zip = new ZipBuilder($this->timestamp);
        $zip->addFile('mimetype', self::MIME_TYPE, compressed: false);
        $zip->addFile('META-INF/manifest.xml', PackageXml::buildManifest(self::MIME_TYPE));
        $zip->addFile('meta.xml', PackageXml::buildMeta());
        $zip->addFile('settings.xml', PackageXml::buildSettings($sheet));
        $zip->addFile('styles.xml', PackageXml::buildStyles());
        $zip->addFile('content.xml', PackageXml::buildContent($styleTable->buildXml(), $table));

        return $zip->build();
    }

    /**
     * Return the MIME type of ODS files.
     *
     * @return string
     */
    public function getMimeType(): string
    {
        return self::MIME_TYPE;
    }

    /**
     * Return the file extension of ODS files.
     *
     * @return string
     */
    public function getFileExtension(): string
    {
        return 'ods';
    }
}
