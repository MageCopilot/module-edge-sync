<?php

/**
 * MageCopilot - AI-Powered Store Operations Platform
 *
 * @category  MageCopilot
 * @package   MageCopilot_EdgeSync
 * @author    MageCopilot Team
 * @copyright Copyright (c) 2026 MageCopilot
 * @license   Proprietary
 */

declare(strict_types=1);

namespace MageCopilot\EdgeSync\Block\Adminhtml\Product;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;

/**
 * Sync Inspector Block for Catalog Product Edit Form
 */
class SyncInspector extends Template
{
    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private Registry $coreRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get current product from registry
     *
     * @return Product|null
     */
    public function getProduct(): ?Product
    {
        return $this->coreRegistry->registry('current_product');
    }

    /**
     * Get current product entity ID
     *
     * @return int|null
     */
    public function getProductId(): ?int
    {
        $product = $this->getProduct();
        return $product ? (int) $product->getId() : null;
    }

    /**
     * Get current product SKU
     *
     * @return string|null
     */
    public function getProductSku(): ?string
    {
        $product = $this->getProduct();
        return $product ? (string) $product->getSku() : null;
    }

    /**
     * Get surgical sync backend URL
     *
     * @return string
     */
    public function getSurgicalSyncUrl(): string
    {
        return $this->getUrl('magecopilot_edgesync/product/surgicalSync');
    }

    /**
     * Get preview token generation URL
     *
     * @return string
     */
    public function getGeneratePreviewTokenUrl(): string
    {
        return $this->getUrl('magecopilot/product/generatePreviewToken');
    }
}
