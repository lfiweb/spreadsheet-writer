<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Internal\NumberParser;

/**
 * Tests the detection of numeric strings.
 */
#[CoversClass(NumberParser::class)]
final class NumberParserTest extends TestCase
{
    /**
     * Return numeric strings with the expected number and number of decimals.
     *
     * @return array<string, array{0: string, 1: int|float, 2: int}>
     */
    public static function provideNumericStrings(): array
    {
        return [
            'integer' => ['12', 12, 0],
            'decimals kept' => ['12.30', 12.3, 2],
            'negative with zero decimal' => ['-5.0', -5.0, 1],
            'plus sign' => ['+5', 5, 0],
            'leading decimal point' => ['.5', 0.5, 1],
            'trailing decimal point' => ['12.', 12.0, 0],
            'zero' => ['0', 0, 0],
            'zero with decimals' => ['0.5', 0.5, 1],
            'exponent' => ['1e3', 1000.0, 0],
            'exponent with decimals' => ['1.5E2', 150.0, 0],
            'negative exponent' => ['1e-3', 0.001, 0],
            'largest integer' => ['999999999999999', 999999999999999, 0],
            'trailing line break' => ["12.30\n", 12.3, 2],
            'integer with trailing line break' => ["12\n", 12, 0],
            'decimals capped at 30' => ['1.0000000000000000000000000000001', 1.0, 30],
        ];
    }

    /**
     * Return strings that are not stored as numbers.
     *
     * @return array<string, array{0: string}>
     */
    public static function provideNonNumericStrings(): array
    {
        return [
            'leading zero' => ['007'],
            'negative leading zero' => ['-007'],
            'double zero' => ['00'],
            'decimal point only' => ['.'],
            'minus only' => ['-'],
            'empty' => [''],
            'leading space' => [' 12'],
            'trailing space' => ['12 '],
            'two trailing line breaks' => ["12\n\n"],
            'trailing carriage return' => ["12\r\n"],
            'leading line break' => ["\n12"],
            'hexadecimal' => ['0x1A'],
            'underscore' => ['1_000'],
            'NaN' => ['NaN'],
            'INF' => ['INF'],
            'decimal comma' => ['1,5'],
            'formula' => ['=1'],
            'overflow' => ['1e400'],
            'integer too large' => ['1000000000000000'],
            'negative integer too large' => ['-1000000000000000'],
        ];
    }

    /**
     * Numeric strings return the number and the number of decimals.
     *
     * @param string $value the string
     * @param int|float $number expected number
     * @param int $decimals expected number of decimals
     *
     * @return void
     */
    #[DataProvider('provideNumericStrings')]
    public function testParsesNumericStrings(string $value, int|float $number, int $decimals): void
    {
        self::assertSame([$number, $decimals], NumberParser::parse($value));
    }

    /**
     * Other strings return null.
     *
     * @param string $value the string
     *
     * @return void
     */
    #[DataProvider('provideNonNumericStrings')]
    public function testRejectsNonNumericStrings(string $value): void
    {
        self::assertNull(NumberParser::parse($value));
    }

    /**
     * Integers beyond 15 digits are too large.
     *
     * @return void
     */
    public function testDetectsIntegersTooLarge(): void
    {
        self::assertFalse(NumberParser::isIntegerTooLarge(999999999999999));
        self::assertFalse(NumberParser::isIntegerTooLarge(-999999999999999));
        self::assertTrue(NumberParser::isIntegerTooLarge(1000000000000000));
        self::assertTrue(NumberParser::isIntegerTooLarge(-1000000000000000));
    }
}
