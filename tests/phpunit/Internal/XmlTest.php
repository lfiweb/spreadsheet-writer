<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Internal\Color;
use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\Exception\InvalidArgumentException;

/**
 * Tests the XML helpers and the color validation.
 */
#[CoversClass(Xml::class)]
#[CoversClass(Color::class)]
final class XmlTest extends TestCase
{
    /**
     * Return texts with their escaped form.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideTexts(): array
    {
        return [
            'special characters' => ['a&<>"\'b', 'a&amp;&lt;&gt;&quot;&apos;b'],
            'white space kept' => ["a\tb\nc\rd", "a\tb\nc\rd"],
            'control characters removed' => ["a\x01b\x0Bc\x1Fd", 'abcd'],
            'invalid UTF-8 replaced' => ["a\xC3b", 'a?b'],
            'non-characters removed' => ["a\u{FFFE}b\u{FFFF}c", 'abc'],
            'multibyte kept' => ['m³ äöü 😀', 'm³ äöü 😀'],
        ];
    }

    /**
     * Return numbers with their XML form.
     *
     * @return array<string, array{0: int|float, 1: string}>
     */
    public static function provideNumbers(): array
    {
        return [
            'integer' => [-3, '-3'],
            'whole float' => [1.0, '1.0'],
            'large whole float' => [1000.0, '1000.0'],
            'negative zero' => [-0.0, '-0.0'],
            '17 digits' => [0.1 + 0.2, '0.30000000000000004'],
            'smallest float' => [PHP_FLOAT_MIN, '2.2250738585072014E-308'],
            'largest float' => [PHP_FLOAT_MAX, '1.7976931348623157E+308'],
            'decimal' => [0.1, '0.1'],
            'full precision' => [1 / 3, '0.3333333333333333'],
            'exponent' => [1e25, '1.0E+25'],
            'small exponent' => [1.5e-7, '1.5E-7'],
        ];
    }

    /**
     * Floats are formatted the same with any serialize_precision setting.
     *
     * @return void
     */
    public function testFormatsNumbersIndependentOfPrecisionSetting(): void
    {
        $previous = ini_set('serialize_precision', '17');
        try {
            self::assertSame('12.3', Xml::formatNumber(12.3));
            self::assertSame('1.0E+25', Xml::formatNumber(1e25));
        } finally {
            ini_set('serialize_precision', (string)$previous);
        }
    }

    /**
     * Texts are escaped for XML.
     *
     * @param string $text the text
     * @param string $expected expected escaped text
     *
     * @return void
     */
    #[DataProvider('provideTexts')]
    public function testEscapesText(string $text, string $expected): void
    {
        self::assertSame($expected, Xml::escape($text));
    }

    /**
     * Numbers are formatted without losing precision.
     *
     * @param int|float $number the number
     * @param string $expected expected text
     *
     * @return void
     */
    #[DataProvider('provideNumbers')]
    public function testFormatsNumbers(int|float $number, string $expected): void
    {
        self::assertSame($expected, Xml::formatNumber($number));
        self::assertSame((float)$number, (float)$expected);
    }

    /**
     * Colors are normalized to upper case.
     *
     * @return void
     */
    public function testNormalizesColors(): void
    {
        self::assertSame('00AAFF', Color::normalize('00aaff'));
    }

    /**
     * Colors not in the format 'RRGGBB' throw.
     *
     * @return void
     */
    public function testRejectsInvalidColors(): void
    {
        foreach (['red', '#00AAFF', 'FFF', 'FF00AAFF', '00AAGG', ''] as $color) {
            try {
                Color::normalize($color);
                self::fail("'$color' is accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
