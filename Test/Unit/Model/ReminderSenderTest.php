<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Model;

use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
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

    private function sender(): ReminderSender
    {
        $helper = $this->createMock(Helper::class);
        $helper->method('getDelays')->willReturn([1.0, 24.0, 72.0]);
        $helper->method('isEnabled')->willReturn(true);
        $helper->method('getMaxAgeDays')->willReturn(7);
        $helper->method('getCouponStep')->willReturn(2);
        $helper->method('getCouponRuleId')->willReturn(4);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['loadByIdWithoutStore', 'getAllVisibleItems'])->getMock();
        $quote->method('loadByIdWithoutStore')->willReturnSelf();
        $quote->method('getAllVisibleItems')->willReturn([new \Magento\Framework\DataObject()]);
        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quoteFactory->method('create')->willReturn($quote);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $storeManager->method('getStore')->willReturn($store);
        // Stop right after the coupon decision; the send failure is logged and swallowed.
        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willThrowException(new \RuntimeException('stop'));

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
            $this->createMock(PriceCurrencyInterface::class),
            $this->createMock(Escaper::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
