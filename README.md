# SpreadsheetWriter

Lightweight PHP library for writing spreadsheet files in XLSX and ODS format.
It is a replacement for `phpoffice/phpspreadsheet` in projects that only write simple tables
and need none of its other features (formulas, reading, charts, etc.).

## Requirements

- PHP 8.3 or newer
- `ext-mbstring`
- `ext-zlib`

## Usage

```php
use SpreadsheetWriter\RichText;
use SpreadsheetWriter\Style\{Border, Font, HorizontalAlignment, Style};
use SpreadsheetWriter\Workbook;
use SpreadsheetWriter\Writer\Xlsx;

$workbook = new Workbook();
$sheet = $workbook->getActiveSheet();

// coordinates are 1-based: (column, row)
$sheet->setString(1, 1, (new RichText())->addText('Unit', new Font(color: '006268'))->addText(': m³'));
$sheet->mergeCells(1, 1, 3, 1);
$sheet->setString(1, 2, '1990');      // always text
$sheet->setValue(2, 2, '12.30');      // number 12.3, displayed as 12.30
$sheet->setStyle(1, 2, 3, 2, new Style(horizontal: HorizontalAlignment::Right, border: new Border(color: 'aaaaaa')));
$sheet->setColumnWidth(1, 20);
$sheet->setShowGridlines(false);

$binary = (new Xlsx())->write($workbook);
```

See [docs/api.md](docs/api.md) for the API reference, the differences to PhpSpreadsheet and the mapping from its API.

## Installation

```bash
composer require lfiweb/spreadsheet-writer
```

## Development

The tests use PHPUnit and read the generated files back with `phpoffice/phpspreadsheet`, which is only a development dependency. They need `ext-zip` and `ext-dom`:

```bash
composer install
composer test
```

## License

GPL-3.0-or-later
