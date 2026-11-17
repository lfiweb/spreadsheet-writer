<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Writer;

use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Workbook;
use SpreadsheetWriter\Writer\WriterInterface;
use ZipArchive;

/**
 * Base class of the writer tests: writes workbooks to temp files and reads them back
 * with PhpSpreadsheet or as raw parts of the ZIP archive.
 */
abstract class WriterTestCase extends TestCase
{
    /**
     * temp files created by the test, deleted after each test
     * @var list<string>
     */
    private array $tempFiles = [];

    /**
     * Delete the temp files.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Write a workbook to a temp file and return its path.
     *
     * @param WriterInterface $writer the writer
     * @param Workbook $workbook the workbook
     *
     * @return string
     */
    protected function saveToTempFile(WriterInterface $writer, Workbook $workbook): string
    {
        $path = $this->createTempPath($writer->getFileExtension());
        $writer->save($workbook, $path);

        return $path;
    }

    /**
     * Write a workbook and read it back with a PhpSpreadsheet reader.
     *
     * @param WriterInterface $writer the writer
     * @param Workbook $workbook the workbook
     * @param IReader $reader PhpSpreadsheet reader of the same format
     *
     * @return Spreadsheet
     */
    protected function readBack(WriterInterface $writer, Workbook $workbook, IReader $reader): Spreadsheet
    {
        return $reader->load($this->saveToTempFile($writer, $workbook));
    }

    /**
     * Return the content of a file of a ZIP archive.
     *
     * @param string $binary the archive
     * @param string $name path of the file in the archive
     *
     * @return string
     */
    protected function readZipEntry(string $binary, string $name): string
    {
        $path = $this->createTempPath('zip');
        file_put_contents($path, $binary);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path), 'The archive can be opened.');
        $content = $zip->getFromName($name);
        $zip->close();
        self::assertIsString($content, "The archive contains '$name'.");

        return $content;
    }

    /**
     * Return the modification time of a file of a ZIP archive as a Unix timestamp.
     *
     * @param string $binary the archive
     * @param string $name path of the file in the archive
     *
     * @return int
     */
    protected function readZipEntryTime(string $binary, string $name): int
    {
        $path = $this->createTempPath('zip');
        file_put_contents($path, $binary);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path), 'The archive can be opened.');
        $stat = $zip->statName($name);
        $zip->close();
        self::assertIsArray($stat, "The archive contains '$name'.");

        return $stat['mtime'];
    }

    /**
     * Return the names of the files of a ZIP archive in their order.
     *
     * @param string $binary the archive
     *
     * @return list<string>
     */
    protected function listZipEntries(string $binary): array
    {
        $path = $this->createTempPath('zip');
        file_put_contents($path, $binary);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path), 'The archive can be opened.');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string)$zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    /**
     * Return a path for a new temp file, which is deleted after the test.
     *
     * @param string $extension file extension without the dot
     *
     * @return string
     */
    protected function createTempPath(string $extension): string
    {
        $path = sys_get_temp_dir().'/spreadsheet-writer-test-'.bin2hex(random_bytes(8)).'.'.$extension;
        $this->tempFiles[] = $path;

        return $path;
    }
}
