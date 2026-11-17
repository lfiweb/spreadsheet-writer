# Design decision: built-in ZIP writer

The package builds the ZIP archive itself in memory instead of using PHP's `ZipArchive`.

## What the decision is

XLSX and ODS files are ZIP archives of XML parts. `ZipArchive` can only write to a file path, so producing a download with it means writing a temp file, reading it back and deleting it.

The package has its own small ZIP writer instead (`SpreadsheetWriter\Internal\ZipBuilder`, about 150 lines). It compresses each part with `gzdeflate()`, computes the checksum with `crc32()` and returns the archive as a string. The package therefore requires `ext-zlib` and not `ext-zip`.

## Performance

At the same compression level, the built-in writer and `ZipArchive` are equally fast, because both use zlib underneath. In a default comparison the built-in writer is about 5 times faster, but only because it compresses at level 6 while `ZipArchive` defaults to level 9.

Measured on 2026-11-16 on the ZIP step alone, with parts the size of a table of 10,000 rows and 40 columns (17.8 MB of XML). PHP 8.5 in the Docker container, zlib 1.2.13, libzip 1.7.3, Xdebug off:

| | Time | Zip size |
|---|---|---|
| `ZipArchive`, default settings | 1,430 ms | 2.45 MB |
| Built-in writer (level 6) | 277 ms | 2.58 MB |
| `ZipArchive` set to level 6, sheet part only | 277 ms | 2.36 MB |
| `gzdeflate()` level 6, sheet part only | 253 ms | 2.36 MB |
| `gzdeflate()` level 9, sheet part only | 1,288 ms | 2.24 MB |

The first two rows compress three parts (sheet, shared strings, styles), the last three only the sheet part, so their sizes can't be compared across the two groups.

- **Speed:** the gain comes from level 6, which `ZipArchive` could also be set to (`setCompressionName()`).
- **File size:** level 6 costs about 5% in file size compared with level 9.
- **Memory:** about the same, roughly the size of the XML (17 to 18 MB extra in this measurement). Neither streams: `ZipArchive::addFromString()` also keeps the content in memory until `close()`.
- **Small files:** for a table of 20 rows the built-in writer took 1 ms and `ZipArchive` 7 ms, mostly temp file handling.

The measurement can be repeated with `build/bench-zip.php` (not in the repository, `build/` is ignored by Git):

```bash
php build/bench-zip.php builder 10000
php build/bench-zip.php archive 10000
```

## Upsides

- **No temp files:** no disk I/O per download, no leftover files if a request dies, and no dependence on a writable temp directory.
- **One dependency fewer:** `ext-zlib` is in almost every PHP build, while `ext-zip` is often missing.
- **Control over the archive:** ODS requires the `mimetype` entry first and uncompressed, and a fixed timestamp gives byte-identical output for tests.

## Downsides

- **Own code to maintain:** the ZIP format is the responsibility of the package. It is covered by tests that read the archives back with `ZipArchive`, and by Excel and LibreOffice opening the files.
- **No ZIP64:** archives over 4 GB or with more than 65,535 files throw a `WriterException`. A spreadsheet has 6 to 9 parts, so only the size limit could ever matter.
- **Everything in memory:** the whole file exists as a string, and briefly twice while it is assembled. A streaming writer could send a very large file with less memory, but then `write()` could not return a string.
- **Slightly larger files** than with the default of `ZipArchive`, because of level 6.

## Compression level

The level is the constant `COMPRESSION_LEVEL` in `ZipBuilder`. Level 6 is the zlib default. Level 9 would make the files about 5% smaller and the ZIP step about 5 times slower.
