<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Model\Config\Source;

use Magento\Cms\Model\Config\Source\Block;
use Magento\Framework\Data\OptionSourceInterface;

/** CMS blocks for the reminder email's promotion box, with "none" first. */
class PromoBlock implements OptionSourceInterface
{
    public function __construct(private readonly Block $blocks)
    {
    }

    public function toOptionArray(): array
    {
        return array_merge([['value' => '', 'label' => __('-- None --')]], $this->blocks->toOptionArray());
    }
}
