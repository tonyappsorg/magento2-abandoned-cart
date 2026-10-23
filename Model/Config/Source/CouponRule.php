<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;

class CouponRule implements OptionSourceInterface
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    public function toOptionArray(): array
    {
        $options = [['value' => 0, 'label' => __('-- No coupon --')]];
        $rules = $this->collectionFactory->create()
            ->addFieldToFilter('use_auto_generation', 1)
            ->addFieldToFilter('is_active', 1);
        foreach ($rules as $rule) {
            $options[] = ['value' => (int) $rule->getId(), 'label' => $rule->getName()];
        }
        return $options;
    }
}
