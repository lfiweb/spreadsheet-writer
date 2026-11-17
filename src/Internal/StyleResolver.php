<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

use SpreadsheetWriter\Sheet;
use SpreadsheetWriter\Style\Style;

/**
 * Resolves the layered styles of a sheet into one style per cell.
 *
 * Every distinct style gets an id. Id 0 is the empty default style. The writers map the ids
 * to their own style definitions (cellXfs in XLSX, automatic styles in ODS).
 *
 * @internal
 */
final class StyleResolver
{
    /**
     * id of the empty default style
     */
    public const int DEFAULT_ID = 0;

    /**
     * distinct styles indexed by id
     * @var list<Style>
     */
    private array $styles;

    /**
     * style ids indexed by style key
     * @var array<string, int>
     */
    private array $idsByKey;

    /**
     * results of merging two style ids, indexed by 'baseId:overrideId'
     * @var array<string, int>
     */
    private array $mergedIds = [];

    /**
     * Create a resolver that knows the empty default style.
     */
    public function __construct()
    {
        $default = new Style();
        $this->styles = [self::DEFAULT_ID => $default];
        $this->idsByKey = [$default->getKey() => self::DEFAULT_ID];
    }

    /**
     * Return the style id of every styled cell of a sheet.
     *
     * Cells without a style (or whose style resolves to the default) are omitted.
     *
     * @param Sheet $sheet the sheet
     *
     * @return array<int, array<int, int>> style ids indexed by row and column
     */
    public function resolveSheet(Sheet $sheet): array
    {
        $grid = [];
        foreach ($sheet->getStyledRanges() as [$range, $style]) {
            $styleId = $this->registerStyle($style);
            if ($styleId !== self::DEFAULT_ID) {
                [$fromCol, $fromRow, $toCol, $toRow] = $range;
                for ($row = $fromRow; $row <= $toRow; $row++) {
                    for ($col = $fromCol; $col <= $toCol; $col++) {
                        $grid[$row][$col] = $this->mergeIds($grid[$row][$col] ?? self::DEFAULT_ID, $styleId);
                    }
                }
            }
        }

        return $grid;
    }

    /**
     * Return the style with the given id.
     *
     * @param int $id style id
     *
     * @return Style
     */
    public function getStyle(int $id): Style
    {
        return $this->styles[$id];
    }

    /**
     * Return the id of a style, registering it if it is new.
     *
     * @param Style $style the style
     *
     * @return int
     */
    private function registerStyle(Style $style): int
    {
        $key = $style->getKey();
        if (!isset($this->idsByKey[$key])) {
            $this->idsByKey[$key] = count($this->styles);
            $this->styles[] = $style;
        }

        return $this->idsByKey[$key];
    }

    /**
     * Return the id of the style that results from layering $overrideId on top of $baseId.
     *
     * @param int $baseId id of the current style of the cell
     * @param int $overrideId id of the style applied on top
     *
     * @return int
     */
    private function mergeIds(int $baseId, int $overrideId): int
    {
        $cacheKey = $baseId.':'.$overrideId;
        if (!isset($this->mergedIds[$cacheKey])) {
            $this->mergedIds[$cacheKey] = $baseId === self::DEFAULT_ID
                ? $overrideId
                : $this->registerStyle($this->styles[$baseId]->merge($this->styles[$overrideId]));
        }

        return $this->mergedIds[$cacheKey];
    }
}
