<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_3;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @deprecated tag:v6.5.0 - reason:becomes-internal - Migrations will be internal in v6.5.0
 */
class Migration1605103533AddCookiesToAppEntity extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1605103533;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(self::cookiesColumn());
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }

    private static function cookiesColumn(): string
    {
        return <<<'EOF'
ALTER TABLE `app`
    ADD COLUMN `cookies` JSON NULL AFTER `modules`,
    ADD CONSTRAINT `json.app.cookies` CHECK (JSON_VALID(`cookies`));
EOF;
    }
}
