<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Ods;

use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\Style\Border;
use SpreadsheetWriter\Style\BorderStyle;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Style\HorizontalAlignment;
use SpreadsheetWriter\Style\Style;
use SpreadsheetWriter\Style\VerticalAlignment;

/**
 * The automatic styles of an ODS file (<office:automatic-styles> in content.xml).
 *
 * Collects the cell styles (ce1, ce2, ...), the number styles they refer to (N1, ...),
 * the text styles of rich text runs (T1, ...), the column styles (co1, ...) and the row styles (ro1, ...).
 *  *
 * @internal
 */
final class StyleTable
{
    /**
     * pixels per character of a column width, for Calibri 11
     *
     * The width is rounded to whole pixels, like Excel shows it and PhpSpreadsheet's ODS writer writes it,
     * and written in points, which are exact for whole pixels (140 px = 105pt), while centimeters would be rounded.
     */
    private const float PIXELS_PER_CHARACTER = 64 / 9.140625;

    /**
     * text color of a rich text run whose font sets no color
     */
    private const string DEFAULT_TEXT_COLOR = '000000';

    /**
     * points per pixel at 96 dpi
     */
    private const float POINTS_PER_PIXEL = 0.75;

    /**
     * cell style names indexed by 'styleId:decimals', null for the default style
     * @var array<string, string|null>
     */
    private array $cellStyleNames = [];

    /**
     * the cell <style:style> elements
     * @var list<string>
     */
    private array $cellStyles = [];

    /**
     * number style names indexed by number of decimals
     * @var array<int, string>
     */
    private array $numberStyleNames = [];

    /**
     * text style names indexed by font key
     * @var array<string, string>
     */
    private array $textStyleNames = [];

    /**
     * the text <style:style> elements
     * @var list<string>
     */
    private array $textStyles = [];

    /**
     * column style names indexed by the formatted width in points
     * @var array<string, string>
     */
    private array $columnStyleNames = [];

    /**
     * row style names indexed by the formatted height
     * @var array<string, string>
     */
    private array $rowStyleNames = [];

    /**
     * Create an empty style table.
     *
     * @param StyleResolver $resolver resolver of the workbook
     */
    public function __construct(private readonly StyleResolver $resolver)
    {
    }

    /**
     * Return the resolver that maps the styled ranges of a sheet to style ids.
     *
     * @return StyleResolver
     */
    public function getResolver(): StyleResolver
    {
        return $this->resolver;
    }

    /**
     * Return the name of the cell style for a style id and a number of decimals.
     *
     * @param int $styleId style id from the StyleResolver
     * @param int $decimals number of decimals to display, 0 for the default number format
     *
     * @return string|null the style name, or null if the cell uses the default style
     */
    public function getCellStyleName(int $styleId, int $decimals): ?string
    {
        $key = $styleId.':'.$decimals;
        if (!array_key_exists($key, $this->cellStyleNames)) {
            $this->cellStyleNames[$key] = $styleId === StyleResolver::DEFAULT_ID && $decimals === 0
                ? null
                : $this->registerCellStyle($this->resolver->getStyle($styleId), $decimals);
        }

        return $this->cellStyleNames[$key];
    }

    /**
     * Return the name of the text style of a rich text run.
     *
     * A span would inherit the font of the cell. To get the same result as in XLSX and in PhpSpreadsheet,
     * the style always sets the weight and the color: a run is only bold or colored if its own font says so.
     *
     * @param Font $font font of the run
     *
     * @return string
     */
    public function getTextStyleName(Font $font): string
    {
        $runFont = new Font($font->bold ?? false, $font->color ?? self::DEFAULT_TEXT_COLOR);
        $key = $runFont->getKey();
        if (!isset($this->textStyleNames[$key])) {
            $name = 'T'.(count($this->textStyles) + 1);
            $this->textStyleNames[$key] = $name;
            $this->textStyles[] = '<style:style style:name="'.$name.'" style:family="text">'
                .$this->createTextProperties($runFont).'</style:style>';
        }

        return $this->textStyleNames[$key];
    }

    /**
     * Return the name of the column style with the given width.
     *
     * @param float $width width in characters (Excel's unit)
     *
     * @return string
     */
    public function getColumnStyleName(float $width): string
    {
        $points = Xml::formatNumber(round($width * self::PIXELS_PER_CHARACTER) * self::POINTS_PER_PIXEL);
        if (!isset($this->columnStyleNames[$points])) {
            $this->columnStyleNames[$points] = 'co'.(count($this->columnStyleNames) + 1);
        }

        return $this->columnStyleNames[$points];
    }

    /**
     * Return the name of the row style with the given height.
     *
     * @param float $points height in points
     *
     * @return string
     */
    public function getRowStyleName(float $points): string
    {
        $height = Xml::formatNumber($points);
        if (!isset($this->rowStyleNames[$height])) {
            $this->rowStyleNames[$height] = 'ro'.(count($this->rowStyleNames) + 1);
        }

        return $this->rowStyleNames[$height];
    }

    /**
     * Return the <office:automatic-styles> element with all styles used so far.
     *
     * @return string
     */
    public function buildXml(): string
    {
        $xml = '';
        foreach ($this->numberStyleNames as $decimals => $name) {
            $xml .= '<number:number-style style:name="'.$name.'">'
                .'<number:number number:decimal-places="'.$decimals.'" number:min-integer-digits="1"/>'
                .'</number:number-style>';
        }
        foreach ($this->columnStyleNames as $points => $name) {
            $xml .= '<style:style style:name="'.$name.'" style:family="table-column">'
                .'<style:table-column-properties style:column-width="'.$points.'pt"/></style:style>';
        }
        foreach ($this->rowStyleNames as $points => $name) {
            $xml .= '<style:style style:name="'.$name.'" style:family="table-row">'
                .'<style:table-row-properties style:row-height="'.$points.'pt" style:use-optimal-row-height="false"/>'
                .'</style:style>';
        }
        $xml .= implode('', $this->cellStyles).implode('', $this->textStyles);

        return $xml === '' ? '<office:automatic-styles/>' : '<office:automatic-styles>'.$xml.'</office:automatic-styles>';
    }

    /**
     * Add the cell style of a style and a number of decimals and return its name.
     *
     * @param Style $style the resolved style
     * @param int $decimals number of decimals to display, 0 for the default number format
     *
     * @return string
     */
    private function registerCellStyle(Style $style, int $decimals): string
    {
        $name = 'ce'.(count($this->cellStyles) + 1);
        $xml = '<style:style style:name="'.$name.'" style:family="table-cell"'
            .' style:parent-style-name="'.PackageXml::DEFAULT_CELL_STYLE.'"';
        if ($decimals > 0) {
            $xml .= ' style:data-style-name="'.$this->registerNumberStyle($decimals).'"';
        }
        $xml .= '>'.$this->createTableCellProperties($style);
        if ($style->horizontal !== null) {
            $xml .= '<style:paragraph-properties fo:text-align="'.match ($style->horizontal) {
                HorizontalAlignment::Left => 'start',
                HorizontalAlignment::Center => 'center',
                HorizontalAlignment::Right => 'end',
            }.'"/>';
        }
        if ($style->font !== null && !$style->font->isEmpty()) {
            $xml .= $this->createTextProperties($style->font);
        }
        $this->cellStyles[] = $xml.'</style:style>';

        return $name;
    }

    /**
     * Return the <style:table-cell-properties> element of a style, or an empty string if it has no such properties.
     *
     * @param Style $style the resolved style
     *
     * @return string
     */
    private function createTableCellProperties(Style $style): string
    {
        $attributes = '';
        if ($style->vertical !== null) {
            $attributes .= ' style:vertical-align="'.match ($style->vertical) {
                VerticalAlignment::Top => 'top',
                VerticalAlignment::Center => 'middle',
                VerticalAlignment::Bottom => 'bottom',
            }.'"';
        }
        if ($style->textWrapped !== null) {
            $attributes .= ' fo:wrap-option="'.($style->textWrapped ? 'wrap' : 'no-wrap').'"';
        }
        if ($style->horizontal !== null) {
            $attributes .= ' style:text-align-source="fix"';
        }
        if ($style->border !== null) {
            $attributes .= $this->createBorderAttributes($style->border);
        }

        return $attributes === '' ? '' : '<style:table-cell-properties'.$attributes.'/>';
    }

    /**
     * Return the border attributes of all four edges.
     *
     * The edges are written one by one instead of the fo:border shorthand,
     * because PhpSpreadsheet's ODS reader only reads the edges.
     *
     * @param Border $border the border
     *
     * @return string
     */
    private function createBorderAttributes(Border $border): string
    {
        $value = match ($border->style) {
            BorderStyle::Thin => '0.75pt solid',
        }.' #'.$border->color;
        $attributes = '';
        foreach (['left', 'right', 'top', 'bottom'] as $edge) {
            $attributes .= ' fo:border-'.$edge.'="'.$value.'"';
        }

        return $attributes;
    }

    /**
     * Return the <style:text-properties> element of a font.
     *
     * @param Font $font the font, not empty
     *
     * @return string
     */
    private function createTextProperties(Font $font): string
    {
        $attributes = '';
        if ($font->bold !== null) {
            $weight = $font->bold ? 'bold' : 'normal';
            $attributes .= ' fo:font-weight="'.$weight.'" style:font-weight-asian="'.$weight.'" style:font-weight-complex="'.$weight.'"';
        }
        if ($font->color !== null) {
            $attributes .= ' fo:color="#'.$font->color.'"';
        }

        return '<style:text-properties'.$attributes.'/>';
    }

    /**
     * Return the name of the number style with the given number of decimals, adding it if it is new.
     *
     * @param int $decimals number of decimals, greater than 0
     *
     * @return string
     */
    private function registerNumberStyle(int $decimals): string
    {
        if (!isset($this->numberStyleNames[$decimals])) {
            $this->numberStyleNames[$decimals] = 'N'.(count($this->numberStyleNames) + 1);
        }

        return $this->numberStyleNames[$decimals];
    }
}
