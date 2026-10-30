<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CouponManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\AbandonedCart\Controller\Cart\Restore;
use Tonyapps\AbandonedCart\Controller\Cart\Unsubscribe;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder;

class RestoreTest extends TestCase
{
    /** Restore POST comes from our own page with a form key; only one-click Unsubscribe may skip CSRF. */
    public function testOnlyUnsubscribeBypassesCsrfValidation(): void
    {
        self::assertFalse(is_subclass_of(Restore::class, CsrfAwareActionInterface::class));
        self::assertTrue(is_subclass_of(Unsubscribe::class, CsrfAwareActionInterface::class));
    }

    public function testSignedInShopperOpeningGuestCartLinkIsToldWhyNothingWasRestored(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('isPost')->willReturn(true);
        $request->method('getParam')->willReturn('token');
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('checkout/cart')->willReturnSelf();
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($redirect);
        $reminder = $this->createMock(Reminder::class);
        $reminder->method('getByToken')->willReturn(['quote_id' => 5, 'coupon_code' => '']);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['loadByIdWithoutStore', 'getId'])->getMock();
        $quote->method('loadByIdWithoutStore')->willReturnSelf();
        $quote->method('getId')->willReturn(5);
        $quote->setData('is_active', 1);
        $quoteFactory = $this->createMock(QuoteFactory::class);
        $quoteFactory->method('create')->willReturn($quote);
        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->expects(self::never())->method('replaceQuote');
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(true);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects(self::once())->method('addNoticeMessage');

        (new Restore(
            $request,
            $resultFactory,
            $reminder,
            $quoteFactory,
            $checkoutSession,
            $customerSession,
            $this->createMock(CouponManagementInterface::class),
            $messages,
            $this->createMock(UrlInterface::class),
            $this->createMock(LoggerInterface::class)
        ))->execute();
    }
}
