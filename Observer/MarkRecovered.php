<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder as ReminderResource;

/**
 * An order placed from a reminded cart counts as recovered, whether or not the shopper used the link.
 */
class MarkRecovered implements ObserverInterface
{
    public function __construct(private readonly ReminderResource $reminderResource)
    {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if ($quote && $quote->getId()) {
            $this->reminderResource->markRecovered((int) $quote->getId());
        }
    }
}
