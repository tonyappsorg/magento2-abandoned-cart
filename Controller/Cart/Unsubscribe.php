<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Controller\Cart;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder as ReminderResource;

/**
 * One-click opt-out from the reminder email. The token is the only credential the recipient has.
 */
class Unsubscribe implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly ReminderResource $reminderResource,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $reminder = $this->reminderResource->getByToken((string) $this->request->getParam('token', ''));
        if ($reminder) {
            $this->reminderResource->unsubscribe((string) $reminder['email']);
            $this->messageManager->addSuccessMessage(__('You will no longer receive cart reminders.'));
        } else {
            $this->messageManager->addErrorMessage(__('This unsubscribe link is no longer valid.'));
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('/');
    }
}
