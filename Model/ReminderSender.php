<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Model;

use Magento\Catalog\Helper\Image;
use Magento\Framework\App\Area;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\SalesRule\Model\CouponGenerator;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Tonyapps\AbandonedCart\Helper\Data as Helper;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder as ReminderResource;

class ReminderSender
{
    // ponytail: fixed batch per cron run. 500 every 15 min is 48k/day; raise it or page if a store outgrows that.
    private const BATCH_SIZE = 500;

    /** Cart item option Tonyapps_FreeGift puts on gift lines. */
    private const FREE_GIFT_OPTION = 'tonyapps_free_gift';

    public function __construct(
        private readonly ReminderResource $reminderResource,
        private readonly ReminderSchedule $schedule,
        private readonly Helper $helper,
        private readonly QuoteFactory $quoteFactory,
        private readonly CouponGenerator $couponGenerator,
        private readonly Random $random,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly TransportBuilder $transportBuilder,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
        private readonly Image $imageHelper
    ) {
    }

    public function send(): void
    {
        $delays = $this->helper->getDelays();
        if (!$delays) {
            return;
        }

        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->helper->isEnabled((int) $store->getId())) {
                $storeIds[] = (int) $store->getId();
            }
        }
        if (!$storeIds) {
            return;
        }

        $candidates = $this->reminderResource->getCandidates(
            $delays,
            $this->helper->getMaxAgeDays(),
            $storeIds,
            self::BATCH_SIZE
        );

        foreach ($candidates as $row) {
            $storeId = (int) $row['store_id'];
            $step = $this->schedule->dueStep(
                $delays,
                (int) ($row['sent_count'] ?? 0),
                (int) strtotime($row['updated_at'] . ' UTC'),
                time()
            );
            if ($step === null) {
                continue;
            }

            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                $this->process($row, $step, $storeId);
            } catch (\Exception $e) {
                $this->logger->error('Abandoned cart reminder for quote ' . $row['quote_id'] . ' failed: ' . $e->getMessage());
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function process(array $row, int $step, int $storeId): void
    {
        $quote = $this->quoteFactory->create()->loadByIdWithoutStore((int) $row['quote_id']);
        $items = $quote->getAllVisibleItems();
        if (!$items) {
            return;
        }

        $email = (string) $row['customer_email'];
        $reminderId = (int) $row['reminder_id'];
        $token = (string) $row['token'];
        if (!$reminderId) {
            $token = $this->random->getUniqueHash();
            $reminderId = $this->reminderResource->create((int) $row['quote_id'], $email, $storeId, $token);
        }

        $couponCode = (string) $row['coupon_code'];
        $couponStep = $this->helper->getCouponStep($storeId);
        // ">=": after a cron outage dueStep() skips straight to the latest reminder, past the coupon step.
        if ($couponCode === '' && $couponStep > 0 && $step + 1 >= $couponStep) {
            $couponCode = $this->reminderResource->getEmailCoupon($email) ?: $this->generateCoupon($storeId);
            if ($couponCode !== '') {
                // Saved before sending so a failed send retries with the same code instead of minting another.
                $this->reminderResource->setCoupon($reminderId, $couponCode);
            }
        }

        $store = $this->storeManager->getStore($storeId);
        $unsubscribeUrl = $store->getUrl('abandonedcart/cart/unsubscribe', ['token' => $token, '_nosid' => true]);
        $transport = $this->transportBuilder
            ->setTemplateIdentifier($this->helper->getTemplate($storeId))
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars([
                'customerName' => (string) $row['customer_firstname'] !== '' ? (string) $row['customer_firstname'] : (string) __('there'),
                // Kept for templates customised before the item cards existed.
                'itemsHtml' => $this->renderItems($quote, $storeId),
                'items' => $this->items($quote, $storeId),
                'subtotal' => $this->formatPrice((float) $quote->getSubtotal(), $quote, $storeId),
                'storeName' => (string) $store->getFrontendName(),
                'promoBlockId' => $this->helper->getPromoBlock($storeId),
                'restoreUrl' => $store->getUrl('abandonedcart/cart/restore', ['token' => $token, '_nosid' => true]),
                'unsubscribeUrl' => $unsubscribeUrl,
                'couponCode' => $couponCode,
            ])
            ->setFromByScope($this->helper->getIdentity($storeId), $storeId)
            ->addTo($email)
            ->getTransport();
        // One-click unsubscribe (RFC 8058): mail clients POST to the URL, which Unsubscribe accepts without a form key.
        $headers = $transport->getMessage()->getSymfonyMessage()->getHeaders();
        $headers->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $transport->sendMessage();

        $this->reminderResource->markSent($reminderId, $step + 1);
    }

    private function generateCoupon(int $storeId): string
    {
        $ruleId = $this->helper->getCouponRuleId($storeId);
        if (!$ruleId) {
            return '';
        }
        try {
            $codes = $this->couponGenerator->generateCodes(['rule_id' => $ruleId, 'qty' => 1, 'length' => 10]);
        } catch (\Exception $e) {
            // Reminder still goes out, just without the discount.
            $this->logger->error('Abandoned cart coupon from rule ' . $ruleId . ' failed: ' . $e->getMessage());
            return '';
        }
        return (string) ($codes[0] ?? '');
    }

    /**
     * One card per cart line for the email's {{for}} loop. Free gifts (Tonyapps_FreeGift lines) say so
     * instead of showing $0.00.
     *
     * @return array<int, array<string, string>>
     */
    private function items(Quote $quote, int $storeId): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $isGift = (bool) $item->getOptionByCode(self::FREE_GIFT_OPTION);
            $items[] = [
                'name' => (string) $item->getName(),
                'url' => $product ? (string) $product->getProductUrl() : '',
                'image' => $product ? (string) $this->imageHelper->init($product, 'category_page_grid')->getUrl() : '',
                'qty' => (string) __('Qty: %1', (float) $item->getQty()),
                'price' => $isGift
                    ? (string) __('Free gift')
                    : $this->formatPrice((float) $item->getRowTotalInclTax(), $quote, $storeId),
            ];
        }
        return $items;
    }

    private function formatPrice(float $amount, Quote $quote, int $storeId): string
    {
        return (string) $this->priceCurrency->format($amount, false, 2, $storeId, $quote->getQuoteCurrencyCode());
    }

    private function renderItems(Quote $quote, int $storeId): string
    {
        $rows = '';
        foreach ($quote->getAllVisibleItems() as $item) {
            $rows .= sprintf(
                '<tr><td style="padding:8px 0">%s &times; %s</td><td style="padding:8px 0 8px 16px;text-align:right">%s</td></tr>',
                $this->escaper->escapeHtml((string) (float) $item->getQty()),
                $this->escaper->escapeHtml($item->getName()),
                $this->escaper->escapeHtml(
                    $this->priceCurrency->format((float) $item->getRowTotalInclTax(), false, 2, $storeId, $quote->getQuoteCurrencyCode())
                )
            );
        }
        return $rows;
    }
}
