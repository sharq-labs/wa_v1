<?php

namespace App\Services\Contacts;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class SpreadsheetContactReader
{
    /** @return array{headers: array<int,string>, rows: array<int,array<string,mixed>>, total_rows:int} */
    public function preview(string $path, string $extension, int $sampleSize = 10): array
    {
        $headers = [];
        $sample = [];
        $total = 0;

        foreach ($this->rows($path, $extension) as $row) {
            if ($headers === []) {
                $headers = array_keys($row);
            }
            $total++;
            if (count($sample) < $sampleSize) {
                $sample[] = $row;
            }
        }

        return ['headers' => $headers, 'rows' => $sample, 'total_rows' => $total];
    }

    /** @return Generator<int,array<string,mixed>> */
    public function rows(string $path, string $extension): Generator
    {
        return match (strtolower($extension)) {
            'csv' => yield from $this->csvRows($path),
            'xlsx' => yield from $this->xlsxRows($path),
            default => throw new RuntimeException('Unsupported contact import format.'),
        };
    }

    public function suggestedMapping(array $headers): array
    {
        $aliases = [
            'phone_number' => ['phone', 'phone number', 'mobile', 'mobile number', 'whatsapp', 'whatsapp number', 'رقم الهاتف', 'الهاتف', 'الموبايل', 'واتساب'],
            'first_name' => ['first name', 'firstname', 'first_name', 'name', 'الاسم', 'الاسم الاول', 'الاسم الأول'],
            'last_name' => ['last name', 'lastname', 'last_name', 'surname', 'family name', 'اسم العائلة', 'الاسم الاخير', 'الاسم الأخير'],
            'display_name' => ['display name', 'full name', 'customer name', 'اسم العميل', 'الاسم كامل', 'الاسم الكامل'],
            'email' => ['email', 'email address', 'e-mail', 'البريد', 'البريد الالكتروني', 'البريد الإلكتروني'],
            'country' => ['country', 'الدولة', 'البلد'],
            'language' => ['language', 'locale', 'اللغة'],
            'opt_in_status' => ['opt in', 'opt-in', 'consent', 'marketing consent', 'الموافقة'],
        ];

        $normalisedHeaders = [];
        foreach ($headers as $header) {
            $normalisedHeaders[$this->normaliseHeader((string) $header)] = (string) $header;
        }

        $mapping = [];
        foreach ($aliases as $target => $names) {
            foreach ($names as $alias) {
                $key = $this->normaliseHeader($alias);
                if (isset($normalisedHeaders[$key])) {
                    $mapping[$target] = $normalisedHeaders[$key];
                    break;
                }
            }
        }

        return $mapping;
    }

    /** @return Generator<int,array<string,mixed>> */
    protected function csvRows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV import file.');
        }

        try {
            $first = fgets($handle);
            if ($first === false) {
                return;
            }
            $delimiter = $this->detectDelimiter($first);
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter);
            if (! is_array($headers)) {
                throw new RuntimeException('CSV header row is missing.');
            }
            $headers = $this->uniqueHeaders($headers);

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($this->rowIsEmpty($values)) {
                    continue;
                }
                $values = array_pad($values, count($headers), null);
                yield array_combine($headers, array_slice($values, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<int,array<string,mixed>> */
    protected function xlsxRows(string $path): Generator
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX import requires the PHP zip extension.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open XLSX import file.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new RuntimeException('XLSX workbook does not contain a first worksheet.');
            }

            $xml = simplexml_load_string($sheetXml);
            if (! $xml instanceof SimpleXMLElement) {
                throw new RuntimeException('Unable to parse XLSX worksheet.');
            }

            $headers = [];
            foreach ($xml->sheetData->row as $row) {
                $values = [];
                foreach ($row->c as $cell) {
                    $reference = (string) $cell['r'];
                    $columnIndex = $this->columnIndex($reference);
                    $type = (string) $cell['t'];
                    $raw = (string) ($cell->v ?? '');
                    $value = $type === 's' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                    if ($type === 'inlineStr') {
                        $value = (string) ($cell->is->t ?? '');
                    }
                    $values[$columnIndex] = $value;
                }

                if ($values === []) {
                    continue;
                }

                $max = max(array_keys($values));
                $dense = [];
                for ($i = 0; $i <= $max; $i++) {
                    $dense[] = $values[$i] ?? null;
                }

                if ($headers === []) {
                    $headers = $this->uniqueHeaders($dense);
                    continue;
                }
                if ($this->rowIsEmpty($dense)) {
                    continue;
                }

                $dense = array_pad($dense, count($headers), null);
                yield array_combine($headers, array_slice($dense, 0, count($headers)));
            }
        } finally {
            $zip->close();
        }
    }

    protected function sharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }
        $xml = simplexml_load_string($content);
        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
                continue;
            }
            $text = '';
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    protected function uniqueHeaders(array $headers): array
    {
        $used = [];
        return array_map(function ($value, $index) use (&$used) {
            $header = trim((string) $value);
            if ($header === '') {
                $header = 'Column '.($index + 1);
            }
            $base = $header;
            $counter = 2;
            while (isset($used[mb_strtolower($header)])) {
                $header = $base.' '.$counter++;
            }
            $used[mb_strtolower($header)] = true;
            return $header;
        }, $headers, array_keys($headers));
    }

    protected function detectDelimiter(string $line): string
    {
        $scores = [];
        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $scores[$delimiter] = substr_count($line, $delimiter);
        }
        arsort($scores);

        return (string) array_key_first($scores);
    }

    protected function normaliseHeader(string $header): string
    {
        return mb_strtolower(trim(preg_replace('/[\s_-]+/u', ' ', $header)));
    }

    protected function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    protected function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return 0;
        }
        $letters = strtoupper($matches[1]);
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
