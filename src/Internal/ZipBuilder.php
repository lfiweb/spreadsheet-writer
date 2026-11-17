<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

use SpreadsheetWriter\Exception\WriterException;

/**
 * Builds a ZIP archive in memory.
 *
 * Supports only what XLSX and ODS need: files that are deflated or stored uncompressed,
 * written in the order they are added. No ZIP64, so each file and the whole archive
 * must be smaller than 4 GB.
 *
 * @internal
 */
final class ZipBuilder
{
    /**
     * compression method 'stored' (no compression)
     */
    private const int METHOD_STORED = 0;

    /**
     * compression method 'deflated'
     */
    private const int METHOD_DEFLATED = 8;

    /**
     * ZIP version needed to extract (2.0, required for deflate)
     */
    private const int VERSION = 20;

    /**
     * largest size or offset without ZIP64
     */
    private const int MAX_SIZE = 0xFFFFFFFF;

    /**
     * largest number of files without ZIP64
     */
    private const int MAX_FILES = 0xFFFF;

    /**
     * deflate compression level, from 1 (fastest) to 9 (smallest)
     */
    private const int COMPRESSION_LEVEL = 6;

    /**
     * local file headers and file data
     * @var list<string>
     */
    private array $chunks = [];

    /**
     * central directory entries
     * @var list<string>
     */
    private array $centralEntries = [];

    /**
     * number of bytes written to $chunks so far
     * @var int
     */
    private int $offset = 0;

    /**
     * modification time of all files in MS-DOS format
     * @var int
     */
    private int $dosTime;

    /**
     * modification date of all files in MS-DOS format
     * @var int
     */
    private int $dosDate;

    /**
     * Create an empty archive.
     *
     * @param int|null $timestamp modification time of the files as a Unix timestamp, null for now
     */
    public function __construct(?int $timestamp = null)
    {
        $date = getdate($timestamp ?? time());
        $this->dosTime = ($date['hours'] << 11) | ($date['minutes'] << 5) | intdiv($date['seconds'], 2);
        $this->dosDate = ((max($date['year'], 1980) - 1980) << 9) | ($date['mon'] << 5) | $date['mday'];
    }

    /**
     * Add a file to the archive.
     *
     * @param string $name path of the file within the archive, e.g. 'xl/workbook.xml'
     * @param string $content content of the file
     * @param bool $compressed true to deflate the content, false to store it uncompressed
     *
     * @return void
     * @throws WriterException if the archive gets too large
     */
    public function addFile(string $name, string $content, bool $compressed = true): void
    {
        $data = $compressed ? gzdeflate($content, self::COMPRESSION_LEVEL) : $content;
        if ($data === false) {
            throw new WriterException("Failed to compress '$name'.");
        }
        if (strlen($content) > self::MAX_SIZE || $this->offset > self::MAX_SIZE || count($this->centralEntries) >= self::MAX_FILES) {
            throw new WriterException('The archive is too large, ZIP64 is not supported.');
        }
        $method = $compressed ? self::METHOD_DEFLATED : self::METHOD_STORED;
        $crc = crc32($content);
        // fields shared by the local header and the central directory entry:
        // version needed, flags, method, time, date, crc-32, compressed size, uncompressed size, name length, extra length
        $fields = pack(
            'vvvvvVVVvv',
            self::VERSION,
            0,
            $method,
            $this->dosTime,
            $this->dosDate,
            $crc,
            strlen($data),
            strlen($content),
            strlen($name),
            0
        );
        $localHeader = pack('V', 0x04034b50).$fields.$name;
        // central directory entry: signature, version made by, shared fields, comment length,
        // disk number, internal attributes, external attributes, offset of the local header
        $this->centralEntries[] = pack('Vv', 0x02014b50, self::VERSION).$fields
            .pack('vvvVV', 0, 0, 0, 0, $this->offset).$name;
        $this->chunks[] = $localHeader;
        $this->chunks[] = $data;
        $this->offset += strlen($localHeader) + strlen($data);
    }

    /**
     * Return the archive as a binary string.
     *
     * @return string
     * @throws WriterException if the archive is too large
     */
    public function build(): string
    {
        $centralDirectory = implode('', $this->centralEntries);
        if ($this->offset > self::MAX_SIZE) {
            throw new WriterException('The archive is too large, ZIP64 is not supported.');
        }
        $numFiles = count($this->centralEntries);
        // end of central directory: signature, disk number, disk with central directory,
        // entries on this disk, total entries, size of central directory, offset of central directory, comment length
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $numFiles, $numFiles, strlen($centralDirectory), $this->offset, 0);

        return implode('', $this->chunks).$centralDirectory.$end;
    }
}
