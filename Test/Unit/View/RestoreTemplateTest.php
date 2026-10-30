<?php
declare(strict_types=1);

namespace Tonyapps\AbandonedCart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class RestoreTemplateTest extends TestCase
{
    /** Restore is CSRF-checked by core, so its self-submitting form must carry the form key. */
    public function testRestoreFormPostsFormKey(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../view/frontend/templates/restore.phtml');

        self::assertStringContainsString("getBlockHtml('formkey')", $template);
    }
}
