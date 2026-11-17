<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Style;

/**
 * Style applied to a range of cells with Sheet::setStyle().
 *
 * Properties that are null are not set, i.e. they leave the current value of the cells unchanged.
 * This allows layering several styles on the same cells, e.g. first setting the alignment
 * and then the border of an overlapping range.
 */
final readonly class Style
{
    /**
     * Create a style.
     *
     * @param Font|null $font font properties, null to leave unchanged
     * @param HorizontalAlignment|null $horizontal horizontal alignment, null to leave unchanged
     * @param VerticalAlignment|null $vertical vertical alignment, null to leave unchanged
     * @param bool|null $textWrapped true to wrap the text at the cell border, null to leave unchanged
     * @param Border|null $border border on all four edges of every cell, null to leave unchanged
     */
    public function __construct(
        public ?Font $font = null,
        public ?HorizontalAlignment $horizontal = null,
        public ?VerticalAlignment $vertical = null,
        public ?bool $textWrapped = null,
        public ?Border $border = null,
    ) {
    }

    /**
     * Return a new style with the properties of $override layered on top of this style.
     *
     * Only the properties of $override that are not null replace the properties of this style.
     * Fonts are merged property by property.
     *
     * @internal used by the writers
     * @param Style $override style whose set properties take precedence
     *
     * @return Style
     */
    public function merge(Style $override): Style
    {
        $font = $override->font ?? $this->font;
        if ($this->font !== null && $override->font !== null) {
            $font = $this->font->merge($override->font);
        }

        return new Style(
            $font,
            $override->horizontal ?? $this->horizontal,
            $override->vertical ?? $this->vertical,
            $override->textWrapped ?? $this->textWrapped,
            $override->border ?? $this->border,
        );
    }

    /**
     * Return a string that is identical for styles that produce the same formatting.
     *
     * A style without a font and a style with an empty font have the same key.
     *
     * @internal used by the writers
     * @return string
     */
    public function getKey(): string
    {
        $wrapped = $this->textWrapped === null ? '-' : (int)$this->textWrapped;

        return implode('|', [
            $this->font?->getKey() ?? '-,-',
            $this->horizontal->name ?? '-',
            $this->vertical->name ?? '-',
            $wrapped,
            $this->border?->getKey() ?? '-',
        ]);
    }
}
