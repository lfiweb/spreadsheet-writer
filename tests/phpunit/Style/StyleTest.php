<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Style;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Exception\InvalidArgumentException;
use SpreadsheetWriter\Style\Border;
use SpreadsheetWriter\Style\BorderStyle;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Style\HorizontalAlignment;
use SpreadsheetWriter\Style\Style;
use SpreadsheetWriter\Style\VerticalAlignment;

/**
 * Tests the style value objects and how they are layered.
 */
#[CoversClass(Style::class)]
#[CoversClass(Font::class)]
#[CoversClass(Border::class)]
final class StyleTest extends TestCase
{
    /**
     * Only the set properties of the override replace the base properties, fonts are merged property by property.
     *
     * @return void
     */
    public function testMergesStyles(): void
    {
        $base = new Style(
            font: new Font(bold: true, color: '111111'),
            horizontal: HorizontalAlignment::Left,
            vertical: VerticalAlignment::Bottom,
            textWrapped: true,
            border: new Border(),
        );
        $merged = $base->merge(new Style(font: new Font(color: '222222'), vertical: VerticalAlignment::Center, textWrapped: false));

        self::assertTrue($merged->font?->bold);
        self::assertSame('222222', $merged->font->color);
        self::assertSame(HorizontalAlignment::Left, $merged->horizontal);
        self::assertSame(VerticalAlignment::Center, $merged->vertical);
        self::assertFalse($merged->textWrapped);
        self::assertSame($base->border, $merged->border);

        $unchanged = $base->merge(new Style());
        self::assertSame($base->font, $unchanged->font);
        self::assertSame(HorizontalAlignment::Left, $unchanged->horizontal);
        self::assertSame(VerticalAlignment::Bottom, $unchanged->vertical);
        self::assertTrue($unchanged->textWrapped);
        self::assertSame($base->border, $unchanged->border);
    }

    /**
     * A font is taken over if only one of the styles has one.
     *
     * @return void
     */
    public function testMergesMissingFonts(): void
    {
        $font = new Font(bold: true);
        self::assertSame($font, (new Style())->merge(new Style(font: $font))->font);
        self::assertSame($font, (new Style(font: $font))->merge(new Style())->font);
    }

    /**
     * Fonts are merged property by property.
     *
     * @return void
     */
    public function testMergesFonts(): void
    {
        $merged = (new Font(bold: true, color: 'aaaaaa'))->merge(new Font(bold: false));
        self::assertFalse($merged->bold);
        self::assertSame('AAAAAA', $merged->color);
        self::assertTrue((new Font())->isEmpty());
        self::assertFalse((new Font(bold: false))->isEmpty());
    }

    /**
     * Keys are equal for equal properties and differ otherwise.
     *
     * @return void
     */
    public function testCreatesKeys(): void
    {
        self::assertSame((new Style(font: new Font(color: 'aaaaaa')))->getKey(), (new Style(font: new Font(color: 'AAAAAA')))->getKey());
        self::assertSame((new Style())->getKey(), (new Style(font: new Font()))->getKey());
        self::assertNotSame((new Style(textWrapped: true))->getKey(), (new Style(textWrapped: false))->getKey());
        self::assertNotSame((new Font(bold: false))->getKey(), (new Font())->getKey());
        self::assertNotSame((new Border(color: '000000'))->getKey(), (new Border(color: '000001'))->getKey());
    }

    /**
     * Borders default to thin black lines.
     *
     * @return void
     */
    public function testCreatesBorders(): void
    {
        $border = new Border();
        self::assertSame(BorderStyle::Thin, $border->style);
        self::assertSame('000000', $border->color);
        self::assertSame('ABCDEF', (new Border(color: 'abcdef'))->color);
    }

    /**
     * Invalid colors throw.
     *
     * @return void
     */
    public function testRejectsInvalidFontColor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Font(color: 'green');
    }

    /**
     * Invalid colors throw.
     *
     * @return void
     */
    public function testRejectsInvalidBorderColor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Border(color: '#000000');
    }
}
