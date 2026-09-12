<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SimulationDate
{
    public function __construct(
        private int $year,
        private int $month,
        private int $day,
    ) {
        if ($year < 1 || $year > 9999) {
            throw new InvalidArgumentException('Simulation years must be between 1 and 9999.');
        }
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new InvalidArgumentException('Simulation dates contain invalid month or day values.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $this->toIsoString(), new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException(sprintf('Invalid simulation date "%s".', $this->toIsoString()));
        }
    }

    public static function fromIsoString(string $value): self
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Simulation dates must use YYYY-MM-DD format.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return new self($year, $month, $day);
    }

    public function year(): int { return $this->year; }

    public function month(): int { return $this->month; }

    public function day(): int { return $this->day; }

    public function toIsoString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public function __toString(): string
    {
        return $this->toIsoString();
    }

    public function compareTo(self $other): int
    {
        return $this->toIsoString() <=> $other->toIsoString();
    }

    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function addDays(int $days): self
    {
        $date = $this->asDateTime()->modify(sprintf('%+d days', $days));
        if ($date === false) {
            throw new InvalidArgumentException('Simulation date arithmetic failed.');
        }

        return self::fromIsoString($date->format('Y-m-d'));
    }

    public function daysUntil(self $other): int
    {
        $difference = $this->asDateTime()->diff($other->asDateTime());
        if ($difference->days === false || $difference->days === null) {
            throw new InvalidArgumentException('Unable to calculate simulation date distance.');
        }

        return $difference->invert === 1 ? -$difference->days : $difference->days;
    }

    public function atStartOfDay(): DateTimeImmutable
    {
        return $this->asDateTime();
    }

    private function asDateTime(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->toIsoString() . 'T00:00:00+00:00');
    }
}
