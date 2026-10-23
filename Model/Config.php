<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'magecopilot_edgesync/general/enabled';
    private const XML_PATH_HOST = 'magecopilot_edgesync/general/typesense_host';
    private const XML_PATH_API_KEY = 'magecopilot_edgesync/general/typesense_api_key';
    private const XML_PATH_COLLECTION = 'magecopilot_edgesync/general/collection_name';
    private const XML_PATH_BATCH_SIZE = 'magecopilot_edgesync/general/batch_size';
    private const XML_PATH_EMBEDDING_ENABLED = 'magecopilot_edgesync/embedding/enabled';
    private const XML_PATH_EMBEDDING_PROVIDER = 'magecopilot_edgesync/embedding/provider';
    private const XML_PATH_EMBEDDING_API_KEY = 'magecopilot_edgesync/embedding/api_key';
    private const XML_PATH_EMBEDDING_MODEL = 'magecopilot_edgesync/embedding/model';
    private const XML_PATH_EMBEDDING_DIMENSIONS = 'magecopilot_edgesync/embedding/dimensions';

    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getHost(?int $storeId = null): string
    {
        $host = (string) $this->scopeConfig->getValue(self::XML_PATH_HOST, ScopeInterface::SCOPE_STORE, $storeId);
        return rtrim($host ?: 'http://typesense:8108', '/');
    }

    public function getApiKey(?int $storeId = null): string
    {
        $key = (string) $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
        return $key ?: 'magecopilot_local_dev_secret_key';
    }

    public function getCollectionName(?int $storeId = null): string
    {
        $col = (string) $this->scopeConfig->getValue(self::XML_PATH_COLLECTION, ScopeInterface::SCOPE_STORE, $storeId);
        return $col ?: 'products';
    }

    public function getBatchSize(?int $storeId = null): int
    {
        $size = (int) $this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE, ScopeInterface::SCOPE_STORE, $storeId);
        return $size > 0 ? $size : 250;
    }

    public function isEmbeddingEnabled(?int $storeId = null): bool
    {
        $val = $this->scopeConfig->getValue(self::XML_PATH_EMBEDDING_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
        return $val === null ? true : (bool) $val; // Default true for Sprint 10 semantic search
    }

    public function getEmbeddingProvider(?int $storeId = null): string
    {
        $provider = (string) $this->scopeConfig->getValue(self::XML_PATH_EMBEDDING_PROVIDER, ScopeInterface::SCOPE_STORE, $storeId);
        return $provider ?: 'deterministic';
    }

    public function getEmbeddingApiKey(?int $storeId = null): string
    {
        $key = (string) $this->scopeConfig->getValue(self::XML_PATH_EMBEDDING_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
        return $key ?: (getenv('OPENAI_API_KEY') ?: (getenv('GEMINI_API_KEY') ?: ''));
    }

    public function getEmbeddingModel(?int $storeId = null): string
    {
        $model = (string) $this->scopeConfig->getValue(self::XML_PATH_EMBEDDING_MODEL, ScopeInterface::SCOPE_STORE, $storeId);
        return $model ?: 'text-embedding-3-small';
    }

    public function getEmbeddingDimensions(?int $storeId = null): int
    {
        $dim = (int) $this->scopeConfig->getValue(self::XML_PATH_EMBEDDING_DIMENSIONS, ScopeInterface::SCOPE_STORE, $storeId);
        return $dim > 0 ? $dim : 512;
    }
}
