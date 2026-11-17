<?php

declare(strict_types=1);

namespace SpreadsheetWriter;

use SpreadsheetWriter\Style\Font;

/**
 * Text made of runs that each can have their own font.
 *
 * Note: RichText intentionally does not implement Stringable. Passing it to a string function
 * such as html_entity_decode() throws a TypeError instead of silently dropping the formatting.
 * Use getPlainText() to get the text without formatting.
 */
final class RichText
{
    /**
     * the runs in the order they were added
     * @var list<TextRun>
     */
    private array $runs = [];

    /**
     * Append a run of text.
     *
     * @param string $text the text to append
     * @param Font|null $font font of the text, it replaces the font of the cell (a bold cell doesn't make
     *     the run bold, also if the font sets no property); null to use the font of the cell
     *
     * @return self this instance for chaining
     */
    public function addText(string $text, ?Font $font = null): self
    {
        $this->runs[] = new TextRun($text, $font);

        return $this;
    }

    /**
     * Return the runs in the order they were added.
     *
     * @return list<TextRun>
     */
    public function getRuns(): array
    {
        return $this->runs;
    }

    /**
     * Return the text of all runs without formatting.
     *
     * @return string
     */
    public function getPlainText(): string
    {
        return implode('', array_map(static fn(TextRun $run): string => $run->text, $this->runs));
    }

    /**
     * Return a string that is identical for rich texts with identical runs.
     *
     * @internal used by the writers
     * @return string
     */
    public function getKey(): string
    {
        $parts = [];
        foreach ($this->runs as $run) {
            $parts[] = ($run->font?->getKey() ?? '').':'.strlen($run->text).':'.$run->text;
        }

        return implode('', $parts);
    }
}
