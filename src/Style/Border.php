<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Style;

use SpreadsheetWriter\Exception\InvalidArgumentException;
use SpreadsheetWriter\Internal\Color;

/**
 * Border drawn on all four edges of every cell of a styled range.
 */
final readonly class Border
{
    /**
     * border color in the format 'RRGGBB' (upper case)
     * @var string
     */
    public string $color;

    /**
     * Create a border.
     *
     * @param BorderStyle $style line style
     * @param string $color line color in the format 'RRGGBB'
     *
     * @throws InvalidArgumentException if the color is not in the format 'RRGGBB'
     */
    public function __construct(public BorderStyle $style = BorderStyle::Thin, string $color = '000000')
    {
        $this->color = Color::normalize($color);
    }

    /**
     * Return a string that is identical for borders with identical properties.
     *
     * @internal used by the writers
     * @return string
     */
    public function getKey(): string
    {
        return $this->style->name.','.$this->color;
    }
}
