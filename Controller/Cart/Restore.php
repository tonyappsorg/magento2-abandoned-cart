<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CouponManagementInterface;
use Magento\Quote\Model\QuoteFactory;
use Psr\Log\LoggerInterface;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder as ReminderResource;

/**
 * "Return to your cart" link. Guest carts are put back into the session; customer carts never log anyone in
 * from an email link - the shopper signs in and core merges their saved cart as usual.
 *
 * GET changes nothing: it renders a page that POSTs straight back (script, plus a button without it). Mail
 * scanners follow links but don't run scripts, so they can no longer apply the coupon or bump the cart's
 * updated_at, which restarted the reminder schedule. The page is same-origin, so the POST carries a form key and
 * core's CSRF check applies: another site can't plant its cart (and email) in a shopper's session.
 */
class Restore implements HttpGetActionInterface, HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly ReminderResource $reminderResource,
        private readonly QuoteFactory $quoteFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CouponManagementInterface $couponManagement,
        private readonly ManagerInterface $messageManager,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->request->isPost()) {
            return $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        }
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $reminder = $this->reminderResource->getByToken((string) $this->request->getParam('token', ''));
        if (!$reminder) {
            $this->messageManager->addErrorMessage(__('This cart link is no longer valid.'));
            return $redirect->setPath('/');
        }

        $quote = $this->quoteFactory->create()->loadByIdWithoutStore((int) $reminder['quote_id']);
        if (!$quote->getId() || !$quote->getIsActive()) {
            $this->messageManager->addNoticeMessage(__('This cart has already been checked out.'));
            return $redirect->setPath('/');
        }

        if ($quote->getCustomerId()) {
            if ((int) $this->customerSession->getCustomerId() !== (int) $quote->getCustomerId()) {
                $this->customerSession->setBeforeAuthUrl($this->url->getUrl('checkout/cart'));
                $this->messageManager->addNoticeMessage(__('Sign in to see your saved cart.'));
                return $redirect->setPath('customer/account/login');
            }
        } elseif (!$this->customerSession->isLoggedIn()) {
            $this->checkoutSession->replaceQuote($quote);
            $this->applyCoupon((int) $quote->getId(), (string) $reminder['coupon_code']);
        } else {
            // A guest cart is never swapped into a signed-in customer's session.
            $this->messageManager->addNoticeMessage(
                __('This link is for a cart saved without signing in. Sign out and open the link again to restore it.')
            );
        }

        return $redirect->setPath('checkout/cart');
    }

    private function applyCoupon(int $quoteId, string $code): void
    {
        if ($code === '') {
            return;
        }
        try {
            $this->couponManagement->set($quoteId, $code);
        } catch (\Exception $e) {
            // Expired or used up; the cart itself is still worth restoring.
            $this->logger->info('Abandoned cart coupon ' . $code . ' not applied: ' . $e->getMessage());
        }
    }
}
