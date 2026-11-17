<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Ods;

use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\RichText;

/**
 * Builds the <text:p> paragraphs of a text cell.
 *
 * ODF collapses white space in paragraphs like HTML does, so the text is encoded:
 * - every line becomes its own <text:p>
 * - tabs become <text:tab/>
 * - spaces that would be lost (at the start or end of a paragraph, after a tab and every space
 *   after the first one of a sequence) become <text:s/>
 * - rich text runs with a font, also one without properties, become <text:span> with a text style
 *
 * @internal
 */
final class TextXml
{
    /**
     * Return the paragraphs of a plain or rich text.
     *
     * @param string|RichText $text the text
     * @param StyleTable $styleTable style table that provides the text styles of rich text runs
     *
     * @return string one or more <text:p> elements
     */
    public static function build(string|RichText $text, StyleTable $styleTable): string
    {
        $segments = [];
        if (is_string($text)) {
            $segments[] = [null, $text];
        } else {
            foreach ($text->getRuns() as $run) {
                $styleName = $run->font === null ? null : $styleTable->getTextStyleName($run->font);
                $segments[] = [$styleName, $run->text];
            }
        }
        $xml = '';
        foreach (self::splitIntoParagraphs($segments) as $paragraph) {
            $content = self::createParagraphContent($paragraph);
            $xml .= $content === '' ? '<text:p/>' : '<text:p>'.$content.'</text:p>';
        }

        return $xml;
    }

    /**
     * Split the segments at line breaks into paragraphs. Empty segments are dropped.
     *
     * @param list<array{0: string|null, 1: string}> $segments [text style name or null, text]
     *
     * @return list<list<array{0: string|null, 1: string}>> the segments of each paragraph
     */
    private static function splitIntoParagraphs(array $segments): array
    {
        $paragraphs = [[]];
        $current = 0;
        foreach ($segments as [$styleName, $text]) {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $paragraphs[++$current] = [];
                }
                if ($line !== '') {
                    $paragraphs[$current][] = [$styleName, $line];
                }
            }
        }

        return $paragraphs;
    }

    /**
     * Return the content of a <text:p> element.
     *
     * @param list<array{0: string|null, 1: string}> $segments the segments of the paragraph without line breaks
     *
     * @return string
     */
    private static function createParagraphContent(array $segments): string
    {
        $xml = '';
        // true at the start of the paragraph, after a tab and after a space
        $afterSpace = true;
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => [$styleName, $text]) {
            $encoded = '';
            $pieces = explode("\t", Xml::escape($text));
            $lastPiece = count($pieces) - 1;
            foreach ($pieces as $i => $piece) {
                if ($i > 0) {
                    $encoded .= '<text:tab/>';
                    $afterSpace = true;
                }
                $encoded .= self::encodeSpaces($piece, $afterSpace, $index === $lastIndex && $i === $lastPiece);
                if ($piece !== '') {
                    $afterSpace = str_ends_with($piece, ' ');
                }
            }
            $xml .= $styleName === null ? $encoded : '<text:span text:style-name="'.$styleName.'">'.$encoded.'</text:span>';
        }

        return $xml;
    }

    /**
     * Encode the spaces of an escaped piece of text without tabs and line breaks.
     *
     * @param string $piece the escaped text
     * @param bool $afterSpace true if the piece follows the start of the paragraph, a tab or a space
     * @param bool $atEnd true if the piece ends the paragraph
     *
     * @return string
     */
    private static function encodeSpaces(string $piece, bool $afterSpace, bool $atEnd): string
    {
        $encoded = $piece;
        if (str_contains($piece, ' ')) {
            $length = strlen($piece);
            $encoded = preg_replace_callback(
                '/ +/',
                static function (array $match) use ($afterSpace, $atEnd, $length): string {
                    [$spaces, $offset] = $match[0];
                    $count = strlen($spaces);
                    $kept = ($offset === 0 && $afterSpace) || ($atEnd && $offset + $count === $length) ? 0 : 1;
                    $count -= $kept;
                    $xml = $kept === 1 ? ' ' : '';
                    if ($count > 0) {
                        $xml .= $count === 1 ? '<text:s/>' : '<text:s text:c="'.$count.'"/>';
                    }

                    return $xml;
                },
                $piece,
                flags: PREG_OFFSET_CAPTURE
            ) ?? $piece;
        }

        return $encoded;
    }
}
