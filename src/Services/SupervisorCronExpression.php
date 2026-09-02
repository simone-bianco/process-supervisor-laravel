<?php

declare(strict_types=1);

namespace SimoneBianco\ProcessSupervisorLaravel\Services;

use Cron\CronExpression;
use DateTimeZone;
use SimoneBianco\ProcessSupervisorLaravel\Contracts\TimezoneProvider;
use SimoneBianco\ProcessSupervisorLaravel\Exceptions\SupervisorRuntimeException;

final class SupervisorCronExpression
{
    public function __construct(private readonly TimezoneProvider $timezone) {}

    public function normalize(string $expression): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($expression));
        if (! is_string($normalized) || ! self::valid($normalized)) {
            throw new SupervisorRuntimeException('SUPERVISOR_SCHEDULE_INVALID', 'The scheduler cron expression is invalid.', 422);
        }

        return $normalized;
    }

    public static function valid(mixed $expression): bool
    {
        return is_string($expression)
            && $expression !== ''
            && strlen($expression) <= 128
            && preg_match('/^[0-9*\/,\- ]+$/D', $expression) === 1
            && count(explode(' ', $expression)) === 5
            && CronExpression::isValidExpression($expression);
    }

    public function nextRunAt(string $expression, ?string $timezone = null): ?string
    {
        try {
            $normalized = $this->normalize($expression);
            $effectiveTimezone = is_string($timezone) && $this->validTimezone($timezone)
                ? $timezone
                : $this->timezone->effective();
            $next = (new CronExpression($normalized))->getNextRunDate('now', 0, false, $effectiveTimezone);

            return $next->format(DATE_ATOM);
        } catch (SupervisorRuntimeException) {
            return null;
        }
    }

    private function validTimezone(string $timezone): bool
    {
        return $timezone === 'UTC'
            || in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }
}
