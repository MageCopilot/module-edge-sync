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
    public function __construct(
        Context $context,
        private Registry $coreRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProduct(): ?Product
    {
        return $this->coreRegistry->registry('current_product');
    }

    public function getProductId(): ?int
    {
        $product = $this->getProduct();
        return $product ? (int) $product->getId() : null;
    }

    public function getProductSku(): ?string
    {
        $product = $this->getProduct();
        return $product ? (string) $product->getSku() : null;
    }

    public function getSurgicalSyncUrl(): string
    {
        return $this->getUrl('magecopilot_edgesync/product/surgicalSync');
    }

    public function getGeneratePreviewTokenUrl(): string
    {
        return $this->getUrl('magecopilot/product/generatePreviewToken');
    }
}
