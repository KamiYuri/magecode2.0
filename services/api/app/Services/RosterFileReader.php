<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Turns an uploaded roster into rows keyed by header name.
 *
 * Read through openspout so `.csv` and `.xlsx` behave identically — the
 * registrar exports whichever the university's system produces, and an
 * instructor should not have to convert it first.
 *
 * Headers are matched loosely (case, spaces, BOM) because these files come out
 * of Excel by way of a human.
 */
class RosterFileReader
{
    public const SUPPORTED_EXTENSIONS = ['csv', 'txt', 'xlsx'];

    /**
     * Rows as [$lineNumber => [$header => $value]], the line number counted as
     * a spreadsheet shows it — header is row 1.
     *
     * @return array<int, array<string, string>>
     */
    public function rows(UploadedFile $file): array
    {
        $reader = $this->readerFor($file);
        $reader->open($file->getPathname());

        $headers = [];
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $number => $row) {
                $cells = array_map(
                    fn (mixed $value): string => trim((string) $value),
                    $row->toArray()
                );

                if ($headers === []) {
                    $headers = array_map($this->normaliseHeader(...), $cells);

                    continue;
                }

                if (array_filter($cells, fn (string $cell): bool => $cell !== '') === []) {
                    continue;
                }

                $rows[$number] = $this->combine($headers, $cells);
            }

            // Only the first sheet is a roster; anything else in the workbook
            // is the registrar's own bookkeeping.
            break;
        }

        $reader->close();

        return $rows;
    }

    /** @return array<int, string> */
    public function headers(UploadedFile $file): array
    {
        $reader = $this->readerFor($file);
        $reader->open($file->getPathname());

        $headers = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $headers = array_map(
                    fn (mixed $value): string => $this->normaliseHeader(trim((string) $value)),
                    $row->toArray()
                );

                break 2;
            }
        }

        $reader->close();

        return $headers;
    }

    /** Concrete union rather than the interface: its generics do not narrow. */
    private function readerFor(UploadedFile $file): CsvReader|XlsxReader
    {
        return strtolower((string) $file->getClientOriginalExtension()) === 'xlsx'
            ? new XlsxReader
            : new CsvReader;
    }

    private function normaliseHeader(string $header): string
    {
        $header = preg_replace('/^\x{FEFF}/u', '', $header) ?? $header;

        return str_replace([' ', '-'], '_', mb_strtolower(trim($header)));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $cells
     * @return array<string, string>
     */
    private function combine(array $headers, array $cells): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = $cells[$index] ?? '';
        }

        return $row;
    }
}
