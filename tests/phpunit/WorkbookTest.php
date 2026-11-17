<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Sheet;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\TextRun;
use SpreadsheetWriter\Workbook;

/**
 * Tests the workbook and the rich text.
 */
#[CoversClass(Workbook::class)]
#[CoversClass(RichText::class)]
#[CoversClass(TextRun::class)]
final class WorkbookTest extends TestCase
{
    /**
     * A workbook has one sheet, which is always the same instance.
     *
     * @return void
     */
    public function testHasOneSheet(): void
    {
        $workbook = new Workbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setValue(1, 1, 'a');

        self::assertSame($sheet, $workbook->getActiveSheet());
        self::assertSame('Worksheet', Sheet::TITLE);
        self::assertNotSame($sheet, (new Workbook())->getActiveSheet());
    }

    /**
     * Rich text keeps its runs and returns the plain text and a key.
     *
     * @return void
     */
    public function testBuildsRichText(): void
    {
        $font = new Font(color: '006268');
        $richText = (new RichText())->addText('Einheit', $font)->addText(': m³');

        self::assertEquals([new TextRun('Einheit', $font), new TextRun(': m³')], $richText->getRuns());
        self::assertSame('Einheit: m³', $richText->getPlainText());
        self::assertSame('', (new RichText())->getPlainText());
        self::assertSame(
            $richText->getKey(),
            (new RichText())->addText('Einheit', new Font(color: '006268'))->addText(': m³')->getKey()
        );
        self::assertNotSame(
            $richText->getKey(),
            (new RichText())->addText('Einheit: ', $font)->addText('m³')->getKey(),
            'The key depends on how the text is split into runs.'
        );
    }

    /**
     * Rich text is not converted to a string silently.
     *
     * @return void
     */
    public function testRichTextIsNotStringable(): void
    {
        self::assertNotInstanceOf(\Stringable::class, new RichText());
    }
}
