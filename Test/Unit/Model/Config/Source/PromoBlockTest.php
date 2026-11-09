<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Model\Config\Source;

use Magento\Cms\Model\Config\Source\Block;
use PHPUnit\Framework\TestCase;
use Tonyapps\AbandonedCart\Model\Config\Source\PromoBlock;

class PromoBlockTest extends TestCase
{
    public function testNoneComesBeforeTheCmsBlocks(): void
    {
        $blocks = $this->createMock(Block::class);
        $blocks->method('toOptionArray')->willReturn([['value' => 'cart-promo', 'label' => 'Cart promo']]);

        $options = (new PromoBlock($blocks))->toOptionArray();

        self::assertSame('', $options[0]['value']);
        self::assertSame(['value' => 'cart-promo', 'label' => 'Cart promo'], $options[1]);
    }
}
