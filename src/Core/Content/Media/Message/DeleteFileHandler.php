<?php declare(strict_types=1);

namespace Shopware\Core\Content\Media\Message;

use League\Flysystem\AdapterInterface;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;

/**
 * @deprecated tag:v6.5.0 - reason:becomes-internal - Will only implement MessageHandlerInterface and all MessageHandler will be internal and final starting with v6.5.0.0
 */
class DeleteFileHandler extends AbstractMessageHandler
{
    private FilesystemInterface $filesystemPublic;

    private FilesystemInterface $filesystemPrivate;

    /**
     * @internal
     */
    public function __construct(FilesystemInterface $filesystemPublic, FilesystemInterface $filesystemPrivate)
    {
        $this->filesystemPublic = $filesystemPublic;
        $this->filesystemPrivate = $filesystemPrivate;
    }

    /**
     * @param DeleteFileMessage $message
     */
    public function handle($message): void
    {
        foreach ($message->getFiles() as $file) {
            try {
                $this->getFileSystem($message->getVisibility())->delete($file);
            } catch (FileNotFoundException $e) {
                //ignore file is already deleted
            }
        }
    }

    public static function getHandledMessages(): iterable
    {
        return [DeleteFileMessage::class];
    }

    private function getFileSystem(string $visibility): FilesystemInterface
    {
        switch ($visibility) {
            case AdapterInterface::VISIBILITY_PUBLIC:
                return $this->filesystemPublic;
            case AdapterInterface::VISIBILITY_PRIVATE:
                return $this->filesystemPrivate;
            default:
                throw new \RuntimeException('Invalid filesystem visibility.');
        }
    }
}
