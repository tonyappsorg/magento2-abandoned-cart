<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Plugin;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\AbandonedCart\Helper\Data as Helper;
use Tonyapps\AbandonedCart\Plugin\SaveGuestEmailOnCart;

class SaveGuestEmailOnCartTest extends TestCase
{
    public function testGuestCartGetsTheEmailAndTheAnswerIsUnchanged(): void
    {
        $quote = $this->quote();
        [$result, $saves] = $this->call($quote, 'guest@example.com', false);

        self::assertFalse($result);
        self::assertSame('guest@example.com', $quote->getCustomerEmail());
        self::assertSame(1, $saves);
    }

    /** @dataProvider skipped */
    public function testNothingIsSaved(array $quoteData, string $email, bool $enabled, bool $sessionHasCart = true): void
    {
        [$result, $saves] = $this->call($this->quote($quoteData), $email, true, $enabled, $sessionHasCart);

        self::assertTrue($result);
        self::assertSame(0, $saves);
    }

    public static function skipped(): array
    {
        return [
            'invalid email' => [[], 'not-an-email', true],
            'same email already on the cart' => [['customer_email' => 'guest@example.com'], 'guest@example.com', true],
            'signed-in customer' => [['customer_id' => 5], 'guest@example.com', true],
            'empty cart' => [['items_count' => 0], 'guest@example.com', true],
            'ordered cart' => [['is_active' => 0], 'guest@example.com', true],
            'reminders off' => [[], 'guest@example.com', false],
            'no cart in the session (API client)' => [[], 'guest@example.com', true, false],
        ];
    }

    private function quote(array $data = []): Quote
    {
        $data += ['entity_id' => 10, 'is_active' => 1, 'items_count' => 2, 'store_id' => 1];
        // Built without its constructor, the model has no id field name, so getId() is stubbed.
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $quote->method('getId')->willReturn($data['entity_id']);
        $quote->setData($data);
        return $quote;
    }

    /** @return array{0: bool, 1: int} the plugin's answer and the number of cart saves */
    private function call(Quote $quote, string $email, bool $available, bool $enabled = true, bool $sessionHasCart = true): array
    {
        $session = $this->getMockBuilder(CheckoutSession::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'getQuoteId'])->getMock();
        $session->method('getQuoteId')->willReturn($sessionHasCart ? 10 : null);
        $session->method('getQuote')->willReturn($quote);
        $helper = $this->createMock(Helper::class);
        $helper->method('isEnabled')->willReturn($enabled);
        $saves = 0;
        $resource = $this->createMock(QuoteResource::class);
        $resource->method('save')->willReturnCallback(function () use (&$saves, $resource) {
            $saves++;
            return $resource;
        });

        $result = (new SaveGuestEmailOnCart($session, $resource, $helper, $this->createMock(LoggerInterface::class)))
            ->afterIsEmailAvailable($this->createMock(AccountManagementInterface::class), $available, $email, 1);
        return [$result, $saves];
    }
}
