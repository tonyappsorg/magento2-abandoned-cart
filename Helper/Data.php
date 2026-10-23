<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Tonyapps\AbandonedCart\Model\ReminderSchedule;

class Data extends AbstractHelper
{
    private const XML_PATH_ENABLED = 'tonyapps_abandonedcart/general/enabled';
    private const XML_PATH_DELAYS = 'tonyapps_abandonedcart/general/delays';
    private const XML_PATH_MAX_AGE = 'tonyapps_abandonedcart/general/max_age_days';
    private const XML_PATH_IDENTITY = 'tonyapps_abandonedcart/email/identity';
    private const XML_PATH_TEMPLATE = 'tonyapps_abandonedcart/email/template';
    private const XML_PATH_COUPON_RULE = 'tonyapps_abandonedcart/coupon/rule_id';
    private const XML_PATH_COUPON_STEP = 'tonyapps_abandonedcart/coupon/step';

    public function __construct(Context $context, private readonly ReminderSchedule $schedule)
    {
        parent::__construct($context);
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Global on purpose: the cron pre-filters quotes in SQL with these, so they can't vary per store.
     *
     * @return float[]
     */
    public function getDelays(): array
    {
        return $this->schedule->parseDelays((string) $this->scopeConfig->getValue(self::XML_PATH_DELAYS));
    }

    public function getMaxAgeDays(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_PATH_MAX_AGE));
    }

    public function getIdentity(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_IDENTITY, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getTemplate(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_TEMPLATE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getCouponRuleId(int $storeId): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_COUPON_RULE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** One-based reminder number that carries the coupon. */
    public function getCouponStep(int $storeId): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_COUPON_STEP, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
