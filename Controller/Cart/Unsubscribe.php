<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Controller\Cart;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder as ReminderResource;

/**
 * Opt-out from the reminder email. GET only shows a confirm button, so mail scanners that follow links don't
 * unsubscribe anyone; POST does it. The token is the only credential the recipient has, so there is no form key:
 * mail clients' one-click List-Unsubscribe POST can't send one.
 */
class Unsubscribe implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
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
        if (!$this->request->isPost()) {
            return $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        }

        $reminder = $this->reminderResource->getByToken((string) $this->request->getParam('token', ''));
        if ($reminder) {
            $this->reminderResource->unsubscribe((string) $reminder['email'], (int) $reminder['entity_id']);
            $this->messageManager->addSuccessMessage(__('You will no longer receive cart reminders.'));
        } else {
            $this->messageManager->addErrorMessage(__('This unsubscribe link is no longer valid.'));
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('/');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
