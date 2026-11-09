<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\View;

use Magento\Framework\Filter\Template;
use PHPUnit\Framework\TestCase;

class ReminderEmailTest extends TestCase
{
    public function testItemCardsLoopOverTheItemsVariable(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../../view/frontend/email/reminder.html');

        self::assertSame(1, preg_match(Template::LOOP_PATTERN, $html, $match));
        self::assertSame('items', trim($match['loopData']));
        foreach (['item.image', 'item.name', 'item.url', 'item.qty', 'item.price'] as $field) {
            self::assertStringContainsString('{{var ' . $field . '}}', $match['loopBody']);
        }
        // The for directive resolves only variables; a {{trans}} in the loop body renders empty.
        self::assertStringNotContainsString('{{trans', $match['loopBody']);
        self::assertStringContainsString('{{var restoreUrl}}', $html);
        self::assertStringContainsString('{{var unsubscribeUrl}}', $html);
        // The promotion CMS block renders only when one is configured.
        self::assertMatchesRegularExpression('/{{depend promoBlockId}}.*{{block id=\$promoBlockId}}.*{{\/depend}}/s', $html);
    }
}
