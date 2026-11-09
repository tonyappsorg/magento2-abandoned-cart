<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Plugin;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Psr\Log\LoggerInterface;
use Tonyapps\AbandonedCart\Helper\Data as Helper;

/**
 * Saves the email a guest types at checkout on their cart, so the cart can be reminded.
 *
 * Luma's checkout keeps the guest email in the browser until the order is placed, but it always calls
 * POST /V1/customers/isEmailAvailable once the address is valid (even with Enable Guest Checkout Login
 * off, when core answers without looking). That request carries the shopper's session, so the email
 * goes on the session's own guest cart. Registered for webapi_rest only; API clients without a
 * storefront session have no cart here and nothing happens.
 */
class SaveGuestEmailOnCart
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteResource $quoteResource,
        private readonly Helper $helper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $customerEmail
     * @param int|null $websiteId
     */
    public function afterIsEmailAvailable(
        AccountManagementInterface $subject,
        bool $result,
        $customerEmail,
        $websiteId = null
    ): bool {
        $email = trim((string) $customerEmail);
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $result;
        }
        try {
            // No cart in the session: don't create one just to look.
            if (!$this->checkoutSession->getQuoteId()) {
                return $result;
            }
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getId() || $quote->getCustomerId() || !$quote->getIsActive() || !(int) $quote->getItemsCount()
                || !$this->helper->isEnabled((int) $quote->getStoreId())
                || (string) $quote->getCustomerEmail() === $email
            ) {
                return $result;
            }
            $quote->setCustomerEmail($email);
            $this->quoteResource->save($quote);
        } catch (\Exception $e) {
            // The email check itself must never fail because of this.
            $this->logger->error('Abandoned cart: guest email not saved: ' . $e->getMessage());
        }
        return $result;
    }
}
