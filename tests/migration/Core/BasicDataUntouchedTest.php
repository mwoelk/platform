<?php declare(strict_types=1);

namespace Shopware\Tests\Migration\Core;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Migration\V6_3\Migration1536233560BasicData;

/**
 * @internal
 * @coversNothing
 */
class BasicDataUntouchedTest extends TestCase
{
    public function testBasicDataUntouched(): void
    {
        $loader = KernelLifecycleManager::getClassLoader();
        /** @var string $file */
        $file = $loader->findFile(Migration1536233560BasicData::class);

        static::assertSame(
            '53542cb8532d6ece43554ebbda4ef0795f2bfeb7',
            sha1_file($file),
            'BasicData migration has changed. This is not allowed.'
        );
    }
}
