<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use Magento\Directory\Model\CurrencyFactory;

/**
 * Resolves dynamic foreign exchange (FX) rates using Magento's directory currency tables.
 */
class CurrencyRateResolver
{
    /** @var array<string, float> */
    private array $rateCache = [];

    /**
     * @param CurrencyFactory $currencyFactory
     */
    public function __construct(
        private CurrencyFactory $currencyFactory
    ) {
    }

    /**
     * Resolve currency exchange rate from base to target currency code.
     *
     * @param string $baseCurrencyCode
     * @param string $targetCurrencyCode
     * @return float
     */
    public function getRate(string $baseCurrencyCode, string $targetCurrencyCode): float
    {
        $baseCurrencyCode = strtoupper(trim($baseCurrencyCode));
        $targetCurrencyCode = strtoupper(trim($targetCurrencyCode));

        $key = "{$baseCurrencyCode}_{$targetCurrencyCode}";
        if (isset($this->rateCache[$key])) {
            return $this->rateCache[$key];
        }

        if ($baseCurrencyCode === $targetCurrencyCode) {
            return $this->rateCache[$key] = 1.0;
        }

        try {
            $base = $this->currencyFactory->create()->load($baseCurrencyCode);
            $rate = (float) $base->getRate($targetCurrencyCode);
            return $this->rateCache[$key] = ($rate > 0.0 ? $rate : 1.0);
        } catch (\Throwable) {
            return $this->rateCache[$key] = 1.0;
        }
    }
}
