<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Model;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Url;
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
use Tonyapps\AbandonedCart\Model\EmailPreview;
use Tonyapps\AbandonedCart\Model\ReminderSchedule;
use Tonyapps\AbandonedCart\Model\ReminderSender;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder;

class EmailPreviewTest extends TestCase
{
    private const TEMPLATE = 'tonyapps_abandonedcart_email_template';

    private Reminder&MockObject $resource;
    private CouponGenerator&MockObject $couponGenerator;
    private Random&MockObject $random;
    private Emulation&MockObject $emulation;
    private int $couponStep = 2;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(Reminder::class);
        $this->couponGenerator = $this->createMock(CouponGenerator::class);
        $this->random = $this->createMock(Random::class);
        $this->emulation = $this->createMock(Emulation::class);
    }

    public function testRecordsAreRecentCartsWithAnEmail(): void
    {
        $this->resource->expects(self::once())->method('getPreviewCarts')->with(20)->willReturn([
            $this->row(['quote_id' => 7, 'updated_at' => '2026-11-10 08:00:00']),
            $this->row(['quote_id' => 5, 'customer_firstname' => '', 'customer_email' => 'b@example.com']),
        ]);

        self::assertSame([
            '7' => 'Cart #7 · Jane a@example.com · 2026-11-10',
            '5' => 'Cart #5 · b@example.com · 2026-11-01',
        ], $this->preview($this->spy())->getRecords(self::TEMPLATE, 20));
    }

    public function testVariablesAreTheOnesTheSenderPassesForTheSameCart(): void
    {
        // A reminded cart that already has its coupon: the sender's next reminder and the preview must match exactly.
        $row = $this->row(['reminder_id' => 9, 'token' => 'tok', 'coupon_code' => 'SAVED', 'sent_count' => 1]);
        $this->resource->method('getCandidates')->willReturn([$row + ['updated_at' => gmdate('Y-m-d H:i:s', time() - 30 * 3600)]]);
        $this->resource->method('getPreviewCarts')->with(1, 5)->willReturn([$row]);
        $spy = $this->spy();

        $this->sender($spy)->send();
        $sent = $spy->vars;
        self::assertNotNull($sent, 'the sender built the email');

        $previewed = $this->preview($spy)->getVariables(self::TEMPLATE, '5');

        self::assertSame($sent, $previewed);
        self::assertSame('https://shop/abandonedcart/cart/restore/token/tok', $previewed['restoreUrl']);
        self::assertSame('SAVED', $previewed['couponCode']);
    }

    public function testCartWithoutReminderGetsPlaceholdersAndNothingIsWritten(): void
    {
        $this->couponStep = 1;
        $this->resource->method('getPreviewCarts')->willReturn([$this->row()]);
        $this->resource->method('getEmailCoupon')->willReturn('');
        $this->resource->expects(self::never())->method('create');
        $this->resource->expects(self::never())->method('setCoupon');
        $this->resource->expects(self::never())->method('markSent');
        $this->couponGenerator->expects(self::never())->method('generateCodes');
        $this->random->expects(self::never())->method('getUniqueHash');
        $this->emulation->expects(self::once())->method('startEnvironmentEmulation')->with(1);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');
        $spy = $this->spy();

        $vars = $this->preview($spy)->getVariables(self::TEMPLATE, '5');

        self::assertSame(EmailPreview::PLACEHOLDER_COUPON, $vars['couponCode']);
        self::assertSame('https://shop/abandonedcart/cart/restore/token/preview', $vars['restoreUrl']);
        self::assertSame('https://shop/abandonedcart/cart/unsubscribe/token/preview', $vars['unsubscribeUrl']);
        self::assertSame('Jane', $vars['customerName']);
        self::assertSame(0, $spy->calls, 'no email is built or sent');
    }

    public function testCouponAnotherCartOfTheEmailGotIsShown(): void
    {
        $this->couponStep = 1;
        $this->resource->method('getPreviewCarts')->willReturn([$this->row()]);
        $this->resource->method('getEmailCoupon')->with('a@example.com')->willReturn('OLDCODE');

        self::assertSame('OLDCODE', $this->preview($this->spy())->getVariables(self::TEMPLATE, '5')['couponCode']);
    }

    public function testNoCouponBeforeTheCouponReminder(): void
    {
        $this->couponStep = 3;
        $this->resource->method('getPreviewCarts')->willReturn([$this->row(['sent_count' => 1])]);
        $this->resource->expects(self::never())->method('getEmailCoupon');

        self::assertSame('', $this->preview($this->spy())->getVariables(self::TEMPLATE, '5')['couponCode']);
    }

    public function testStoreIsTheCartsStore(): void
    {
        $this->resource->method('getPreviewCarts')->willReturnCallback(
            fn (int $limit, ?int $quoteId) => $quoteId === 5 ? [$this->row(['store_id' => 3])] : []
        );
        $this->resource->expects(self::never())->method('create');

        $preview = $this->preview($this->spy());
        self::assertSame(3, $preview->getStoreId(self::TEMPLATE, '5'));
        self::assertNull($preview->getStoreId(self::TEMPLATE, '6'));
    }

    public function testEmulationStopsWhenBuildingFails(): void
    {
        $this->resource->method('getPreviewCarts')->willReturn([$this->row()]);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $this->expectException(\RuntimeException::class);
        $this->preview($this->spy(), true)->getVariables(self::TEMPLATE, '5');
    }

    /** @return array<string, mixed> */
    private function row(array $data = []): array
    {
        return $data + [
            'quote_id' => 5, 'store_id' => 1, 'customer_email' => 'a@example.com', 'customer_firstname' => 'Jane',
            'updated_at' => '2026-11-01 10:00:00', 'reminder_id' => null, 'sent_count' => null, 'token' => null,
            'coupon_code' => null,
        ];
    }

    private function preview(TransportBuilder $transportBuilder, bool $brokenImages = false): EmailPreview
    {
        return new EmailPreview(
            $this->resource,
            $this->sender($transportBuilder, $brokenImages),
            $this->helper(),
            $this->quoteFactory(),
            $this->emulation
        );
    }

    private function helper(): Helper
    {
        $helper = $this->createMock(Helper::class);
        $helper->method('getDelays')->willReturn([1.0, 24.0, 72.0]);
        $helper->method('isEnabled')->willReturn(true);
        $helper->method('getMaxAgeDays')->willReturn(7);
        $helper->method('getCouponStep')->willReturnCallback(fn () => $this->couponStep);
        $helper->method('getCouponRuleId')->willReturn(4);
        $helper->method('getPromoBlock')->willReturn('cart-promo');
        $helper->method('getTemplate')->willReturn(self::TEMPLATE);
        return $helper;
    }

    private function quoteFactory(): QuoteFactory
    {
        $product = $this->createMock(Product::class);
        $product->method('getProductUrl')->willReturn('https://shop/bag.html');
        $item = $this->getMockBuilder(QuoteItem::class)->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getOptionByCode', 'getQty', 'getName'])
            ->addMethods(['getRowTotalInclTax'])->getMock();
        $item->method('getName')->willReturn('Bag');
        $item->method('getQty')->willReturn(2.0);
        $item->method('getRowTotalInclTax')->willReturn(118.0);
        $item->method('getProduct')->willReturn($product);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['loadByIdWithoutStore', 'getAllVisibleItems'])->addMethods(['getSubtotal', 'getQuoteCurrencyCode'])->getMock();
        $quote->method('loadByIdWithoutStore')->willReturnSelf();
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $quote->method('getSubtotal')->willReturn(118.0);
        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quoteFactory->method('create')->willReturn($quote);
        return $quoteFactory;
    }

    private function sender(TransportBuilder $transportBuilder, bool $brokenImages = false): ReminderSender
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getFrontendName')->willReturn('Luma');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $storeManager->method('getStore')->willReturn($store);
        $image = $this->createMock(Image::class);
        if ($brokenImages) {
            $image->method('init')->willThrowException(new \RuntimeException('no image'));
        } else {
            $image->method('init')->willReturnSelf();
            $image->method('getUrl')->willReturn('https://shop/bag.jpg');
        }
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('format')->willReturnCallback(static fn (float $amount) => '$' . number_format($amount, 2));
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($value) => (string) $value);

        return new ReminderSender(
            $this->resource,
            new ReminderSchedule(),
            $this->helper(),
            $this->quoteFactory(),
            $this->couponGenerator,
            $this->random,
            $storeManager,
            $this->emulation,
            $transportBuilder,
            $currency,
            $escaper,
            $this->createMock(LoggerInterface::class),
            $image,
            $this->frontendUrl()
        );
    }

    /** Records the vars the sender passes, then stops the send (the sender logs and swallows the failure). */
    private function spy(): TransportBuilder
    {
        return new class extends TransportBuilder {
            public ?array $vars = null;
            public int $calls = 0;

            public function __construct()
            {
            }

            public function setTemplateIdentifier($templateIdentifier)
            {
                $this->calls++;
                return $this;
            }

            public function setTemplateOptions($templateOptions)
            {
                $this->calls++;
                return $this;
            }

            public function setTemplateVars($templateVars)
            {
                $this->calls++;
                $this->vars = $templateVars;
                throw new \RuntimeException('stop');
            }
        };
    }

    /** Storefront URL builder: https://shop/<route>/token/<token>. */
    private function frontendUrl(): Url
    {
        $url = $this->createMock(Url::class);
        $url->method('setScope')->willReturnSelf();
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params) => 'https://shop/' . $route . '/token/' . $params['token']
        );
        return $url;
    }
}
