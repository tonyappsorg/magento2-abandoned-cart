<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Model;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\QuoteFactory;
use Magento\SalesRule\Model\CouponGenerator;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\AbandonedCart\Helper\Data as Helper;
use Tonyapps\AbandonedCart\Model\ReminderSchedule;
use Tonyapps\AbandonedCart\Model\ReminderSender;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder;

class ReminderSenderTest extends TestCase
{
    private Reminder&MockObject $resource;
    private CouponGenerator&MockObject $couponGenerator;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(Reminder::class);
        $this->couponGenerator = $this->createMock(CouponGenerator::class);
        // Cart idle 100h, nothing sent yet: with delays 1,24,72 dueStep() jumps to reminder #3, past coupon step #2.
        $this->resource->method('getCandidates')->willReturn([[
            'quote_id' => 5, 'store_id' => 1, 'customer_email' => 'a@example.com', 'customer_firstname' => 'A',
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 100 * 3600),
            'reminder_id' => 9, 'sent_count' => 0, 'token' => 't', 'coupon_code' => null,
        ]]);
    }

    public function testCouponIsAddedWhenCronSkipsPastTheCouponStep(): void
    {
        $this->resource->method('getEmailCoupon')->willReturn('');
        $this->couponGenerator->expects(self::once())->method('generateCodes')->willReturn(['NEWCODE']);
        $this->resource->expects(self::once())->method('setCoupon')->with(9, 'NEWCODE');

        $this->sender()->send();
    }

    public function testAnotherCartOfTheSameEmailDoesNotMintASecondCoupon(): void
    {
        $this->resource->method('getEmailCoupon')->with('a@example.com')->willReturn('OLDCODE');
        $this->couponGenerator->expects(self::never())->method('generateCodes');
        $this->resource->expects(self::once())->method('setCoupon')->with(9, 'OLDCODE');

        $this->sender()->send();
    }

    public function testEmailGetsItemCardsWithTheGiftMarkedFree(): void
    {
        $this->resource->method('getEmailCoupon')->willReturn('CODE');
        $product = $this->createMock(Product::class);
        $product->method('getProductUrl')->willReturn('https://shop/bag.html');
        $bag = $this->item('Bag', 2.0, 118.0, $product, false);
        $gift = $this->item('Bottle', 1.0, 0.0, $product, true);
        $image = $this->createMock(Image::class);
        $image->method('init')->with($product, 'category_page_grid')->willReturnSelf();
        $image->method('getUrl')->willReturn('https://shop/bag.jpg');
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('format')->willReturnCallback(static fn(float $amount) => '$' . number_format($amount, 2));

        $vars = null;
        $transportBuilder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo'] as $method) {
            $transportBuilder->method($method)->willReturnSelf();
        }
        $transportBuilder->method('setTemplateVars')->willReturnCallback(function (array $v) use (&$vars, $transportBuilder) {
            $vars = $v;
            throw new \RuntimeException('stop'); // vars captured; the send failure is logged and swallowed
        });

        $this->sender([$bag, $gift], $transportBuilder, $image, $currency, 118.0)->send();

        self::assertSame([
            ['name' => 'Bag', 'url' => 'https://shop/bag.html', 'image' => 'https://shop/bag.jpg', 'qty' => 'Qty: 2', 'price' => '$118.00'],
            ['name' => 'Bottle', 'url' => 'https://shop/bag.html', 'image' => 'https://shop/bag.jpg', 'qty' => 'Qty: 1', 'price' => 'Free gift'],
        ], $vars['items']);
        self::assertSame('$118.00', $vars['subtotal']);
        self::assertSame('Luma', $vars['storeName']);
        self::assertSame('CODE', $vars['couponCode']);
        self::assertSame('cart-promo', $vars['promoBlockId']);
    }

    private function item(string $name, float $qty, float $rowTotal, Product $product, bool $gift): QuoteItem
    {
        $item = $this->getMockBuilder(QuoteItem::class)->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getOptionByCode', 'getQty', 'getName'])
            ->addMethods(['getRowTotalInclTax'])->getMock();
        $item->method('getName')->willReturn($name);
        $item->method('getQty')->willReturn($qty);
        $item->method('getRowTotalInclTax')->willReturn($rowTotal);
        $item->method('getProduct')->willReturn($product);
        $item->method('getOptionByCode')->willReturnCallback(
            static fn(string $code) => $gift && $code === 'tonyapps_free_gift' ? new \Magento\Framework\DataObject() : null
        );
        return $item;
    }

    private function sender(
        ?array $items = null,
        ?TransportBuilder $transportBuilder = null,
        ?Image $image = null,
        ?PriceCurrencyInterface $currency = null,
        float $subtotal = 0.0
    ): ReminderSender {
        $helper = $this->createMock(Helper::class);
        $helper->method('getDelays')->willReturn([1.0, 24.0, 72.0]);
        $helper->method('isEnabled')->willReturn(true);
        $helper->method('getMaxAgeDays')->willReturn(7);
        $helper->method('getCouponStep')->willReturn(2);
        $helper->method('getCouponRuleId')->willReturn(4);
        $helper->method('getPromoBlock')->with(1)->willReturn('cart-promo');
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['loadByIdWithoutStore', 'getAllVisibleItems'])->addMethods(['getSubtotal', 'getQuoteCurrencyCode'])->getMock();
        $quote->method('loadByIdWithoutStore')->willReturnSelf();
        $quote->method('getAllVisibleItems')->willReturn($items ?? [new \Magento\Framework\DataObject()]);
        $quote->method('getSubtotal')->willReturn($subtotal);
        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quoteFactory->method('create')->willReturn($quote);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getFrontendName')->willReturn('Luma');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $storeManager->method('getStore')->willReturn($store);
        if (!$transportBuilder) {
            // Stop right after the coupon decision; the send failure is logged and swallowed.
            $transportBuilder = $this->createMock(TransportBuilder::class);
            $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('stop'));
        }

        return new ReminderSender(
            $this->resource,
            new ReminderSchedule(),
            $helper,
            $quoteFactory,
            $this->couponGenerator,
            $this->createMock(Random::class),
            $storeManager,
            $this->createMock(Emulation::class),
            $transportBuilder,
            $currency ?? $this->createMock(PriceCurrencyInterface::class),
            $this->createMock(Escaper::class),
            $this->createMock(LoggerInterface::class),
            $image ?? $this->createMock(Image::class)
        );
    }
}
