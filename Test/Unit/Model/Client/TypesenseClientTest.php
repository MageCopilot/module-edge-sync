<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Test\Unit\Model\Client;

use MageCopilot\EdgeSync\Model\Client\TypesenseClient;
use MageCopilot\EdgeSync\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TypesenseClientTest extends TestCase
{
    private Config $configMock;
    private LoggerInterface $loggerMock;
    private TypesenseClient $client;

    protected function setUp(): void
    {
        $this->configMock = $this->createStub(Config::class);
        $this->loggerMock = $this->createStub(LoggerInterface::class);

        $this->configMock->method('getHost')->willReturn('http://127.0.0.1:8108');
        $this->configMock->method('getApiKey')->willReturn('test_key');

        $this->client = new TypesenseClient($this->configMock, $this->loggerMock);
    }

    public function testIsHealthyReturnsBoolean(): void
    {
        // Typesense is running in Docker on port 8108 in this environment
        $isHealthy = $this->client->isHealthy();
        $this->assertIsBool($isHealthy);
    }

    public function testCollectionExistsReturnsBoolean(): void
    {
        $exists = $this->client->collectionExists('products');
        $this->assertIsBool($exists);
    }
}
