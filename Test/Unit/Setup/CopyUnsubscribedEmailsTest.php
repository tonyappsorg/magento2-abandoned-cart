<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder;
use Tonyapps\AbandonedCart\Setup\Patch\Data\CopyUnsubscribedEmails;

class CopyUnsubscribedEmailsTest extends TestCase
{
    public function testCopiesUnsubscribedReminderEmailsIntoOptOutTable(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('distinct')->willReturnSelf();
        $select->expects(self::once())->method('from')->with('tonyapps_abandonedcart_reminder', ['email'])->willReturnSelf();
        $select->expects(self::once())->method('where')->with('status = ?', Reminder::STATUS_UNSUBSCRIBED)->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())->method('insertFromSelect')
            ->with($select, Reminder::OPTOUT_TABLE, ['email'], AdapterInterface::INSERT_IGNORE)
            ->willReturn('INSERT IGNORE ...');
        $connection->expects(self::once())->method('query')->with('INSERT IGNORE ...');
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        (new CopyUnsubscribedEmails($setup))->apply();
    }
}
