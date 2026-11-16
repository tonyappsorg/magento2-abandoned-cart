<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Model;

use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\App\Emulation;
use Tonyapps\AbandonedCart\Helper\Data as Helper;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder as ReminderResource;

/**
 * Real-data preview for Tonyapps_EmailDesigner (registered in etc/di.xml, duck-typed): the reminder a cart would get
 * next, built by ReminderSender::templateVars(). Read-only: no reminder row, token or coupon is created.
 */
class EmailPreview
{
    /** Restore/unsubscribe links of a cart that has no reminder row yet; they show "no longer valid". */
    public const PLACEHOLDER_TOKEN = 'preview';
    /** Shown where the reminder would mint a coupon. */
    public const PLACEHOLDER_COUPON = 'PREVIEW10';

    public function __construct(
        private readonly ReminderResource $reminderResource,
        private readonly ReminderSender $sender,
        private readonly Helper $helper,
        private readonly QuoteFactory $quoteFactory,
        private readonly Emulation $emulation
    ) {
    }

    /** @return array<string, string> */
    public function getRecords(string $templateId, int $limit): array
    {
        $records = [];
        foreach ($this->reminderResource->getPreviewCarts($limit) as $row) {
            $records[(string) $row['quote_id']] = implode(' · ', array_filter([
                'Cart #' . $row['quote_id'],
                trim((string) $row['customer_firstname'] . ' ' . $row['customer_email']),
                substr((string) $row['updated_at'], 0, 10),
            ]));
        }
        return $records;
    }

    /** The cart's store, which the reminder goes out from; null for an unknown cart. */
    public function getStoreId(string $templateId, string $recordId): ?int
    {
        $row = $this->reminderResource->getPreviewCarts(1, (int) $recordId)[0] ?? null;
        return $row ? (int) $row['store_id'] : null;
    }

    /** @return array<string, mixed> */
    public function getVariables(string $templateId, string $recordId): array
    {
        $row = $this->reminderResource->getPreviewCarts(1, (int) $recordId)[0] ?? null;
        if (!$row) {
            throw new LocalizedException(__('This cart is no longer active.'));
        }
        $storeId = (int) $row['store_id'];
        $quote = $this->quoteFactory->create()->loadByIdWithoutStore((int) $row['quote_id']);
        if (!$quote->getAllVisibleItems()) {
            throw new LocalizedException(__('This cart is empty, so no reminder would be sent.'));
        }

        $token = (string) $row['token'] !== '' ? (string) $row['token'] : self::PLACEHOLDER_TOKEN;
        // The cart's next reminder: the coupon it already has, else the one its email already got, else a placeholder
        // where the sender would mint one.
        $couponCode = (string) $row['coupon_code'];
        if ($this->sender->couponDue($couponCode, (int) $row['sent_count'], $storeId)) {
            $couponCode = $this->reminderResource->getEmailCoupon((string) $row['customer_email'])
                ?: ($this->helper->getCouponRuleId($storeId) ? self::PLACEHOLDER_COUPON : '');
        }

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            return $this->sender->templateVars($row, $quote, $token, $couponCode, $storeId);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
