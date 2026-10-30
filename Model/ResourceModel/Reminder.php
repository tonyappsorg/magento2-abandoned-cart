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

    /** Email-keyed, so an opt-out survives core's quote cleanup, which cascades to the reminder rows. */
    public const OPTOUT_TABLE = 'tonyapps_abandonedcart_optout';

    protected function _construct()
    {
        $this->_init('tonyapps_abandonedcart_reminder', 'entity_id');
    }

    /**
     * Active carts in $storeIds with an email, younger than $maxAgeDays, whose next reminder is due,
     * whose email hasn't opted out and hasn't placed an order since the cart last changed (e.g. from another cart).
     *
     * "Due" is decided here, before the LIMIT: filtering only by the first delay let carts waiting for a later
     * reminder fill every batch, so new carts never got their first one.
     *
     * @param float[] $delays hours, ascending
     * @param int[] $storeIds
     * @return array<int, array<string, mixed>>
     */
    public function getCandidates(array $delays, int $maxAgeDays, array $storeIds, int $limit): array
    {
        $connection = $this->getConnection();
        $now = time();
        $idleSince = static fn (float $hours): string => gmdate(DateTime::DATETIME_PHP_FORMAT, $now - (int) round($hours * 3600));

        $dueBefore = 'CASE COALESCE(r.sent_count, 0)';
        foreach ($delays as $step => $hours) {
            $dueBefore .= ' WHEN ' . (int) $step . ' THEN ' . $connection->quote($idleSince($hours));
        }
        $dueBefore .= ' END';

        $optedOut = $connection->select()
            ->from(['u' => $this->getTable(self::OPTOUT_TABLE)], [new \Zend_Db_Expr('1')])
            ->where('u.email = q.customer_email');
        // ponytail: sales_order has no customer_email index; the created_at index bounds each probe to orders
        // placed since the cart changed (at most max-age days). Add an email index if order volume makes this slow.
        $orderedSince = $connection->select()
            ->from(['o' => $this->getTable('sales_order')], [new \Zend_Db_Expr('1')])
            ->where('o.created_at >= q.updated_at')
            ->where('o.customer_email = q.customer_email');

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
            ->where('q.store_id IN (?)', $storeIds)
            ->where('q.is_active = 1')
            ->where('q.items_count > 0')
            ->where("q.customer_email IS NOT NULL AND q.customer_email <> ''")
            ->where('q.updated_at <= ?', $idleSince($delays[0]))
            ->where('q.updated_at <= ' . $dueBefore)
            ->where('q.updated_at >= ?', gmdate(DateTime::DATETIME_PHP_FORMAT, $now - $maxAgeDays * 86400))
            ->where('r.entity_id IS NULL OR (r.status = ? AND r.sent_count < ' . count($delays) . ')', self::STATUS_ACTIVE)
            ->where('NOT EXISTS (' . $optedOut . ')')
            ->where('NOT EXISTS (' . $orderedSince . ')')
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

    /** A coupon already minted for this email by another cart's reminder, so extra carts don't mint extra coupons. */
    public function getEmailCoupon(string $email): string
    {
        $connection = $this->getConnection();
        return (string) $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), ['coupon_code'])
                ->where('email = ?', $email)
                ->where('coupon_code IS NOT NULL')
                ->order('entity_id DESC')
                ->limit(1)
        );
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

    /**
     * Opts the address out of reminders for every cart, past and future: the opt-out table is what candidate
     * selection checks. Reminder rows are flagged too, for the stats; recovered rows keep their status, except the
     * clicked one.
     */
    public function unsubscribe(string $email, int $reminderId): void
    {
        $this->getConnection()->insertOnDuplicate($this->getTable(self::OPTOUT_TABLE), ['email' => $email], ['email']);
        $this->getConnection()->update(
            $this->getMainTable(),
            ['status' => self::STATUS_UNSUBSCRIBED],
            ['email = ?' => $email, '(status <> ' . self::STATUS_RECOVERED . ' OR entity_id = ?)' => $reminderId]
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
