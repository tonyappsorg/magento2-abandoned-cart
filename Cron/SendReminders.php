<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Cron;

use Tonyapps\AbandonedCart\Model\ReminderSender;

class SendReminders
{
    public function __construct(private readonly ReminderSender $sender)
    {
    }

    public function execute(): void
    {
        $this->sender->send();
    }
}
