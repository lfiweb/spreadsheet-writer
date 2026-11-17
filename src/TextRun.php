<?php

declare(strict_types=1);

namespace SpreadsheetWriter;

use SpreadsheetWriter\Style\Font;

/**
 * A piece of text within a RichText with its own font.
 */
final readonly class TextRun
{
    /**
     * Create a text run.
     *
     * @param string $text the text
     * @param Font|null $font font of the text, it replaces the font of the cell (a bold cell doesn't make
     *     the run bold, also if the font sets no property); null to use the font of the cell
     */
    public function __construct(public string $text, public ?Font $font = null)
    {
    }
}
