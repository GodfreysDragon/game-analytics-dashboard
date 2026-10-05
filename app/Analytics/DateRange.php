<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

/**
 * An inclusive range of UTC calendar days.
 */
final readonly class DateRange
{
    public const MAX_DAYS = 366;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {
        if ($from->gt($to)) {
            throw new InvalidArgumentException('The range start must not be after its end.');
        }
    }

    public static function fromStrings(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $end = $to ? CarbonImmutable::parse($to, 'UTC')->startOfDay() : CarbonImmutable::today('UTC');
        $start = $from ? CarbonImmutable::parse($from, 'UTC')->startOfDay() : $end->subDays($defaultDays - 1);

        return new self($start, $end);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The equally long range that ends the day before this one starts.
     */
    public function previous(): self
    {
        return new self($this->from->subDays($this->days()), $this->from->subDay());
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * Exclusive upper bound for datetime columns.
     */
    public function endExclusive(): string
    {
        return $this->to->addDay()->toDateTimeString();
    }

    /**
     * @return list<string> every day in the range as Y-m-d
     */
    public function dates(): array
    {
        return array_map(
            fn ($day) => $day->toDateString(),
            iterator_to_array(CarbonPeriod::create($this->from, $this->to)),
        );
    }

    /**
     * Expand sparse "day => value" rows into a gap-free daily series.
     *
     * @param  array<string, int|float>  $values
     * @return list<array{date: string, value: int|float}>
     */
    public function fill(array $values, int|float $default = 0): array
    {
        return array_map(
            fn (string $date) => ['date' => $date, 'value' => $values[$date] ?? $default],
            $this->dates(),
        );
    }

    /**
     * @return array{from: string, to: string, days: int}
     */
    public function toArray(): array
    {
        return ['from' => $this->fromDate(), 'to' => $this->toDate(), 'days' => $this->days()];
    }
}
