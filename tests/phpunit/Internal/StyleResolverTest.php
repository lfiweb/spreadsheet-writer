<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\Sheet;
use SpreadsheetWriter\Style\Border;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Style\HorizontalAlignment;
use SpreadsheetWriter\Style\Style;
use SpreadsheetWriter\Style\VerticalAlignment;

/**
 * Tests the resolution of layered style ranges into one style per cell.
 */
#[CoversClass(StyleResolver::class)]
final class StyleResolverTest extends TestCase
{
    /**
     * Overlapping ranges are layered in the order they were added.
     *
     * @return void
     */
    public function testLayersStyles(): void
    {
        $sheet = new Sheet();
        $sheet->setStyle(1, 1, 2, 2, new Style(horizontal: HorizontalAlignment::Left, border: new Border()));
        $sheet->setStyle(2, 2, 3, 3, new Style(horizontal: HorizontalAlignment::Right, vertical: VerticalAlignment::Top));
        $resolver = new StyleResolver();
        $ids = $resolver->resolveSheet($sheet);

        self::assertSame([1, 2, 3], array_keys($ids));
        self::assertSame([1, 2], array_keys($ids[1]));
        self::assertSame([2, 3], array_keys($ids[3]));
        $a1 = $resolver->getStyle($ids[1][1]);
        self::assertSame(HorizontalAlignment::Left, $a1->horizontal);
        self::assertNull($a1->vertical);
        $b2 = $resolver->getStyle($ids[2][2]);
        self::assertSame(HorizontalAlignment::Right, $b2->horizontal);
        self::assertSame(VerticalAlignment::Top, $b2->vertical);
        self::assertNotNull($b2->border, 'The border of the first range is kept.');
        $c3 = $resolver->getStyle($ids[3][3]);
        self::assertNull($c3->border);
    }

    /**
     * Equal styles get the same id, also across sheets, and empty styles are not stored.
     *
     * @return void
     */
    public function testReusesIds(): void
    {
        $first = new Sheet();
        $first->setStyle(1, 1, 1, 1, new Style(font: new Font(bold: true)));
        $first->setStyle(2, 1, 2, 1, new Style());
        $first->setStyle(3, 1, 3, 1, new Style(font: new Font()));
        $second = new Sheet();
        $second->setStyle(5, 5, 5, 5, new Style(font: new Font(bold: true)));
        $resolver = new StyleResolver();
        $firstIds = $resolver->resolveSheet($first);
        $secondIds = $resolver->resolveSheet($second);

        self::assertSame([1 => [1 => $firstIds[1][1]]], $firstIds, 'Empty styles leave the cells unstyled.');
        self::assertSame($firstIds[1][1], $secondIds[5][5]);
        self::assertNotSame(StyleResolver::DEFAULT_ID, $firstIds[1][1]);
        self::assertSame('-,-|-|-|-|-', $resolver->getStyle(StyleResolver::DEFAULT_ID)->getKey());
    }
}
