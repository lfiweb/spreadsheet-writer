<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Test\Writer\Ods;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Writer\Ods\StyleTable;
use SpreadsheetWriter\Writer\Ods\TextXml;

/**
 * Tests the encoding of line breaks, tabs, spaces and rich text runs in ODS paragraphs.
 */
#[CoversClass(TextXml::class)]
final class TextXmlTest extends TestCase
{
    /**
     * Return texts with their paragraphs.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideTexts(): array
    {
        return [
            'plain' => ['a b', '<text:p>a b</text:p>'],
            'empty' => ['', '<text:p/>'],
            'escaped' => ['<&>', '<text:p>&lt;&amp;&gt;</text:p>'],
            'line break' => ["a\nb", '<text:p>a</text:p><text:p>b</text:p>'],
            'Windows line break' => ["a\r\nb", '<text:p>a</text:p><text:p>b</text:p>'],
            'old Mac line break' => ["a\rb", '<text:p>a</text:p><text:p>b</text:p>'],
            'empty lines' => ["\n", '<text:p/><text:p/>'],
            'leading space' => [' a', '<text:p><text:s/>a</text:p>'],
            'trailing space' => ['a ', '<text:p>a<text:s/></text:p>'],
            'only spaces' => ['   ', '<text:p><text:s text:c="3"/></text:p>'],
            'space sequences' => ['  two  spaces  ', '<text:p><text:s text:c="2"/>two <text:s/>spaces<text:s text:c="2"/></text:p>'],
            'space after line break' => ["a\n b", '<text:p>a</text:p><text:p><text:s/>b</text:p>'],
            'tab' => ["a\tb", '<text:p>a<text:tab/>b</text:p>'],
            'space after tab' => ["a\t b", '<text:p>a<text:tab/><text:s/>b</text:p>'],
            'tabs and spaces' => ["tab\there \t x", '<text:p>tab<text:tab/>here <text:tab/><text:s/>x</text:p>'],
        ];
    }

    /**
     * Plain texts are encoded.
     *
     * @param string $text the text
     * @param string $expected expected paragraphs
     *
     * @return void
     */
    #[DataProvider('provideTexts')]
    public function testEncodesPlainText(string $text, string $expected): void
    {
        self::assertSame($expected, TextXml::build($text, new StyleTable(new StyleResolver())));
    }

    /**
     * Runs with a font become spans that set the weight and the color, spaces are encoded across run boundaries.
     *
     * @return void
     */
    public function testEncodesRichText(): void
    {
        $styleTable = new StyleTable(new StyleResolver());
        $bold = new Font(bold: true);
        $richText = (new RichText())
            ->addText('a ', $bold)
            ->addText(' b ')
            ->addText(' ', new Font(color: 'FF0000'))
            ->addText("\nc", $bold)
            ->addText('', $bold)
            ->addText('d', new Font());

        self::assertSame(
            '<text:p><text:span text:style-name="T1">a </text:span><text:s/>b <text:span text:style-name="T2"><text:s/></text:span></text:p>'
            .'<text:p><text:span text:style-name="T1">c</text:span><text:span text:style-name="T3">d</text:span></text:p>',
            TextXml::build($richText, $styleTable)
        );
        self::assertSame('<text:p/>', TextXml::build(new RichText(), $styleTable));
        self::assertStringContainsString(
            '<style:style style:name="T1" style:family="text"><style:text-properties fo:font-weight="bold"'
            .' style:font-weight-asian="bold" style:font-weight-complex="bold" fo:color="#000000"/></style:style>',
            $styleTable->buildXml(),
            'A bold run is black, also in a colored cell.'
        );
        self::assertStringContainsString(
            '<style:style style:name="T2" style:family="text"><style:text-properties fo:font-weight="normal"'
            .' style:font-weight-asian="normal" style:font-weight-complex="normal" fo:color="#FF0000"/></style:style>',
            $styleTable->buildXml(),
            'A colored run is not bold, also in a bold cell.'
        );
        self::assertStringContainsString(
            '<style:style style:name="T3" style:family="text"><style:text-properties fo:font-weight="normal"'
            .' style:font-weight-asian="normal" style:font-weight-complex="normal" fo:color="#000000"/></style:style>',
            $styleTable->buildXml(),
            'A run with a font without properties is not bold and black, as in PhpSpreadsheet.'
        );
    }
}
