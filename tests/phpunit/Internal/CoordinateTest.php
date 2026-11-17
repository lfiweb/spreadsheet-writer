<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Exception\InvalidArgumentException;
use SpreadsheetWriter\Internal\Coordinate;

/**
 * Tests the validation and conversion of cell coordinates.
 */
#[CoversClass(Coordinate::class)]
final class CoordinateTest extends TestCase
{
    /**
     * Return column indexes with their letters.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function provideColumnLetters(): array
    {
        return [
            'first' => [1, 'A'],
            'last single letter' => [26, 'Z'],
            'first double letter' => [27, 'AA'],
            'AZ' => [52, 'AZ'],
            'ZZ' => [702, 'ZZ'],
            'first triple letter' => [703, 'AAA'],
            'last column' => [16384, 'XFD'],
        ];
    }

    /**
     * Return invalid coordinates.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function provideInvalidCoordinates(): array
    {
        return [
            'column 0' => [0, 1],
            'column too large' => [16385, 1],
            'row 0' => [1, 0],
            'row too large' => [1, 1048577],
            'negative' => [-1, -1],
        ];
    }

    /**
     * Column indexes are converted to letters.
     *
     * @param int $col column index
     * @param string $letters expected letters
     *
     * @return void
     */
    #[DataProvider('provideColumnLetters')]
    public function testReturnsColumnLetters(int $col, string $letters): void
    {
        self::assertSame($letters, Coordinate::getColumnLetters($col));
        self::assertSame($letters.'7', Coordinate::getReference($col, 7));
    }

    /**
     * Coordinates outside the sheet throw.
     *
     * @param int $col column index
     * @param int $row row index
     *
     * @return void
     */
    #[DataProvider('provideInvalidCoordinates')]
    public function testRejectsInvalidCoordinates(int $col, int $row): void
    {
        $this->expectException(InvalidArgumentException::class);
        Coordinate::validate($col, $row);
    }

    /**
     * The sheet limits are valid.
     *
     * @return void
     */
    public function testAcceptsLimits(): void
    {
        Coordinate::validate(1, 1);
        Coordinate::validate(16384, 1048576);
        self::assertSame([1, 1, 16384, 1048576], Coordinate::normalizeRange(1, 1, 16384, 1048576));
        self::assertSame([3, 3, 3, 3], Coordinate::normalizeRange(3, 3, 3, 3));
    }

    /**
     * A range given by any two opposite corners is returned with its start before its end, as in PhpSpreadsheet.
     *
     * @return void
     */
    public function testCorrectsReversedRange(): void
    {
        self::assertSame([8, 8, 9, 9], Coordinate::normalizeRange(9, 9, 8, 8));
        self::assertSame([1, 1, 3, 2], Coordinate::normalizeRange(3, 1, 1, 2));
        self::assertSame([5, 3, 6, 4], Coordinate::normalizeRange(5, 4, 6, 3));
    }

    /**
     * A range with an invalid corner throws.
     *
     * @return void
     */
    public function testRejectsRangeOutsideSheet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Coordinate::normalizeRange(1, 1, 16385, 1);
    }
}
