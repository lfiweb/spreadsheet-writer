<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Internal;

/**
 * The default font of the written files, the same in XLSX and ODS.
 *
 * @internal
 */
final class DefaultFont
{
    /**
     * name of the default font, also used for rich text runs
     */
    public const string NAME = 'Calibri';

    /**
     * size of the default font in points
     */
    public const int SIZE = 11;
}
