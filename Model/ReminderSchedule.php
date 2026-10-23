<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Model;

/**
 * Pure timing logic, kept free of Magento so it can be unit tested on its own.
 */
class ReminderSchedule
{
    /**
     * "1, 24,72" -> [1.0, 24.0, 72.0]. Drops junk, duplicates and non-positive values; sorts ascending.
     *
     * @return float[]
     */
    public function parseDelays(string $value): array
    {
        $delays = array_filter(
            array_map('floatval', explode(',', $value)),
            static fn (float $hours) => $hours > 0
        );
        $delays = array_values(array_unique($delays, SORT_NUMERIC));
        sort($delays);
        return $delays;
    }

    /**
     * Zero-based index of the reminder to send now, or null if none is due.
     * If cron was down and several are overdue, returns only the latest one so the shopper gets one email, not three.
     *
     * @param float[] $delays hours, ascending
     */
    public function dueStep(array $delays, int $sentCount, int $cartUpdatedAt, int $now): ?int
    {
        $due = null;
        for ($i = $sentCount, $count = count($delays); $i < $count; $i++) {
            if ($now < $cartUpdatedAt + (int) round($delays[$i] * 3600)) {
                break;
            }
            $due = $i;
        }
        return $due;
    }
}
