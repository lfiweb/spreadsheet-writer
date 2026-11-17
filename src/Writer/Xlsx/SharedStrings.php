<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Xlsx;

use SpreadsheetWriter\Internal\DefaultFont;
use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Style\Font;

/**
 * The shared string table of an XLSX file (xl/sharedStrings.xml).
 *
 * Every distinct text is stored once, and the cells refer to it by its index.
 *
 * @internal
 */
final class SharedStrings
{
    /**
     * text color of a rich text run whose font sets no color
     */
    private const string DEFAULT_TEXT_COLOR = '000000';

    /**
     * indexes of the strings, indexed by string key
     * @var array<string, int>
     */
    private array $indexesByKey = [];

    /**
     * the <si> elements in index order
     * @var list<string>
     */
    private array $items = [];

    /**
     * number of cells referring to a string
     * @var int
     */
    private int $numReferences = 0;

    /**
     * Return the index of a text, adding it to the table if it is new.
     *
     * A rich text run with its own font gets run properties, which replace the cell font in Excel:
     * the run is only bold or colored if its own font says so, as in PhpSpreadsheet. This is also
     * true for a font without properties: the run is not bold and black.
     * Runs without a font have no run properties and use the cell font.
     *
     * @param string|RichText $text the text
     *
     * @return int
     */
    public function add(string|RichText $text): int
    {
        $key = is_string($text) ? 's'.$text : 'r'.$text->getKey();
        if (!isset($this->indexesByKey[$key])) {
            $this->indexesByKey[$key] = count($this->items);
            $this->items[] = is_string($text) ? $this->createPlainItem($text) : $this->createRichItem($text);
        }
        ++$this->numReferences;

        return $this->indexesByKey[$key];
    }

    /**
     * Return the XML of the shared string table.
     *
     * @return string
     */
    public function buildXml(): string
    {
        return Xml::DECLARATION
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' count="'.$this->numReferences.'" uniqueCount="'.count($this->items).'">'
            .implode('', $this->items)
            .'</sst>';
    }

    /**
     * Escape a text for an XLSX string element.
     *
     * Besides the XML escaping, this does the same as PhpSpreadsheet and Excel:
     * - a literal '_xHHHH_' is escaped as '_x005F_xHHHH_', because Excel would read it as the escaped character U+HHHH
     * - a control character that is not allowed in XML is written as '_xHHHH_', so Excel keeps it
     *
     * @param string $text the text
     *
     * @return string
     */
    public static function escape(string $text): string
    {
        $literal = preg_replace('/_(x[0-9A-Fa-f]{4}_)/', '_x005F_$1', $text) ?? $text;
        $encoded = preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
            static fn(array $match): string => sprintf('_x%04X_', ord($match[0])),
            $literal
        ) ?? $literal;

        return Xml::escape($encoded);
    }

    /**
     * Return the <si> element of a plain text.
     *
     * @param string $text the text
     *
     * @return string
     */
    private function createPlainItem(string $text): string
    {
        return '<si><t xml:space="preserve">'.self::escape($text).'</t></si>';
    }

    /**
     * Return the <si> element of a rich text, one <r> element per run.
     *
     * @param RichText $text the rich text
     *
     * @return string
     */
    private function createRichItem(RichText $text): string
    {
        $runs = '';
        foreach ($text->getRuns() as $run) {
            $properties = '';
            if ($run->font !== null) {
                $properties = $this->createRunProperties($run->font);
            }
            $runs .= '<r>'.$properties.'<t xml:space="preserve">'.self::escape($run->text).'</t></r>';
        }

        return '<si>'.($runs === '' ? '<t/>' : $runs).'</si>';
    }

    /**
     * Return the <rPr> element of a run with the given font.
     *
     * @param Font $font font of the run
     *
     * @return string
     */
    private function createRunProperties(Font $font): string
    {
        $xml = '<rPr>';
        if ($font->bold === true) {
            $xml .= '<b/>';
        }
        $xml .= '<sz val="'.DefaultFont::SIZE.'"/>';
        $xml .= '<color rgb="FF'.($font->color ?? self::DEFAULT_TEXT_COLOR).'"/>';
        $xml .= '<rFont val="'.DefaultFont::NAME.'"/><family val="2"/>';

        return $xml.'</rPr>';
    }
}
