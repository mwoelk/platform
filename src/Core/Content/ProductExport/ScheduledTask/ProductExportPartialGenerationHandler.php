<?php declare(strict_types=1);

namespace Shopware\Core\Content\ProductExport\ScheduledTask;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\Service\ProductExportFileHandlerInterface;
use Shopware\Core\Content\ProductExport\Service\ProductExportGeneratorInterface;
use Shopware\Core\Content\ProductExport\Service\ProductExportRendererInterface;
use Shopware\Core\Content\ProductExport\Struct\ExportBehavior;
use Shopware\Core\Content\ProductExport\Struct\ProductExportResult;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Translation\Translator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;
use Shopware\Core\Framework\Routing\Exception\SalesChannelNotFoundException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @deprecated tag:v6.5.0 - reason:becomes-internal - Will only implement MessageHandlerInterface and all MessageHandler will be internal and final starting with v6.5.0.0
 */
class ProductExportPartialGenerationHandler extends AbstractMessageHandler
{
    private AbstractSalesChannelContextFactory $salesChannelContextFactory;

    private EntityRepository $productExportRepository;

    private ProductExportGeneratorInterface $productExportGenerator;

    private int $readBufferSize;

    private MessageBusInterface $messageBus;

    private ProductExportFileHandlerInterface $productExportFileHandler;

    private ProductExportRendererInterface $productExportRender;

    private Translator $translator;

    private SalesChannelContextServiceInterface $salesChannelContextService;

    private SalesChannelContextPersister $contextPersister;

    private Connection $connection;

    private LanguageLocaleCodeProvider $languageLocaleProvider;

    /**
     * @internal
     */
    public function __construct(
        ProductExportGeneratorInterface $productExportGenerator,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        EntityRepository $productExportRepository,
        ProductExportFileHandlerInterface $productExportFileHandler,
        MessageBusInterface $messageBus,
        ProductExportRendererInterface $productExportRender,
        Translator $translator,
        SalesChannelContextServiceInterface $salesChannelContextService,
        SalesChannelContextPersister $contextPersister,
        Connection $connection,
        int $readBufferSize,
        LanguageLocaleCodeProvider $languageLocaleProvider
    ) {
        $this->productExportGenerator = $productExportGenerator;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->productExportRepository = $productExportRepository;
        $this->productExportFileHandler = $productExportFileHandler;
        $this->messageBus = $messageBus;
        $this->productExportRender = $productExportRender;
        $this->translator = $translator;
        $this->salesChannelContextService = $salesChannelContextService;
        $this->contextPersister = $contextPersister;
        $this->connection = $connection;
        $this->readBufferSize = $readBufferSize;
        $this->languageLocaleProvider = $languageLocaleProvider;
    }

    public static function getHandledMessages(): iterable
    {
        return [
            ProductExportPartialGeneration::class,
        ];
    }

    /**
     * @param ProductExportPartialGeneration $productExportPartialGeneration
     *
     * @throws SalesChannelNotFoundException
     * @throws InconsistentCriteriaIdsException
     */
    public function handle($productExportPartialGeneration): void
    {
        $context = $this->getContext($productExportPartialGeneration);
        $productExport = $this->fetchProductExport($productExportPartialGeneration, $context);

        if (!$productExport) {
            return;
        }

        $exportResult = $this->runExport($productExport, $productExportPartialGeneration->getOffset(), $context);

        $filePath = $this->productExportFileHandler->getFilePath($productExport, true);

        if ($exportResult === null) {
            $this->finalizeExport($productExport, $filePath);

            return;
        }

        $this->productExportFileHandler->writeProductExportContent(
            $exportResult->getContent(),
            $filePath,
            $productExportPartialGeneration->getOffset() > 0
        );

        if ($productExportPartialGeneration->getOffset() + $this->readBufferSize < $exportResult->getTotal()) {
            $this->messageBus->dispatch(
                new ProductExportPartialGeneration(
                    $productExportPartialGeneration->getProductExportId(),
                    $productExportPartialGeneration->getSalesChannelId(),
                    $productExportPartialGeneration->getOffset() + $this->readBufferSize
                )
            );

            return;
        }

        $this->finalizeExport($productExport, $filePath);
    }

    private function getContext(ProductExportPartialGeneration $productExportPartialGeneration): Context
    {
        $context = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $productExportPartialGeneration->getSalesChannelId()
        );

        if ($context->getSalesChannel()->getTypeId() !== Defaults::SALES_CHANNEL_TYPE_STOREFRONT) {
            throw new SalesChannelNotFoundException();
        }

        return $context->getContext();
    }

    private function fetchProductExport(
        ProductExportPartialGeneration $productExportPartialGeneration,
        Context $context
    ): ?ProductExportEntity {
        $criteria = new Criteria([$productExportPartialGeneration->getProductExportId()]);
        $criteria
            ->addAssociation('salesChannel')
            ->addAssociation('salesChannelDomain.salesChannel')
            ->addAssociation('salesChannelDomain.language.locale')
            ->addAssociation('productStream.filters.queries')
            ->setLimit(1);

        return $this->productExportRepository
            ->search($criteria, $context)
            ->first();
    }

    private function runExport(
        ProductExportEntity $productExport,
        int $offset,
        Context $context
    ): ?ProductExportResult {
        $this->productExportRepository->update([[
            'id' => $productExport->getId(),
            'isRunning' => true,
        ]], $context);

        return $this->productExportGenerator->generate(
            $productExport,
            new ExportBehavior(
                false,
                false,
                true,
                false,
                false,
                $offset
            )
        );
    }

    private function finalizeExport(ProductExportEntity $productExport, string $filePath): void
    {
        $contextToken = Uuid::randomHex();
        $this->contextPersister->save(
            $contextToken,
            [
                SalesChannelContextService::CURRENCY_ID => $productExport->getCurrencyId(),
            ],
            $productExport->getSalesChannelId()
        );

        $context = $this->salesChannelContextService->get(
            new SalesChannelContextServiceParameters(
                $productExport->getStorefrontSalesChannelId(),
                $contextToken,
                $productExport->getSalesChannelDomain()->getLanguageId(),
                $productExport->getSalesChannelDomain()->getCurrencyId() ?? $productExport->getCurrencyId()
            )
        );

        $this->translator->injectSettings(
            $productExport->getStorefrontSalesChannelId(),
            $productExport->getSalesChannelDomain()->getLanguageId(),
            $this->languageLocaleProvider->getLocaleForLanguageId($productExport->getSalesChannelDomain()->getLanguageId()),
            $context->getContext()
        );

        $headerContent = $this->productExportRender->renderHeader($productExport, $context);
        $footerContent = $this->productExportRender->renderFooter($productExport, $context);
        $finalFilePath = $this->productExportFileHandler->getFilePath($productExport);

        $this->translator->resetInjection();

        $writeProductExportSuccessful = $this->productExportFileHandler->finalizePartialProductExport(
            $filePath,
            $finalFilePath,
            $headerContent,
            $footerContent
        );

        $this->connection->delete('sales_channel_api_context', ['token' => $contextToken]);

        if (!$writeProductExportSuccessful) {
            return;
        }

        $this->productExportRepository->update(
            [
                [
                    'id' => $productExport->getId(),
                    'generatedAt' => new \DateTime(),
                    'isRunning' => false,
                ],
            ],
            $context->getContext()
        );
    }
}
