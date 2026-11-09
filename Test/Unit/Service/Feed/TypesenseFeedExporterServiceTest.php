<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Service\Feed;

use MageCopilot\EdgeSync\Model\Config;
use MageCopilot\EdgeSync\Service\Feed\TypesenseFeedExporterService;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Escaper;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TypesenseFeedExporterServiceTest extends TestCase
{
    /** @var Config&MockObject */
    private Config&MockObject $configMock;

    /** @var Filesystem&MockObject */
    private Filesystem&MockObject $filesystemMock;

    /** @var WriteInterface&MockObject */
    private WriteInterface&MockObject $mediaDirectoryMock;

    /** @var StoreManagerInterface&MockObject */
    private StoreManagerInterface&MockObject $storeManagerMock;

    /** @var Escaper&MockObject */
    private Escaper&MockObject $escaperMock;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface&MockObject $loggerMock;

    private TypesenseFeedExporterService $service;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(Config::class);
        $this->filesystemMock = $this->createMock(Filesystem::class);
        $this->mediaDirectoryMock = $this->createMock(WriteInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->escaperMock = $this->createMock(Escaper::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->filesystemMock->expects($this->once())
            ->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectoryMock);

        $this->service = new TypesenseFeedExporterService(
            $this->configMock,
            $this->filesystemMock,
            $this->storeManagerMock,
            $this->escaperMock,
            $this->loggerMock
        );
    }

    public function testExportThrowsExceptionForUnsupportedChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported feed channel "unsupported_channel"');

        $this->service->export('unsupported_channel');
    }

    public function testSupportedChannelsList(): void
    {
        $channels = TypesenseFeedExporterService::SUPPORTED_CHANNELS;
        $this->assertContains(TypesenseFeedExporterService::CHANNEL_GOOGLE, $channels);
        $this->assertContains(TypesenseFeedExporterService::CHANNEL_META, $channels);
        $this->assertContains(TypesenseFeedExporterService::CHANNEL_TIKTOK, $channels);
    }
}
