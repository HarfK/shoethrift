<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting period in Malaysia time (config('app.display_timezone')): whole local days from
 * $start (00:00) to $end (23:59:59.999999). Queries convert it to UTC, the storage timezone.
 */
final class ReportPeriod
{
    public const MAX_CUSTOM_DAYS = 366;

    /** Longer ranges are grouped by month instead of by day. */
    public const DAILY_GROUPING_MAX_DAYS = 62;

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
        public readonly string $type,
    ) {}

    public static function timezone(): string
    {
        return (string) config('app.display_timezone');
    }

    /**
     * "Now" in Malaysia time.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function day(CarbonImmutable $date): self
    {
        $day = $date->setTimezone(self::timezone())->startOfDay();

        return new self($day, $day->endOfDay(), $day->format('j M Y'), 'day');
    }

    /**
     * The Monday-to-Sunday week containing $date.
     */
    public static function week(CarbonImmutable $date): self
    {
        $start = $date->setTimezone(self::timezone())->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addDays(6)->endOfDay();

        return new self($start, $end, 'Week of '.$start->format('j M').' to '.$end->format('j M Y'), 'week');
    }

    public static function month(CarbonImmutable $date): self
    {
        $start = $date->setTimezone(self::timezone())->startOfMonth();

        return new self($start, $start->endOfMonth(), $start->format('F Y'), 'month');
    }

    /**
     * @throws InvalidArgumentException when $to is before $from or the range is too long
     */
    public static function custom(CarbonImmutable $from, CarbonImmutable $to): self
    {
        $start = $from->setTimezone(self::timezone())->startOfDay();
        $end = $to->setTimezone(self::timezone())->endOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }
        if ($start->diffInDays($end) >= self::MAX_CUSTOM_DAYS) {
            throw new InvalidArgumentException('Choose a range of at most '.self::MAX_CUSTOM_DAYS.' days.');
        }

        return new self($start, $end, $start->format('j M Y').' to '.$end->format('j M Y'), 'custom');
    }

    /**
     * From the start of $start's local day up to now (for "today / this week so far").
     */
    public static function between(CarbonImmutable $start, CarbonImmutable $end, string $label): self
    {
        return new self($start, $end, $label, 'custom');
    }

    public function days(): int
    {
        return (int) $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
    }

    /**
     * 'day' or 'month': how sales over time are grouped.
     */
    public function grouping(): string
    {
        return $this->days() > self::DAILY_GROUPING_MAX_DAYS ? 'month' : 'day';
    }

    /**
     * For file names, e.g. "2026-10" or "2026-10-01_2026-10-26".
     */
    public function slug(): string
    {
        return match ($this->type) {
            'day' => $this->start->format('Y-m-d'),
            'month' => $this->start->format('Y-m'),
            default => $this->start->format('Y-m-d').'_'.$this->end->format('Y-m-d'),
        };
    }
}
