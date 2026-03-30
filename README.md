# Contractors API PHP Client

PHP client library for Contractors.es API.

API endpoint documentation is available at: https://api.contractors.es

## Requirements

- PHP 8.1+
- Guzzle 7.8+

## Installation

```bash
composer require contractors-es/php-api
```

## Quick Start

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use ContractorsEs\Api\Api;

$api = new Api(
    'https://demo.contractors.es',
    'admin',
    'admin',
    'en',
    getenv('API_2FA') ?: ''
);

$company = $api->first('/api/crm/companies');
print_r($company);
```

## More Usage Examples

### Get all countries (automatic pagination)

```php
$countries = $api->getAll('/api/countries?limit=50');
print_r($countries);
```

### Search companies

```php
$result = $api->searchAll('/api/crm/companies', [
    'filters' => [
        [
            'type' => 'and',
            'field' => 'company_name',
            'operator' => 'like',
            'value' => '%a%',
        ],
    ],
    'limit' => 25,
]);

print_r($result);
```

### Create and update task

```php
$createdTask = $api->create('/api/crm/tasks', [
    'title' => 'API Task ' . date('c'),
    'deadline_date' => date('Y-m-d'),
    'deadline_time' => date('H:i'),
    'priority' => 1,
]);

$taskId = $createdTask['id'];

$api->update("/api/crm/tasks/{$taskId}", [
    'status' => 2,
]);
```

### Batch meetings API

```php
$location = $api->getFirst('/api/crm/meeting-locations');

$api->post('/api/crm/meetings/batch', [
    'resources' => [
        [
            'title' => 'Batch Meeting #1',
            'start' => '2025-11-20 13:00:00',
            'end' => '2025-11-20 14:00:00',
            'priority' => 1,
            'schedule_type' => 'datetime',
            'location_id' => $location['id'] ?? null,
        ],
        [
            'title' => 'Batch Meeting #2',
            'start' => '2025-11-20 5:00 PM',
            'end' => '2025-11-20 06:00 PM',
            'priority' => 1,
            'schedule_type' => 'datetime',
            'location_id' => $location['id'] ?? null,
        ],
    ],
]);
```

### Attach relation (example: task employee)

```php
$contact = $api->getFirst('/api/crm/contacts');

if (!empty($contact)) {
    $api->post("/api/crm/tasks/{$taskId}/employees/attach", [
        'resources' => [$contact['id']],
    ]);
}
```

### Error handling

```php
use ContractorsEs\Api\ApiRequestException;

try {
    $api->record('/api/contractors/projects/999999');
} catch (ApiRequestException $e) {
    echo 'Status: ' . $e->getStatusCode() . PHP_EOL;
    echo 'Response: ' . $e->getResponseBody() . PHP_EOL;
}
```

## Authentication and Token Cache

The client authenticates with `/api/auth/login` and caches bearer token in:

- default: `sys_get_temp_dir()/contractors-api`
- custom: 6th constructor argument (`$tokenCacheDir`)

Example:

```php
$api = new Api($url, $user, $pass, $lang, $twoFactorToken, '/tmp/contractors-api-tokens');
```

## Running Tests

```bash
composer install
composer test
```
