<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Style;

use SpreadsheetWriter\Exception\InvalidArgumentException;
use SpreadsheetWriter\Internal\Color;

/**
 * Font properties of a cell or of a rich text run.
 *
 * In a style, properties that are null are not set: they leave the current value of the cells unchanged.
 * In a rich text run, the font replaces the font of the cell, as in PhpSpreadsheet: the run is only bold
 * if $bold is true, and black if $color is null. This is also true for a font without any property.
 */
final readonly class Font
{
    /**
     * font color in the format 'RRGGBB' (upper case) or null if not set
     * @var string|null
     */
    public ?string $color;

    /**
     * Create a font.
     *
     * @param bool|null $bold true for bold text, false for normal text, null to leave unchanged (in a style)
     * @param string|null $color font color in the format 'RRGGBB', null to leave unchanged (in a style)
     *
     * @throws InvalidArgumentException if the color is not in the format 'RRGGBB'
     */
    public function __construct(public ?bool $bold = null, ?string $color = null)
    {
        $this->color = $color === null ? null : Color::normalize($color);
    }

    /**
     * Return a new font with the properties of $override layered on top of this font.
     *
     * Only the properties of $override that are not null replace the properties of this font.
     *
     * @internal used by the writers
     * @param Font $override font whose set properties take precedence
     *
     * @return Font
     */
    public function merge(Font $override): Font
    {
        return new Font($override->bold ?? $this->bold, $override->color ?? $this->color);
    }

    /**
     * Return true if no property is set.
     *
     * @internal used by the writers
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->bold === null && $this->color === null;
    }

    /**
     * Return a string that is identical for fonts with identical properties.
     *
     * @internal used by the writers
     * @return string
     */
    public function getKey(): string
    {
        $bold = $this->bold === null ? '-' : (int)$this->bold;

        return $bold.','.($this->color ?? '-');
    }
}
