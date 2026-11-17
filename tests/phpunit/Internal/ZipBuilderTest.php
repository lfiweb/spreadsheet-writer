<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Internal\ZipBuilder;
use ZipArchive;

/**
 * Tests the in-memory ZIP archive by opening it with ZipArchive.
 */
#[CoversClass(ZipBuilder::class)]
final class ZipBuilderTest extends TestCase
{
    /**
     * path of the temp file of the archive
     * @var string
     */
    private string $path;

    /**
     * Create the path of the temp file.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/spreadsheet-writer-test-'.bin2hex(random_bytes(8)).'.zip';
    }

    /**
     * Delete the temp file.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Files keep their order, content and compression method, and the checksums are valid.
     *
     * @return void
     */
    public function testBuildsReadableArchive(): void
    {
        $large = str_repeat('<row>repeated content</row>', 1000);
        $builder = new ZipBuilder(1700000000);
        $builder->addFile('mimetype', 'application/vnd.oasis.opendocument.spreadsheet', compressed: false);
        $builder->addFile('dir/large.xml', $large);
        $builder->addFile('empty.txt', '');
        $builder->addFile('ümlaut.txt', 'ü');
        $binary = $builder->build();
        file_put_contents($this->path, $binary);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path, ZipArchive::CHECKCONS));
        self::assertSame(4, $zip->numFiles);
        self::assertSame(['mimetype', 'dir/large.xml', 'empty.txt', 'ümlaut.txt'], [
            $zip->getNameIndex(0), $zip->getNameIndex(1), $zip->getNameIndex(2), $zip->getNameIndex(3),
        ]);
        self::assertSame('application/vnd.oasis.opendocument.spreadsheet', $zip->getFromName('mimetype'));
        self::assertSame($large, $zip->getFromName('dir/large.xml'));
        self::assertSame('', $zip->getFromName('empty.txt'));
        self::assertSame('ü', $zip->getFromName('ümlaut.txt'));

        $stored = $zip->statName('mimetype');
        $deflated = $zip->statName('dir/large.xml');
        self::assertIsArray($stored);
        self::assertIsArray($deflated);
        self::assertSame(ZipArchive::CM_STORE, $stored['comp_method']);
        self::assertSame(ZipArchive::CM_DEFLATE, $deflated['comp_method']);
        self::assertLessThan(strlen($large), $deflated['comp_size']);
        // MS-DOS times have a resolution of 2 seconds, the timestamp is even
        self::assertSame(1700000000, $stored['mtime']);
        $zip->close();
    }

    /**
     * The same timestamp gives the same archive.
     *
     * @return void
     */
    public function testBuildsReproducibleArchive(): void
    {
        $build = static function (int $timestamp = 1700000000): string {
            $builder = new ZipBuilder($timestamp);
            $builder->addFile('a.txt', 'a');

            return $builder->build();
        };
        self::assertSame($build(), $build());
        self::assertNotSame($build(), $build(1600000000));
    }

    /**
     * The timestamp is stored as the modification time; ZIP can't store a date before 1980, so the year is raised to 1980.
     *
     * @return void
     */
    public function testStoresTimestamp(): void
    {
        $times = [];
        foreach ([1700000000, 0] as $timestamp) {
            $builder = new ZipBuilder($timestamp);
            $builder->addFile('a.txt', 'a');
            file_put_contents($this->path, $builder->build());
            $zip = new ZipArchive();
            self::assertTrue($zip->open($this->path));
            $stat = $zip->statName('a.txt');
            $zip->close();
            self::assertIsArray($stat);
            $times[] = $stat['mtime'];
        }

        self::assertSame(1700000000, $times[0]);
        self::assertSame('1980', date('Y', $times[1]));
    }

    /**
     * An archive without files is valid.
     *
     * @return void
     */
    public function testBuildsEmptyArchive(): void
    {
        file_put_contents($this->path, (new ZipBuilder())->build());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path));
        self::assertSame(0, $zip->numFiles);
    }
}
