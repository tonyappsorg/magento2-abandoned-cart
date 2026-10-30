<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tonyapps\AbandonedCart\Model\ResourceModel\Reminder;

class ReminderTest extends TestCase
{
    public function testUnsubscribeRecordsEmailInOptOutTableThatOutlivesTheQuote(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate')
            ->with(Reminder::OPTOUT_TABLE, ['email' => 'a@example.com'], ['email']);
        $connection->expects(self::once())->method('update');

        $this->resource($connection)->unsubscribe('a@example.com', 3);
    }

    public function testCandidatesSkipOptedOutEmailsAndEmailsThatOrderedSinceTheCartChanged(): void
    {
        $from = [];
        $where = [];
        $select = $this->createMock(Select::class);
        foreach (['joinLeft', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('from')->willReturnCallback(function ($name) use (&$from, $select) {
            $from[] = $name;
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond) use (&$where, $select) {
            $where[] = (string) $cond;
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quote')->willReturnCallback(static fn ($v) => "'" . $v . "'");
        $connection->method('fetchAll')->willReturn([]);

        $this->resource($connection)->getCandidates([1.0], 7, [1], 10);

        self::assertContains(['u' => Reminder::OPTOUT_TABLE], $from);
        self::assertContains(['o' => 'sales_order'], $from);
        self::assertContains('o.customer_email = q.customer_email', $where);
        self::assertContains('o.created_at >= q.updated_at', $where);
        self::assertNotContains('u.status = ?', $where);
    }

    private function resource(AdapterInterface $connection): Reminder&MockObject
    {
        $resource = $this->getMockBuilder(Reminder::class)->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable', 'getTable'])->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('tonyapps_abandonedcart_reminder');
        $resource->method('getTable')->willReturnArgument(0);
        return $resource;
    }
}
