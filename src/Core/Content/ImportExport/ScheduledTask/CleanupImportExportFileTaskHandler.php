<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport\ScheduledTask;

use Shopware\Core\Content\ImportExport\Service\DeleteExpiredFilesService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

/**
 * @deprecated tag:v6.5.0 - reason:becomes-internal - MessageHandler will be internal and final starting with v6.5.0.0
 */
class CleanupImportExportFileTaskHandler extends ScheduledTaskHandler
{
    private DeleteExpiredFilesService $deleteExpiredFilesService;

    /**
     * @internal
     */
    public function __construct(
        EntityRepository $repository,
        DeleteExpiredFilesService $deleteExpiredFilesService
    ) {
        parent::__construct($repository);

        $this->deleteExpiredFilesService = $deleteExpiredFilesService;
    }

    public static function getHandledMessages(): iterable
    {
        return [CleanupImportExportFileTask::class];
    }

    public function run(): void
    {
        $this->deleteExpiredFilesService->deleteFiles(Context::createDefaultContext());
    }
}
