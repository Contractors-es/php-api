<?php

declare(strict_types=1);

namespace ContractorsEs\Api\Tests\Unit;

use ContractorsEs\Api\Api;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ApiClientWorkflowTest extends TestCase
{
    public function testBatchApiUsesExpectedEndpointAndPayload(): void
    {
        $session = $this->createMock(Client::class);
        $api = $this->makeApiWithSession($session);

        $payload = [
            'resources' => [
                ['title' => 'Batch Meeting #1', 'start' => '2025-11-20 13:00:00', 'end' => '2025-11-20 14:00:00'],
                ['title' => 'Batch Meeting #2', 'start' => '2025-11-20 13:00', 'end' => '2025-11-20 14:00'],
            ],
        ];

        $session->expects($this->once())
            ->method('post')
            ->with(
                'https://example.test/api/crm/meetings/batch',
                $this->callback(function (array $options) use ($payload): bool {
                    $this->assertSame($payload, $options['json']);
                    $this->assertSame('Bearer test-token', $options['headers']['Authorization']);
                    $this->assertSame('en', $options['headers']['Accept-Language']);

                    return true;
                })
            )
            ->willReturn($this->jsonResponse(['data' => ['ok' => true]], 200));

        $response = $api->post('/api/crm/meetings/batch', $payload);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGetAllAggregatesPaginatedResults(): void
    {
        $session = $this->createMock(Client::class);
        $api = $this->makeApiWithSession($session);

        $requestUrls = [];

        $session->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $url, array $options) use (&$requestUrls): Response {
                $requestUrls[] = $url;
                $this->assertArrayHasKey('headers', $options);

                if (count($requestUrls) === 1) {
                    return $this->jsonResponse([
                        'data' => [
                            ['id' => 1, 'name' => 'A'],
                            ['id' => 2, 'name' => 'B'],
                        ],
                        'meta' => ['per_page' => 2],
                        'links' => ['next' => 'https://example.test/api/countries?page=2'],
                    ], 200);
                }

                return $this->jsonResponse([
                    'data' => [
                        ['id' => 3, 'name' => 'C'],
                    ],
                    'meta' => ['per_page' => 2],
                    'links' => ['next' => null],
                ], 200);
            });

        $items = $api->getAll('/api/countries?limit=2');

        $this->assertCount(3, $items);
        $this->assertSame(3, $items[2]['id']);
        $this->assertSame('https://example.test/api/countries?limit=2', $requestUrls[0]);
        $this->assertSame('https://example.test/api/countries?page=2&limit=2', $requestUrls[1]);
    }

    public function testGetFirstAddsLimitWhenMissing(): void
    {
        $session = $this->createMock(Client::class);
        $api = $this->makeApiWithSession($session);

        $session->expects($this->once())
            ->method('get')
            ->with(
                'https://example.test/api/crm/companies?limit=1',
                $this->isType('array')
            )
            ->willReturn($this->jsonResponse([
                'data' => [
                    ['id' => 55, 'company_name' => 'Acme'],
                ],
            ], 200));

        $company = $api->getFirst('/api/crm/companies');

        $this->assertSame(55, $company['id']);
    }

    public function testSearchPostsToSearchEndpoint(): void
    {
        $session = $this->createMock(Client::class);
        $api = $this->makeApiWithSession($session);

        $payload = [
            'filters' => [
                [
                    'type' => 'and',
                    'field' => 'company_name',
                    'operator' => 'like',
                    'value' => '%a%',
                ],
            ],
        ];

        $session->expects($this->once())
            ->method('post')
            ->with(
                'https://example.test/api/crm/companies/search',
                $this->callback(function (array $options) use ($payload): bool {
                    $this->assertSame($payload, $options['json']);

                    return true;
                })
            )
            ->willReturn($this->jsonResponse(['data' => []], 200));

        $response = $api->search('/api/crm/companies', $payload);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSearchFirstAddsLimitToSearchEndpoint(): void
    {
        $session = $this->createMock(Client::class);
        $api = $this->makeApiWithSession($session);

        $session->expects($this->once())
            ->method('post')
            ->with(
                'https://example.test/api/contractors/change-orders/search?limit=1',
                $this->isType('array')
            )
            ->willReturn($this->jsonResponse([
                'data' => [
                    ['id' => 9001],
                ],
            ], 200));

        $record = $api->searchFirst('/api/contractors/change-orders', ['filters' => []]);

        $this->assertSame(9001, $record['id']);
    }

    public function testCreateSendsJsonPayloadAndReturnsData(): void
    {
        $session = $this->createMock(Client::class);
        $api = $this->makeApiWithSession($session);

        $payload = [
            'title' => 'API Task',
            'priority' => 1,
        ];

        $session->expects($this->once())
            ->method('post')
            ->with(
                'https://example.test/api/crm/tasks',
                $this->callback(function (array $options) use ($payload): bool {
                    $this->assertSame($payload, $options['json']);

                    return true;
                })
            )
            ->willReturn($this->jsonResponse([
                'data' => ['id' => 111],
            ], 201));

        $created = $api->create('/api/crm/tasks', $payload);

        $this->assertSame(111, $created['id']);
    }

    public function testUpdateMergesJsonRequestOptions(): void
    {
        $session = $this->createMock(Client::class);
        $api = $this->makeApiWithSession($session);

        $session->expects($this->once())
            ->method('put')
            ->with(
                'https://example.test/api/crm/tasks/111',
                $this->callback(function (array $options): bool {
                    $this->assertSame('Done', $options['json']['title']);
                    $this->assertSame(2, $options['json']['status']);

                    return true;
                })
            )
            ->willReturn($this->jsonResponse([
                'data' => ['id' => 111, 'status' => 2],
            ], 200));

        $updated = $api->update('/api/crm/tasks/111', ['title' => 'Done'], ['json' => ['status' => 2]]);

        $this->assertSame(2, $updated['status']);
    }

    private function makeApiWithSession(Client&MockObject $session): Api
    {
        $api = new Api('https://example.test', 'user', 'pass', 'en');

        $tokenProperty = new \ReflectionProperty(Api::class, 'token');
        $tokenProperty->setAccessible(true);
        $tokenProperty->setValue($api, 'Bearer test-token');

        $sessionProperty = new \ReflectionProperty(Api::class, 'session');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($api, $session);

        return $api;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }
}
