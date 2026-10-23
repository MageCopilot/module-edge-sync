<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Model;

use MageCopilot\EdgeSync\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfigMock;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfigMock);
    }

    public function testIsEnabledReturnsTrueWhenConfigured(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('isSetFlag')
            ->with('magecopilot_edgesync/general/enabled', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled(1));
    }

    public function testGetHostReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('magecopilot_edgesync/general/typesense_host', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(null);

        $this->assertSame('http://typesense:8108', $this->config->getHost());
    }

    public function testGetHostStripsTrailingSlash(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('magecopilot_edgesync/general/typesense_host', ScopeInterface::SCOPE_STORE, null)
            ->willReturn('http://typesense:8108/');

        $this->assertSame('http://typesense:8108', $this->config->getHost());
    }

    public function testGetApiKeyReturnsDefaultWhenEmpty(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('magecopilot_edgesync/general/typesense_api_key', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(null);

        $this->assertSame('magecopilot_local_dev_secret_key', $this->config->getApiKey());
    }

    public function testGetCollectionNameReturnsDefault(): void
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with('magecopilot_edgesync/general/collection_name', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(null);

        $this->assertSame('products', $this->config->getCollectionName());
    }

    public function testGetBatchSizeReturnsConfiguredOrFallback(): void
    {
        $this->scopeConfigMock->expects($this->exactly(2))
            ->method('getValue')
            ->with('magecopilot_edgesync/general/batch_size', ScopeInterface::SCOPE_STORE, null)
            ->willReturnOnConsecutiveCalls(0, 500);

        $this->assertSame(250, $this->config->getBatchSize());
        $this->assertSame(500, $this->config->getBatchSize());
    }
}
