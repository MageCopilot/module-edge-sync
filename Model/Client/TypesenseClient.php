<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Model\Client;

use MageCopilot\EdgeSync\Model\Config;
use Psr\Log\LoggerInterface;

class TypesenseClient
{
    public function __construct(
        private Config $config,
        private LoggerInterface $logger
    ) {
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->request('GET', '/health');
            return isset($response['data']['ok']) && $response['data']['ok'] === true;
        } catch (\Throwable $e) {
            $this->logger->error('[MageCopilot_EdgeSync] Healthcheck failed: ' . $e->getMessage());
            return false;
        }
    }

    public function collectionExists(string $collectionName): bool
    {
        try {
            $response = $this->request('GET', '/collections/' . urlencode($collectionName));
            return $response['status'] === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param string $collectionName
     * @return array<string, mixed>|null
     */
    public function getCollection(string $collectionName): ?array
    {
        try {
            $response = $this->request('GET', '/collections/' . urlencode($collectionName));
            return $response['status'] === 200 ? $response['data'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function createCollection(array $schema): array
    {
        $response = $this->request('POST', '/collections', $schema);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(
                sprintf('Failed to create collection "%s": %s', $schema['name'] ?? '', json_encode($response['data']))
            );
        }
        return $response['data'];
    }

    public function deleteCollection(string $collectionName): bool
    {
        try {
            $response = $this->request('DELETE', '/collections/' . urlencode($collectionName));
            return $response['status'] === 200 || $response['status'] === 404;
        } catch (\Throwable $e) {
            $this->logger->warning('[MageCopilot_EdgeSync] Failed to delete collection: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Upsert a batch of documents into Typesense using JSONL import endpoint.
     *
     * @param string $collectionName
     * @param list<array<string, mixed>> $documents
     * @param string $action
     * @return array{success: bool, success_count: int, failed_count: int, items: list<mixed>}
     */
    public function importDocuments(string $collectionName, array $documents, string $action = 'upsert'): array
    {
        if (empty($documents)) {
            return ['success' => true, 'success_count' => 0, 'failed_count' => 0, 'items' => []];
        }

        $lines = [];
        foreach ($documents as $doc) {
            $lines[] = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $jsonlBody = implode("\n", $lines);

        $endpoint = sprintf('/collections/%s/documents/import?action=%s', urlencode($collectionName), urlencode($action));
        $response = $this->rawRequest('POST', $endpoint, $jsonlBody, 'text/plain');

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach (explode("\n", trim($response['body'])) as $line) {
            if (empty($line)) {
                continue;
            }
            $res = json_decode($line, true);
            $results[] = $res;
            if (isset($res['success']) && $res['success'] === true) {
                $successCount++;
            } else {
                $failedCount++;
                if (isset($res['error'])) {
                    $this->logger->warning('[MageCopilot_EdgeSync] Import item error: ' . $res['error']);
                }
            }
        }

        return [
            'success' => $failedCount === 0,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'items' => $results
        ];
    }

    /**
     * Update specific documents by ID or in batch.
     *
     * @param string $collectionName
     * @param list<array<string, mixed>> $documents
     * @return array{success: bool, success_count: int, failed_count: int, items: list<mixed>}
     */
    public function updateDocuments(string $collectionName, array $documents): array
    {
        return $this->importDocuments($collectionName, $documents, 'update');
    }

    /**
     * Update a single document by ID.
     *
     * @param string $collectionName
     * @param string $id
     * @param array<string, mixed> $document
     * @return array{success: bool, success_count: int, failed_count: int, items: list<mixed>}
     */
    public function updateDocument(string $collectionName, string $id, array $document): array
    {
        $document['id'] = $id;
        return $this->importDocuments($collectionName, [$document], 'update');
    }

    /**
     * Search documents in Typesense.
     *
     * @param string $collectionName
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function search(string $collectionName, array $queryParams): array
    {
        $queryString = http_build_query($queryParams);
        $endpoint = sprintf('/collections/%s/documents/search?%s', urlencode($collectionName), $queryString);
        $response = $this->request('GET', $endpoint);

        if ($response['status'] !== 200) {
            throw new \RuntimeException('Typesense search failed: ' . json_encode($response['data']));
        }

        return $response['data'];
    }

    /**
     * Retrieve a single document by ID.
     *
     * @param string $collectionName
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function getDocument(string $collectionName, string $id): ?array
    {
        try {
            $endpoint = sprintf('/collections/%s/documents/%s', urlencode($collectionName), urlencode($id));
            $response = $this->request('GET', $endpoint);
            return $response['status'] === 200 ? $response['data'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $jsonPayload
     * @return array{status: int, data: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $jsonPayload = null): array
    {
        $encoded = $jsonPayload !== null ? json_encode($jsonPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $body = ($encoded !== false) ? $encoded : null;
        $raw = $this->rawRequest($method, $path, $body, 'application/json');

        return [
            'status' => $raw['status'],
            'data' => json_decode($raw['body'], true) ?? []
        ];
    }

    /**
     * @param string $method
     * @param string $path
     * @param string|null $body
     * @param string $contentType
     * @return array{status: int, body: string}
     */
    private function rawRequest(string $method, string $path, ?string $body, string $contentType): array
    {
        $url = $this->config->getHost() . $path;
        $apiKey = $this->config->getApiKey();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $headers = [
            'X-TYPESENSE-API-KEY: ' . $apiKey,
            'Content-Type: ' . $contentType
        ];

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($responseBody === false) {
            throw new \RuntimeException(sprintf('Typesense HTTP request failed to %s: %s', $url, $error));
        }

        return [
            'status' => (int) $statusCode,
            'body' => (string) $responseBody
        ];
    }
}
