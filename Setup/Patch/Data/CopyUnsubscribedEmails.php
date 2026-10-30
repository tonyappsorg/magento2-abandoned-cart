<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder;

/** Opt-outs used to live only on reminder rows (status 2); copies the ones still there into the opt-out table. */
class CopyUnsubscribedEmails implements DataPatchInterface
{
    public function __construct(private readonly ModuleDataSetupInterface $setup)
    {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $select = $connection->select()->distinct()
            ->from($this->setup->getTable('tonyapps_abandonedcart_reminder'), ['email'])
            ->where('status = ?', Reminder::STATUS_UNSUBSCRIBED);
        $connection->query($connection->insertFromSelect(
            $select,
            $this->setup->getTable(Reminder::OPTOUT_TABLE),
            ['email'],
            AdapterInterface::INSERT_IGNORE
        ));
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
