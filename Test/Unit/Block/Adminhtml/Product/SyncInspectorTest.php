<?php

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Block\Adminhtml\Product;

use MageCopilot\EdgeSync\Block\Adminhtml\Product\SyncInspector;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SyncInspectorTest extends TestCase
{
    private Context&MockObject $contextMock;
    private Registry&MockObject $registryMock;
    private UrlInterface&MockObject $urlBuilderMock;
    private SyncInspector $block;

    protected function setUp(): void
    {
        $omMock = $this->createMock(\Magento\Framework\App\ObjectManager::class);
        \Magento\Framework\App\ObjectManager::setInstance($omMock);

        $this->contextMock = $this->createMock(Context::class);
        $this->registryMock = $this->createMock(Registry::class);
        $this->urlBuilderMock = $this->createMock(UrlInterface::class);

        $this->contextMock->method('getUrlBuilder')->willReturn($this->urlBuilderMock);

        $this->block = new SyncInspector(
            $this->contextMock,
            $this->registryMock
        );
    }

    public function testGetProductReturnsProductFromRegistry(): void
    {
        $productMock = $this->createMock(Product::class);
        $productMock->method('getId')->willReturn(101);
        $productMock->method('getSku')->willReturn('SKU-101');

        $this->registryMock->method('registry')->with('current_product')->willReturn($productMock);

        $this->assertSame($productMock, $this->block->getProduct());
        $this->assertSame(101, $this->block->getProductId());
        $this->assertSame('SKU-101', $this->block->getProductSku());
    }

    public function testGetProductReturnsNullWhenNotInRegistry(): void
    {
        $this->registryMock->method('registry')->with('current_product')->willReturn(null);

        $this->assertNull($this->block->getProduct());
        $this->assertNull($this->block->getProductId());
        $this->assertNull($this->block->getProductSku());
    }

    public function testActionUrls(): void
    {
        $this->urlBuilderMock->method('getUrl')->willReturnMap([
            ['magecopilot_edgesync/product/surgicalSync', [], 'https://admin.test/surgicalSync'],
            ['magecopilot/product/generatePreviewToken', [], 'https://admin.test/generatePreviewToken']
        ]);

        $this->assertSame('https://admin.test/surgicalSync', $this->block->getSurgicalSyncUrl());
        $this->assertSame('https://admin.test/generatePreviewToken', $this->block->getGeneratePreviewTokenUrl());
    }
}
