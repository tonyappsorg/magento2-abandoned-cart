<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tonyapps\AbandonedCart\Model\ReminderSchedule;

class ReminderScheduleTest extends TestCase
{
    private const HOUR = 3600;
    private const UPDATED = 1_700_000_000;

    private ReminderSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new ReminderSchedule();
    }

    public function testParsesSortsAndCleansDelays(): void
    {
        $this->assertSame([0.5, 24.0, 72.0], $this->schedule->parseDelays(' 72, 0.5,abc,24,-3,0,24 '));
        $this->assertSame([], $this->schedule->parseDelays(''));
    }

    public function testNothingDueBeforeFirstDelay(): void
    {
        $this->assertNull($this->schedule->dueStep([1, 24, 72], 0, self::UPDATED, self::UPDATED + self::HOUR - 1));
    }

    public function testFirstReminderDueAtFirstDelay(): void
    {
        $this->assertSame(0, $this->schedule->dueStep([1, 24, 72], 0, self::UPDATED, self::UPDATED + self::HOUR));
    }

    public function testSecondReminderWaitsForItsOwnDelay(): void
    {
        $this->assertNull($this->schedule->dueStep([1, 24, 72], 1, self::UPDATED, self::UPDATED + 23 * self::HOUR));
        $this->assertSame(1, $this->schedule->dueStep([1, 24, 72], 1, self::UPDATED, self::UPDATED + 24 * self::HOUR));
    }

    public function testOverdueStepsCollapseIntoTheLatest(): void
    {
        $this->assertSame(2, $this->schedule->dueStep([1, 24, 72], 0, self::UPDATED, self::UPDATED + 100 * self::HOUR));
    }

    public function testNothingDueOnceAllSent(): void
    {
        $this->assertNull($this->schedule->dueStep([1, 24, 72], 3, self::UPDATED, self::UPDATED + 1000 * self::HOUR));
    }
}
