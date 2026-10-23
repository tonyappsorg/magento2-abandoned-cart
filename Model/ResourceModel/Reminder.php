<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Stdlib\DateTime;

/**
 * All reminder-table access in one place. Rows travel as plain arrays; nothing here needs a model object.
 */
class Reminder extends AbstractDb
{
    public const STATUS_ACTIVE = 0;
    public const STATUS_RECOVERED = 1;
    public const STATUS_UNSUBSCRIBED = 2;

    protected function _construct()
    {
        $this->_init('tonyapps_abandonedcart_reminder', 'entity_id');
    }

    /**
     * Active carts with an email, idle at least $minIdleHours, younger than $maxAgeDays,
     * that still have reminders left and whose email hasn't opted out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCandidates(float $minIdleHours, int $maxAgeDays, int $stepCount, int $limit): array
    {
        $connection = $this->getConnection();
        $now = time();

        $optedOut = $connection->select()
            ->from(['u' => $this->getMainTable()], [new \Zend_Db_Expr('1')])
            ->where('u.email = q.customer_email')
            ->where('u.status = ?', self::STATUS_UNSUBSCRIBED);

        $select = $connection->select()
            ->from(
                ['q' => $this->getTable('quote')],
                ['quote_id' => 'entity_id', 'store_id', 'customer_email', 'customer_firstname', 'updated_at']
            )
            ->joinLeft(
                ['r' => $this->getMainTable()],
                'r.quote_id = q.entity_id',
                ['reminder_id' => 'entity_id', 'sent_count', 'token', 'coupon_code']
            )
            ->where('q.is_active = 1')
            ->where('q.items_count > 0')
            ->where("q.customer_email IS NOT NULL AND q.customer_email <> ''")
            ->where('q.updated_at <= ?', gmdate(DateTime::DATETIME_PHP_FORMAT, $now - (int) round($minIdleHours * 3600)))
            ->where('q.updated_at >= ?', gmdate(DateTime::DATETIME_PHP_FORMAT, $now - $maxAgeDays * 86400))
            ->where('r.entity_id IS NULL OR (r.status = ? AND r.sent_count < ' . $stepCount . ')', self::STATUS_ACTIVE)
            ->where('NOT EXISTS (' . $optedOut . ')')
            ->order('q.updated_at ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    public function create(int $quoteId, string $email, int $storeId, string $token): int
    {
        $this->getConnection()->insert($this->getMainTable(), [
            'quote_id' => $quoteId,
            'email' => $email,
            'store_id' => $storeId,
            'token' => $token,
        ]);
        return (int) $this->getConnection()->lastInsertId($this->getMainTable());
    }

    public function setCoupon(int $reminderId, string $code): void
    {
        $this->getConnection()->update($this->getMainTable(), ['coupon_code' => $code], ['entity_id = ?' => $reminderId]);
    }

    public function markSent(int $reminderId, int $sentCount): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['sent_count' => $sentCount, 'last_sent_at' => gmdate(DateTime::DATETIME_PHP_FORMAT)],
            ['entity_id = ?' => $reminderId]
        );
    }

    /** @return array<string, mixed>|null */
    public function getByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getMainTable())->where('token = ?', $token)
        );
        return $row ?: null;
    }

    /** Opts the address out of reminders for every cart, past and future. */
    public function unsubscribe(string $email): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => self::STATUS_UNSUBSCRIBED],
            ['email = ?' => $email]
        );
    }

    /** Only carts that actually got a reminder count as recovered. */
    public function markRecovered(int $quoteId): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => self::STATUS_RECOVERED, 'recovered_at' => gmdate(DateTime::DATETIME_PHP_FORMAT)],
            ['quote_id = ?' => $quoteId, 'status = ?' => self::STATUS_ACTIVE, 'sent_count > 0']
        );
    }
}
