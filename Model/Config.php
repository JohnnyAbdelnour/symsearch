<?php
declare(strict_types=1);

namespace JALabs\SymSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const FIELD_NAME = 'embedding_vector';

    private const XML_ENABLED            = 'symsearch/general/enabled';
    private const XML_STOREFRONT_ENABLED = 'symsearch/general/storefront_enabled';
    private const XML_DEBUG              = 'symsearch/general/debug';
    private const XML_PROVIDER           = 'symsearch/provider/type';
    private const XML_API_KEY            = 'symsearch/provider/api_key';
    private const XML_MODEL              = 'symsearch/provider/model';
    private const XML_DIMENSIONS         = 'symsearch/provider/dimensions';
    private const XML_ATTRIBUTES         = 'symsearch/indexing/attributes';
    private const XML_BATCH_SIZE         = 'symsearch/indexing/batch_size';
    private const XML_THROTTLE_MS        = 'symsearch/indexing/throttle_ms';
    private const XML_KEYWORD_WEIGHT     = 'symsearch/ranking/keyword_weight';
    private const XML_SEMANTIC_WEIGHT    = 'symsearch/ranking/semantic_weight';
    private const XML_KNN_K              = 'symsearch/ranking/knn_k';
    private const XML_MIN_SCORE          = 'symsearch/ranking/min_score';
    private const XML_QUERY_TIMEOUT_MS   = 'symsearch/ranking/query_timeout_ms';
    private const XML_PAGINATION_DEPTH   = 'symsearch/ranking/pagination_depth';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /** Master switch: indexing infra (mapping, vectors, queue). Default scope. */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED);
    }

    /** Query-time hybrid rewrite, per store view. */
    public function isStorefrontEnabled(int $storeId): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_STOREFRONT_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isDebug(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_DEBUG);
    }

    public function getProviderCode(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PROVIDER);
    }

    public function getApiKey(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_API_KEY);
        return $value === '' ? '' : $this->encryptor->decrypt($value);
    }

    public function getModel(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_MODEL);
    }

    public function getDimensions(): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_DIMENSIONS);
    }

    public function getModelVersion(): string
    {
        return $this->getProviderCode() . ':' . $this->getModel() . ':' . $this->getDimensions();
    }

    /** @return string[] attribute codes */
    public function getEmbedAttributes(): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_ATTRIBUTES);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function getBatchSize(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_BATCH_SIZE));
    }

    /** Pause between embedding API calls during bulk generation (proactive rate-limit pacing). */
    public function getThrottleMs(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_THROTTLE_MS));
    }

    public function getKeywordWeight(): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_KEYWORD_WEIGHT);
    }

    public function getSemanticWeight(): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_SEMANTIC_WEIGHT);
    }

    public function getKnnK(int $storeId): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_KNN_K, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getMinScore(int $storeId): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_MIN_SCORE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getQueryTimeoutMs(int $storeId): int
    {
        return max(100, (int)$this->scopeConfig->getValue(self::XML_QUERY_TIMEOUT_MS, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getPaginationDepth(int $storeId): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PAGINATION_DEPTH, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
