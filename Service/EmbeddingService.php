<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Service;

use MageCopilot\EdgeSync\Model\Config;
use Psr\Log\LoggerInterface;

class EmbeddingService
{
    private bool $warnedDeterministic = false;

    public function __construct(
        private Config $config,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Builds clean, rich semantic representation text for product embedding.
     *
     * @param array<string, mixed> $productData
     * @return string
     */
    public function buildEmbeddingText(array $productData): string
    {
        $parts = [];

        if (!empty($productData['name'])) {
            $parts[] = 'Producto: ' . (string) $productData['name'];
        }

        if (!empty($productData['sku'])) {
            $parts[] = 'SKU: ' . (string) $productData['sku'];
        }

        if (!empty($productData['category_names']) && is_array($productData['category_names'])) {
            $parts[] = 'Categorías: ' . implode(', ', array_map('strval', $productData['category_names']));
        }

        if (!empty($productData['brand'])) {
            $parts[] = 'Brand: ' . (string) $productData['brand'];
        }

        if (!empty($productData['color'])) {
            $parts[] = 'Color: ' . (string) $productData['color'];
        }

        if (!empty($productData['size'])) {
            $parts[] = 'Size: ' . (string) $productData['size'];
        }

        if (!empty($productData['short_description'])) {
            $cleanShort = trim(strip_tags((string) $productData['short_description']));
            if ($cleanShort !== '') {
                $parts[] = $cleanShort;
            }
        }

        if (!empty($productData['description'])) {
            $cleanDesc = trim(strip_tags((string) $productData['description']));
            if ($cleanDesc !== '') {
                // Limit description length to avoid token bloat
                $parts[] = mb_substr($cleanDesc, 0, 1000);
            }
        }

        return implode('. ', $parts);
    }

    /**
     * Generate a 512-dimension normalized embedding vector for the given text.
     *
     * @param string $text
     * @return float[]
     */
    public function generateEmbedding(string $text): array
    {
        $dimensions = $this->config->getEmbeddingDimensions();
        $provider = strtolower($this->config->getEmbeddingProvider());
        $apiKey = $this->config->getEmbeddingApiKey();

        if ($apiKey !== '' && ($provider === 'openai' || $provider === 'text-embedding-3-small')) {
            try {
                $vec = $this->callOpenAiEmbedding($text, $apiKey, $dimensions);
                if (!empty($vec)) {
                    return $vec;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MageCopilot_EdgeSync] OpenAI embedding failed, falling back to deterministic: ' . $e->getMessage());
            }
        }

        if ($apiKey !== '' && ($provider === 'gemini' || $provider === 'google')) {
            try {
                $vec = $this->callGeminiEmbedding($text, $apiKey, $dimensions);
                if (!empty($vec)) {
                    return $vec;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[MageCopilot_EdgeSync] Gemini embedding failed, falling back to deterministic: ' . $e->getMessage());
            }
        }

        if (!$this->warnedDeterministic) {
            $this->logger->warning('[MageCopilot_EdgeSync] Using deterministic embeddings — for production quality, configure OPENAI_API_KEY or GEMINI_API_KEY.');
            $this->warnedDeterministic = true;
        }

        return $this->generateDeterministicEmbedding($text, $dimensions);
    }

    /**
     * Generate embeddings for an array of texts.
     *
     * @param string[] $texts
     * @return array<int, float[]>
     */
    public function generateBatchEmbeddings(array $texts): array
    {
        $results = [];
        foreach ($texts as $idx => $text) {
            $results[$idx] = $this->generateEmbedding($text);
        }
        return $results;
    }

    /**
     * Calls OpenAI text-embedding-3-small with custom dimensions.
     *
     * @param string $text
     * @param string $apiKey
     * @param int $dimensions
     * @return float[]
     */
    private function callOpenAiEmbedding(string $text, string $apiKey, int $dimensions): array
    {
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize curl for OpenAI');
        }

        $payload = json_encode([
            'model' => $this->config->getEmbeddingModel() ?: 'text-embedding-3-small',
            'input' => $text,
            'dimensions' => $dimensions
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($httpCode !== 200 || !is_string($response)) {
            throw new \RuntimeException("OpenAI embedding returned HTTP {$httpCode}: " . substr((string) $response, 0, 200));
        }

        $json = json_decode($response, true);
        if (!isset($json['data'][0]['embedding']) || !is_array($json['data'][0]['embedding'])) {
            throw new \RuntimeException('Invalid OpenAI embedding response structure');
        }

        /** @var float[] $vector */
        $vector = array_map('floatval', $json['data'][0]['embedding']);
        return $this->normalizeVector($vector);
    }

    /**
     * Calls Google Gemini text-embedding-004.
     *
     * @param string $text
     * @param string $apiKey
     * @param int $dimensions
     * @return float[]
     */
    private function callGeminiEmbedding(string $text, string $apiKey, int $dimensions): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key=' . urlencode($apiKey);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize curl for Gemini');
        }

        $payload = json_encode([
            'model' => 'models/text-embedding-004',
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ]
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($httpCode !== 200 || !is_string($response)) {
            throw new \RuntimeException("Gemini embedding returned HTTP {$httpCode}");
        }

        $json = json_decode($response, true);
        if (!isset($json['embedding']['values']) || !is_array($json['embedding']['values'])) {
            throw new \RuntimeException('Invalid Gemini embedding response structure');
        }

        /** @var float[] $rawValues */
        $rawValues = array_map('floatval', $json['embedding']['values']);

        // Adjust dimensions to match schema if necessary
        if (count($rawValues) > $dimensions) {
            $rawValues = array_slice($rawValues, 0, $dimensions);
        } elseif (count($rawValues) < $dimensions) {
            $rawValues = array_pad($rawValues, $dimensions, 0.0);
        }

        return $this->normalizeVector($rawValues);
    }

    /**
     * High-quality deterministic semantic embedding projection based on character n-grams and hashing trick.
     * Generates a repeatable, cosine-normalized 512-dimension vector suitable for offline/local development & tests.
     *
     * @param string $text
     * @param int $dimensions
     * @return float[]
     */
    public function generateDeterministicEmbedding(string $text, int $dimensions = 512): array
    {
        $vector = array_fill(0, $dimensions, 0.0);
        $normalizedText = mb_strtolower(trim($text));

        if ($normalizedText === '') {
            return $vector;
        }

        // Tokenize words
        $words = preg_split('/[\s,\.\-_:;\(\)\[\]\/]+/u', $normalizedText, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            // Word-level hash feature
            $wordHash = abs(crc32($word));
            $idx1 = $wordHash % $dimensions;
            $sign1 = (($wordHash >> 8) & 1) === 1 ? 1.0 : -1.0;
            $vector[$idx1] += $sign1 * 1.5;

            // Character 3-gram and 4-gram features (captures stems, subwords, morphology)
            $len = mb_strlen($word);
            for ($i = 0; $i <= $len - 3; $i++) {
                $tri = mb_substr($word, $i, 3);
                $triHash = abs(crc32($tri));
                $idx2 = $triHash % $dimensions;
                $sign2 = (($triHash >> 4) & 1) === 1 ? 0.7 : -0.7;
                $vector[$idx2] += $sign2;
            }

            if ($len >= 4) {
                for ($i = 0; $i <= $len - 4; $i++) {
                    $quad = mb_substr($word, $i, 4);
                    $quadHash = abs(crc32($quad));
                    $idx3 = $quadHash % $dimensions;
                    $sign3 = (($quadHash >> 6) & 1) === 1 ? 0.5 : -0.5;
                    $vector[$idx3] += $sign3;
                }
            }
        }

        return $this->normalizeVector($vector);
    }

    /**
     * Normalizes a vector to unit length (L2 norm = 1.0) for accurate cosine similarity.
     *
     * @param float[] $vector
     * @return float[]
     */
    public function normalizeVector(array $vector): array
    {
        $sumSquares = 0.0;
        foreach ($vector as $val) {
            $sumSquares += $val * $val;
        }

        $norm = sqrt($sumSquares);
        if ($norm < 1e-9) {
            return $vector;
        }

        $normalized = [];
        foreach ($vector as $val) {
            $normalized[] = (float) round($val / $norm, 6);
        }

        return $normalized;
    }
}
