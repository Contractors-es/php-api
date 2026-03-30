<?php

declare(strict_types=1);

namespace ContractorsEs\Api\Tests\Unit;

use ContractorsEs\Api\Api;
use ContractorsEs\Api\ApiRequestException;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    public function testApiRequestExceptionExposesStatusAndResponseBody(): void
    {
        $exception = new ApiRequestException('Request failed', 422, '{"error":"validation"}');

        $this->assertSame(422, $exception->getStatusCode());
        $this->assertSame('{"error":"validation"}', $exception->getResponseBody());
    }

    public function testApiCanBeInstantiatedFromNamespace(): void
    {
        $api = new Api('https://example.test', 'user', 'pass', 'en');

        $this->assertInstanceOf(Api::class, $api);
    }
}
