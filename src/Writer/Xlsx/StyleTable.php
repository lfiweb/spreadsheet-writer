<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Xlsx;

use SpreadsheetWriter\Internal\DefaultFont;
use SpreadsheetWriter\Internal\StyleResolver;
use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\Style\Border;
use SpreadsheetWriter\Style\BorderStyle;
use SpreadsheetWriter\Style\Font;
use SpreadsheetWriter\Style\HorizontalAlignment;
use SpreadsheetWriter\Style\Style;
use SpreadsheetWriter\Style\VerticalAlignment;

/**
 * The style table of an XLSX file (xl/styles.xml).
 *
 * Maps a combination of style id and number of decimals to a cell format (<xf> element of cellXfs),
 * and collects the fonts, borders and number formats the cell formats refer to.
 *
 * @internal
 */
final class StyleTable
{
    /**
     * first id available for custom number formats, lower ids are built in
     */
    private const int FIRST_CUSTOM_NUMBER_FORMAT_ID = 164;

    /**
     * cell format indexes, indexed by 'styleId:decimals'
     * @var array<string, int>
     */
    private array $xfIndexesByKey = [];

    /**
     * the <xf> elements of cellXfs in index order
     * @var list<string>
     */
    private array $xfs = [];

    /**
     * font ids indexed by font key
     * @var array<string, int>
     */
    private array $fontIdsByKey = [];

    /**
     * the <font> elements in id order
     * @var list<string>
     */
    private array $fonts = [];

    /**
     * border ids indexed by border key
     * @var array<string, int>
     */
    private array $borderIdsByKey = [];

    /**
     * the <border> elements in id order
     * @var list<string>
     */
    private array $borders = [];

    /**
     * custom number format ids indexed by number of decimals
     * @var array<int, int>
     */
    private array $numberFormatIds = [];

    /**
     * Create the style table with the default font, border and cell format.
     *
     * @param StyleResolver $resolver resolver that knows the styles of the style ids
     */
    public function __construct(private readonly StyleResolver $resolver)
    {
        $this->registerFont(new Font(false));
        $this->registerBorder(null);
        $this->getXfIndex(StyleResolver::DEFAULT_ID, 0);
    }

    /**
     * Return the index of the cell format for a style id and a number of decimals.
     *
     * @param int $styleId style id from the StyleResolver
     * @param int $decimals number of decimals to display, 0 for the General number format
     *
     * @return int
     */
    public function getXfIndex(int $styleId, int $decimals): int
    {
        $key = $styleId.':'.$decimals;
        if (!isset($this->xfIndexesByKey[$key])) {
            $this->xfIndexesByKey[$key] = count($this->xfs);
            $this->xfs[] = $this->createXf($this->resolver->getStyle($styleId), $decimals);
        }

        return $this->xfIndexesByKey[$key];
    }

    /**
     * Return the XML of the style table.
     *
     * @return string
     */
    public function buildXml(): string
    {
        $numberFormats = '';
        foreach ($this->numberFormatIds as $decimals => $id) {
            $numberFormats .= '<numFmt numFmtId="'.$id.'" formatCode="0.'.str_repeat('0', $decimals).'"/>';
        }
        if ($numberFormats !== '') {
            $numberFormats = '<numFmts count="'.count($this->numberFormatIds).'">'.$numberFormats.'</numFmts>';
        }

        return Xml::DECLARATION
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$numberFormats
            .'<fonts count="'.count($this->fonts).'">'.implode('', $this->fonts).'</fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="'.count($this->borders).'">'.implode('', $this->borders).'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($this->xfs).'">'.implode('', $this->xfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /**
     * Return the <xf> element of a style and a number of decimals.
     *
     * @param Style $style the resolved style
     * @param int $decimals number of decimals to display, 0 for the General number format
     *
     * @return string
     */
    private function createXf(Style $style, int $decimals): string
    {
        $fontId = $style->font === null ? 0 : $this->registerFont($style->font);
        $borderId = $this->registerBorder($style->border);
        $numberFormatId = $decimals > 0 ? $this->registerNumberFormat($decimals) : 0;
        $alignment = $this->createAlignment($style);

        $xml = '<xf numFmtId="'.$numberFormatId.'" fontId="'.$fontId.'" fillId="0" borderId="'.$borderId.'" xfId="0"';
        $xml .= $numberFormatId > 0 ? ' applyNumberFormat="1"' : '';
        $xml .= $fontId > 0 ? ' applyFont="1"' : '';
        $xml .= $borderId > 0 ? ' applyBorder="1"' : '';

        return $xml.($alignment === '' ? '/>' : ' applyAlignment="1">'.$alignment.'</xf>');
    }

    /**
     * Return the <alignment> element of a style, or an empty string if the style has no alignment.
     *
     * @param Style $style the resolved style
     *
     * @return string
     */
    private function createAlignment(Style $style): string
    {
        $attributes = '';
        if ($style->horizontal !== null) {
            $attributes .= ' horizontal="'.match ($style->horizontal) {
                HorizontalAlignment::Left => 'left',
                HorizontalAlignment::Center => 'center',
                HorizontalAlignment::Right => 'right',
            }.'"';
        }
        if ($style->vertical !== null) {
            $attributes .= ' vertical="'.match ($style->vertical) {
                VerticalAlignment::Top => 'top',
                VerticalAlignment::Center => 'center',
                VerticalAlignment::Bottom => 'bottom',
            }.'"';
        }
        if ($style->textWrapped === true) {
            $attributes .= ' wrapText="1"';
        }

        return $attributes === '' ? '' : '<alignment'.$attributes.'/>';
    }

    /**
     * Return the id of a font, adding it if it is new.
     *
     * A font that is neither bold nor colored is the default font with id 0.
     *
     * @param Font $font the font
     *
     * @return int
     */
    private function registerFont(Font $font): int
    {
        $key = ($font->bold === true ? 'b' : '').($font->color ?? '');
        if (!isset($this->fontIdsByKey[$key])) {
            $xml = '<font>'.($font->bold === true ? '<b/>' : '');
            $xml .= '<sz val="'.DefaultFont::SIZE.'"/>';
            $xml .= $font->color === null ? '' : '<color rgb="FF'.$font->color.'"/>';
            $xml .= '<name val="'.DefaultFont::NAME.'"/><family val="2"/></font>';
            $this->fontIdsByKey[$key] = count($this->fonts);
            $this->fonts[] = $xml;
        }

        return $this->fontIdsByKey[$key];
    }

    /**
     * Return the id of a border, adding it if it is new. No border has id 0.
     *
     * @param Border|null $border the border or null for no border
     *
     * @return int
     */
    private function registerBorder(?Border $border): int
    {
        $key = $border?->getKey() ?? '';
        if (!isset($this->borderIdsByKey[$key])) {
            $edges = '<left/><right/><top/><bottom/>';
            if ($border !== null) {
                $style = match ($border->style) {
                    BorderStyle::Thin => 'thin',
                };
                $edges = '';
                foreach (['left', 'right', 'top', 'bottom'] as $edge) {
                    $edges .= '<'.$edge.' style="'.$style.'"><color rgb="FF'.$border->color.'"/></'.$edge.'>';
                }
            }
            $this->borderIdsByKey[$key] = count($this->borders);
            $this->borders[] = '<border>'.$edges.'<diagonal/></border>';
        }

        return $this->borderIdsByKey[$key];
    }

    /**
     * Return the id of the number format with the given number of decimals, adding it if it is new.
     *
     * @param int $decimals number of decimals, greater than 0
     *
     * @return int
     */
    private function registerNumberFormat(int $decimals): int
    {
        if (!isset($this->numberFormatIds[$decimals])) {
            $this->numberFormatIds[$decimals] = self::FIRST_CUSTOM_NUMBER_FORMAT_ID + count($this->numberFormatIds);
        }

        return $this->numberFormatIds[$decimals];
    }
}
