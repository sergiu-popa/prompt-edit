<?php

declare(strict_types=1);

namespace App\Support\Pixelforge\DataTransferObjects;

use App\Domain\Reporting\ValueObjects\ReportRange;
use App\Support\Pixelforge\Exceptions\InvalidUsageReportException;
use InvalidArgumentException;

/**
 * Pixelforge's `GET /v1/usage` answer: what it billed this key on each UTC day of an
 * inclusive range, and in total (D-72, D-73). It is read only when it is exactly as
 * documented, so a half-read answer can never show a Match that isn't one.
 */
final readonly class UsageReportData
{
    /**
     * @param  array<string, UsageRowData>  $days  every date of the range, ascending
     */
    private function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public UsageRowData $total,
    ) {}

    /**
     * `object` is `usage`, the range is a valid report range, and `data` holds each of its
     * dates exactly once, in any order. Unknown keys are ignored.
     *
     * @param  array<array-key, mixed>  $report
     *
     * @throws InvalidUsageReportException
     */
    public static function from(array $report): self
    {
        if (self::required($report, 'object') !== 'usage') {
            throw InvalidUsageReportException::invalidField('object', '"usage"', $report['object']);
        }

        $startDate = self::requiredString($report, 'start_date');
        $endDate = self::requiredString($report, 'end_date');

        try {
            $dates = ReportRange::fromDates($startDate, $endDate)->dates();
        } catch (InvalidArgumentException $exception) {
            throw InvalidUsageReportException::invalidRange($exception);
        }

        $total = self::required($report, 'total');

        if (! is_array($total)) {
            throw InvalidUsageReportException::invalidField('total', 'an object', $total);
        }

        return new self($startDate, $endDate, self::days($report, array_reverse($dates)), UsageRowData::from($total, 'total'));
    }

    /**
     * The docs' shape, which `from()` reads back equal.
     *
     * @return array{object: string, start_date: string, end_date: string, data: list<array<string, int|string>>, total: array<string, int|string>}
     */
    public function toArray(): array
    {
        $data = [];

        foreach ($this->days as $date => $row) {
            $data[] = ['date' => $date, ...$row->toArray()];
        }

        return [
            'object' => 'usage',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'data' => $data,
            'total' => $this->total->toArray(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $report
     * @param  list<string>  $dates  the range's dates, ascending
     * @return array<string, UsageRowData>
     *
     * @throws InvalidUsageReportException
     */
    private static function days(array $report, array $dates): array
    {
        $data = self::required($report, 'data');

        if (! is_array($data) || ! array_is_list($data)) {
            throw InvalidUsageReportException::invalidField('data', 'a list', $data);
        }

        $rows = [];

        foreach ($data as $index => $row) {
            $path = "data[{$index}]";

            if (! is_array($row)) {
                throw InvalidUsageReportException::invalidField($path, 'an object', $row);
            }

            $date = self::requiredString($row, 'date', $path);

            if (! in_array($date, $dates, true)) {
                throw InvalidUsageReportException::unexpectedDate("{$path}.date", $date);
            }

            if (array_key_exists($date, $rows)) {
                throw InvalidUsageReportException::repeatedDate("{$path}.date", $date);
            }

            $rows[$date] = UsageRowData::from($row, $path);
        }

        $days = [];

        foreach ($dates as $date) {
            $days[$date] = $rows[$date] ?? throw InvalidUsageReportException::missingDate($date);
        }

        return $days;
    }

    /**
     * @param  array<array-key, mixed>  $object
     *
     * @throws InvalidUsageReportException
     */
    private static function required(array $object, string $field, ?string $path = null): mixed
    {
        if (! array_key_exists($field, $object)) {
            throw InvalidUsageReportException::missingField(self::path($path, $field));
        }

        return $object[$field];
    }

    /**
     * @param  array<array-key, mixed>  $object
     *
     * @throws InvalidUsageReportException
     */
    private static function requiredString(array $object, string $field, ?string $path = null): string
    {
        $value = self::required($object, $field, $path);

        if (! is_string($value)) {
            throw InvalidUsageReportException::invalidField(self::path($path, $field), 'a string', $value);
        }

        return $value;
    }

    private static function path(?string $path, string $field): string
    {
        return $path === null ? $field : "{$path}.{$field}";
    }
}
