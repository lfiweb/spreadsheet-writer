# SpreadsheetWriter: API

SpreadsheetWriter writes XLSX and ODS files with one sheet: cell values, rich text, merged cells, fonts, alignments, borders, row heights, column widths and hidden gridlines.

- It doesn't read files and has no formulas, charts or images.
- The file is built in memory, without temp files.
- Coordinates are 1-based (column 1 = `A`, row 1 = first row), the same as in PhpSpreadsheet.

## Classes

```
SpreadsheetWriter\
├── Workbook                  holds the one sheet
├── Sheet                     cell values, merges, styles, row heights, column widths, gridlines
├── RichText                  text made of runs with their own font
├── TextRun                   one run of a RichText: text and optional font
├── Style\
│   ├── Style                 font, alignment, wrap, border; null = "leave unchanged"
│   ├── Font                  bold, color
│   ├── Border                style and color for all edges of each cell
│   ├── HorizontalAlignment   enum: Left, Center, Right
│   ├── VerticalAlignment     enum: Top, Center, Bottom
│   └── BorderStyle           enum: Thin
├── Writer\
│   ├── WriterInterface
│   ├── Xlsx
│   └── Ods
└── Exception\
    ├── ExceptionInterface    implemented by all exceptions of the package
    ├── InvalidArgumentException
    └── WriterException       the file can't be created or saved
```

The classes in `SpreadsheetWriter\Internal\`, `SpreadsheetWriter\Writer\Xlsx\` and `SpreadsheetWriter\Writer\Ods\` are marked `@internal`. They are not part of the API and can change in any version.

## Public API

```php
namespace SpreadsheetWriter;

final class Workbook
{
    /** Creates a workbook with one empty sheet. */
    public function __construct();

    /** Returns the sheet of the workbook. */
    public function getActiveSheet(): Sheet;
}

final class Sheet
{
    /** The title shown on the sheet tab, the same for every file. */
    public const string TITLE = 'Worksheet';
    public const int MAX_TEXT_LENGTH = 32767;
    public const float MAX_ROW_HEIGHT = 409.0;
    public const float MAX_COLUMN_WIDTH = 255.0;

    /**
     * Set a cell value.
     * Numeric strings ("12.30", "-5", "1e3") are stored as numbers, other strings as text,
     * null clears the cell. Same detection rules as PhpSpreadsheet's DefaultValueBinder,
     * but strings starting with "=" are always text (no formulas).
     * A numeric string with decimals also sets a number format that keeps them ("12.30" → 0.00).
     */
    public function setValue(int $col, int $row, string|int|float|null $value): void;

    /** Set a cell value that is always stored as text (also numeric strings). */
    public function setString(int $col, int $row, string|RichText $text): void;

    /**
     * Merge a range of cells. The value of the top left cell is kept.
     * Merging a single cell or an already merged range has no effect; a range overlapping a different merged range throws.
     */
    public function mergeCells(int $fromCol, int $fromRow, int $toCol, int $toRow): void;

    /**
     * Apply a style to a range of cells.
     * Styles are layered: only the properties that are not null override earlier styles,
     * like getStyle($range)->getAlignment()->setHorizontal() in PhpSpreadsheet.
     */
    public function setStyle(int $fromCol, int $fromRow, int $toCol, int $toRow, Style $style): void;

    /** Set the height of a row in points, from 0 to 409. */
    public function setRowHeight(int $row, float $points): void;

    /** Set the width of a column in characters (Excel's unit), from 0 to 255. */
    public function setColumnWidth(int $col, float $width): void;

    /** Return the width set with setColumnWidth(), or null if the default width is used. */
    public function getColumnWidth(int $col): ?float;

    /** Show or hide the gridlines. Default: shown. */
    public function setShowGridlines(bool $show): void;

    /** Return true if the gridlines are shown. */
    public function areGridlinesShown(): bool;
}

final class RichText
{
    /** Append a run of text with an optional font. Returns $this for chaining. */
    public function addText(string $text, ?Font $font = null): self;

    /** @return list<TextRun> */
    public function getRuns(): array;

    /** Return the text without formatting. */
    public function getPlainText(): string;
}

// RichText doesn't implement Stringable: passing it to a string function
// (e.g. html_entity_decode()) throws a TypeError instead of dropping the formatting.

final readonly class TextRun
{
    public function __construct(public string $text, public ?Font $font = null);
}
```

```php
namespace SpreadsheetWriter\Style;

final readonly class Style
{
    public function __construct(
        public ?Font $font = null,
        public ?HorizontalAlignment $horizontal = null,
        public ?VerticalAlignment $vertical = null,
        public ?bool $textWrapped = null,
        public ?Border $border = null,  // applied to all four edges of every cell in the range
    );
}

final readonly class Font
{
    public function __construct(
        public ?bool $bold = null,
        public ?string $color = null,  // 'RRGGBB'
    );
}

final readonly class Border
{
    public function __construct(
        public BorderStyle $style = BorderStyle::Thin,
        public string $color = '000000',  // 'RRGGBB'
    );
}

enum HorizontalAlignment { case Left; case Center; case Right; }
enum VerticalAlignment { case Top; case Center; case Bottom; }
enum BorderStyle { case Thin; }
```

```php
namespace SpreadsheetWriter\Writer;

interface WriterInterface
{
    /** Return the file as a binary string. */
    public function write(Workbook $workbook): string;

    /** Write the file to the given path. */
    public function save(Workbook $workbook, string $path): void;

    /** e.g. 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' */
    public function getMimeType(): string;

    /** e.g. 'xlsx' */
    public function getFileExtension(): string;
}

final class Xlsx extends AbstractWriter   // AbstractWriter implements WriterInterface
{
    public const string MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** $timestamp: modification time stored in the ZIP archive, null for now; a fixed value makes the output reproducible. */
    public function __construct(?int $timestamp = null);
}

final class Ods extends AbstractWriter
{
    public const string MIME_TYPE = 'application/vnd.oasis.opendocument.spreadsheet';

    /** $timestamp: the same as for Xlsx. */
    public function __construct(?int $timestamp = null);
}
```

Some public methods are helpers for the writers and marked `@internal`: `merge()`, `isEmpty()` and `getKey()` of `Font`, `Style`, `Border` and `RichText`, and the getters of `Sheet` that are not listed above (`getCells()`, `getMergedRanges()`, `getStyledRanges()`, `getRowHeights()`, `getColumnWidths()`). They are not part of the API. The same goes for `Writer\AbstractWriter`, the base class of both writers: use `WriterInterface` as the type of a writer.

A workbook has exactly one sheet, titled `Worksheet`. Sheets can't be added and the title can't be changed.

The writers don't hold the workbook. The same writer instance can write any workbook.

`save()` throws `SpreadsheetWriter\Exception\WriterException` if the file can't be written.

## Values

`setValue()` stores numeric strings as numbers. A numeric string with decimals also gets a number format made from the string, so the cell is a number and shows the same decimals as the string:

- `"12.30"` → number `12.3`, format `0.00` (shows `12.30`)
- `"-5.0"` → number `-5`, format `0.0` (shows `-5.0`)
- `"12"`, `"1e3"` → number, General format (no decimals to keep)

The decimal separator shown depends on the locale of the user in Excel or LibreOffice, not on the file.

All other strings are stored as text, also strings with leading zeros (`"007"`), strings starting with `=` and error codes such as `#N/A`. Use `setString()` to store a numeric string as text.

## Invalid input

Invalid input throws `SpreadsheetWriter\Exception\InvalidArgumentException`: row or column < 1, more than 16,384 columns or 1,048,576 rows, a merged range that overlaps a different merged range, a color that is not `RRGGBB`, a rich text longer than 32,767 characters, a float that is not finite, a row height outside 0–409 points and a column width outside 0–255. Integers larger than 999,999,999,999,999 are stored as text, as PhpSpreadsheet does.

## Compared to PhpSpreadsheet

The package acts like PhpSpreadsheet 5 for the features it has.

The same as PhpSpreadsheet, also in edge cases:

- Number detection of `setValue()`: leading zeros, signs, exponents, integers above 15 digits, a line break at the end.
- A string longer than 32,767 characters is cut off.
- An empty string is written to XLSX like an empty cell (the style is kept).
- Control characters in XLSX are written as `_xHHHH_` and a literal `_xHHHH_` as `_x005F_xHHHH_`, so Excel shows the same text.
- Invalid UTF-8 is replaced with `?`.
- A range given from its end to its start is corrected, for merges and for styles.
- Merging a range that is already merged has no effect. Values in the covered cells of a merged range are kept in the file.
- Whole floats are written with a decimal point (`1000.0`).
- A rich text run with its own font doesn't inherit the font of the cell: a colored label is not bold in a bold cell. This is also true for a font without properties (`new Font()`), like `createTextRun()` in PhpSpreadsheet: the run is not bold and black. Runs without a font use the cell font, like `createText()`.
- The sheet is titled `Worksheet`, the default font is Calibri 11, no default column width or row height is written.

Deliberate differences:

| Case | PhpSpreadsheet | SpreadsheetWriter | Reason |
|---|---|---|---|
| Numeric string with decimals (`"12.30"`) | number `12.3`, format General | number `12.3`, format `0.00` | the cell shows the decimals of the string, see "Values" |
| String starting with `=` in `setValue()` | formula (writing fails if it is not a valid formula) | text | no formulas |
| `#N/A`, `#DIV/0!`, `#VALUE!`, `#REF!`, `#NAME?`, `#NUM!`, `#NULL!` in `setValue()` | error cell in XLSX, a broken formula `=#NULL!` in ODS | text | no error cells |
| Floats with more than 14 significant digits | rounded to PHP's `precision` (`0.1 + 0.2` → `0.3`) | exact (`0.30000000000000004`) | no loss of precision |
| Rich text, row heights, gridlines, wrapped text and tabs in ODS | not written, or lost | written; rich text runs look the same as in XLSX | the ODS file looks like the XLSX file |
| Control characters in ODS | written as they are, the file can't be opened | removed | valid file |
| Overlapping merged ranges | written, Excel reports the file as damaged | throws | valid file |
| Merging a single cell | written as a merged range `G1:G1` | ignored | no effect in Excel |
| Row height below 0 or above 409, column width outside 0–255, color not `RRGGBB`, `INF`/`NAN`, rich text over 32,767 characters | accepted and written as they are | throws | such a call is a bug in the caller |
| Width of a column without a width | `getWidth()` returns `-1.0` | `getColumnWidth()` returns `null` | no magic number |

## Example

A caption with a colored label above a table with borders, written as ODS:

```php
use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Style\{Border, Font, Style, VerticalAlignment};
use SpreadsheetWriter\Workbook;
use SpreadsheetWriter\Writer\Ods;

$workbook = new Workbook();
$sheet = $workbook->getActiveSheet();

$caption = (new RichText())
    ->addText('Einheit', new Font(color: '006268'))
    ->addText(': 1000 m³');
$sheet->setString(1, 1, $caption);
$sheet->mergeCells(1, 1, 8, 1);
$sheet->setRowHeight(1, 24);
$sheet->setStyle(1, 1, 1, 1, new Style(font: new Font(bold: true), vertical: VerticalAlignment::Top));

$sheet->setValue(3, 5, '12.30');   // stored as the number 12.3
$sheet->setString(1, 5, '1990');   // stored as text
$sheet->setStyle(1, 2, 8, 20, new Style(border: new Border(color: 'aaaaaa')));
$sheet->setShowGridlines(false);

$binary = (new Ods())->write($workbook);
```

## Mapping from PhpSpreadsheet

| PhpSpreadsheet | SpreadsheetWriter |
|---|---|
| `new Spreadsheet()`, `->getActiveSheet()` | `new Workbook()`, `->getActiveSheet()` |
| `new Xlsx($xls)`, `new Ods($xls)`, `->save($tempFile)` + `file_get_contents()` | `new Xlsx()`, `new Ods()`, `->write($workbook)` |
| `setCellValue([$x, $y], $val)` | `setValue($x, $y, $val)` |
| `setCellValueExplicit([$x, $y], $str, DataType::TYPE_STRING)` | `setString($x, $y, $str)` |
| `new RichText()`, `createTextRun($a)->getFont()->getColor()->setRGB($c)`, `createText($b)` | `(new RichText())->addText($a, new Font(color: $c))->addText($b)` |
| `mergeCells([$x1, $y1, $x2, $y2])` | `mergeCells($x1, $y1, $x2, $y2)` |
| `getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)` | `setStyle(...$range, new Style(horizontal: HorizontalAlignment::Left))` |
| `getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)` | `setStyle(...$range, new Style(vertical: VerticalAlignment::Top))` |
| `getStyle($range)->getAlignment()->setWrapText(true)` | `setStyle(...$range, new Style(textWrapped: true))` |
| `getStyle($range)->getFont()->setBold(true)` | `setStyle(...$range, new Style(font: new Font(bold: true)))` |
| `getStyle($range)->getFont()->getColor()->setRGB($c)` | `setStyle(...$range, new Style(font: new Font(color: $c)))` |
| `getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)` + `->getColor()->setRGB($c)` | `setStyle(...$range, new Style(border: new Border(BorderStyle::Thin, $c)))` |
| `getRowDimension($y)->setRowHeight($pt)` | `setRowHeight($y, $pt)` |
| `getColumnDimensionByColumn($x)->setWidth($w)` / `->getWidth()` | `setColumnWidth($x, $w)` / `getColumnWidth($x)` |
| `setShowGridlines(false)` | `setShowGridlines(false)` |
| `setSelectedCell('A1')` after styling a range | not needed: styling doesn't move the selection, which stays at `A1` |
| `Settings::setLocale($locale)` | not needed: it only sets the locale of the formula engine |
