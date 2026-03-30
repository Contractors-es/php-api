<?php

declare(strict_types=1);

namespace ContractorsEs\Api;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Custom API exceptions for better error handling
 */
class ApiException extends Exception {}
class ApiConfigurationException extends ApiException {}
class ApiRequestException extends ApiException
{
    protected int $statusCode;
    protected string $responseBody;

    public function __construct(string $message, int $statusCode = 0, string $responseBody = '', ?Exception $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
class ApiAuthenticationException extends ApiRequestException {}

class Api
{
    private string $token = "";
    private string $url = "";
    private string $username = "";
    private string $password = "";
    private string $twoFactorToken = "";
    private string $lang = "";
    private string $tokenCacheDir = "";
    private Client $session;

    public function __construct(
        string $url,
        string $username,
        string $password,
        string $lang,
        string $twoFactorToken = "",
        ?string $tokenCacheDir = null
    ) {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->lang = $lang;
        $this->twoFactorToken = $twoFactorToken;
        $this->tokenCacheDir = $tokenCacheDir ?: rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'contractors-api';
        $this->session = new Client([
            'base_uri' => $this->url,
            'headers' => [
                'Connection' => 'keep-alive'
            ],
            'timeout' => 30,
            'retry' => function (
                $retries,
                RequestException $exception
            ) {
                if ($retries >= 7) {
                    return false;
                }
                if ($exception->getResponse() && in_array($exception->getResponse()->getStatusCode(), [502, 503, 504])) {
                    return true;
                }
                return false;
            }
        ]);
    }

    private function getToken(): string
    {
        if ($this->token !== "") {
            return $this->token;
        }

        // generate md5 sum of username and url
        $md5sum = md5($this->username . $this->url);

        if (!is_dir($this->tokenCacheDir)) {
            mkdir($this->tokenCacheDir, 0775, true);
        }

        // if there is token.txt file, read it
        $tokenFile = $this->tokenCacheDir . DIRECTORY_SEPARATOR . "token_" . $md5sum . ".txt";
        if (file_exists($tokenFile)) {
            $this->token = file_get_contents($tokenFile);
            // check token by getting user info
            try {
                $response = $this->get($this->url . "/api/auth/user");
                if ($response->getStatusCode() !== 200) {
                    $this->token = "";
                }
            } catch (RequestException $e) {
                $this->token = "";
            } catch (ApiRequestException $e) {
                $this->token = "";
            }
        }

        // if token is not empty, return it
        if ($this->token !== "") {
            return $this->token;
        }

        // if url, username or password is empty, throw exception
        if ($this->url === "") {
            throw new ApiConfigurationException("No URL specified");
        }
        if ($this->username === "") {
            throw new ApiConfigurationException("No username specified");
        }
        if ($this->password === "") {
            throw new ApiConfigurationException("No password specified");
        }

        // if token is empty, get it from the API
        $args = [
            "username" => $this->username,
            "password" => $this->password,
            "useragent" => "api request"
        ];
        if ($this->twoFactorToken !== "") {
            $args["api_token"] = $this->twoFactorToken;
        }
        $response = $this->session->post("/api/auth/login", [
            'json' => $args
        ]);
        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody()->getContents();
            throw new ApiAuthenticationException(
                "Invalid credentials",
                $response->getStatusCode(),
                $responseBody,
                null
            );
        }

        $token = $response->getBody()->getContents();
        $token = json_decode($token, true);
        $this->token = $token['token']['token_type'] . " " . $token['token']['access_token'];

        // save token to token.txt file
        file_put_contents($tokenFile, $this->token);

        return $this->token;
    }

    public function __call($name, $arguments)
    {
        $headers = [
            "Authorization" => $this->getToken(),
            "Accept-Language" => $this->lang,
            "Accept" => "application/json"
        ];
        $timeout = 30;

        // merge headers with kwargs headers
        if (isset($arguments[1]['headers'])) {
            $headers = array_merge($headers, $arguments[1]['headers']);
            unset($arguments[1]['headers']);
        }

        // merge headers with kwargs headers
        if (isset($arguments[1]['timeout'])) {
            $timeout = $arguments[1]['timeout'];
            unset($arguments[1]['timeout']);
        }

        if (isset($arguments[0]['url'])) {
            // if url in kwargs doesn't start with http
            if (strpos($arguments[0]['url'], "http") === 0) {
                $url = $arguments[0]['url'];
            } else {
                $url = $this->url . $arguments[0]['url'];
            }
            unset($arguments[0]['url']);
        } else {
            // if url in kwargs doesn't start with http
            if (strpos($arguments[0], "http") === 0) {
                $url = $arguments[0];
            } else {
                $url = $this->url . $arguments[0];
            }
            array_shift($arguments);
        }

        $requestPayload = $arguments[0] ?? [];
        $requestOptions = [];

        if (is_array($requestPayload) && $this->isRequestOptionsArray($requestPayload)) {
            $requestOptions = $requestPayload;
        } else {
            $requestOptions['json'] = $requestPayload;
        }

        if (isset($requestOptions['headers']) && is_array($requestOptions['headers'])) {
            $headers = array_merge($headers, $requestOptions['headers']);
        }
        unset($requestOptions['headers']);

        if (isset($requestOptions['timeout'])) {
            $timeout = $requestOptions['timeout'];
        }
        unset($requestOptions['timeout']);

        $requestOptions['headers'] = $headers;
        $requestOptions['timeout'] = $timeout;

        try {
            $response = $this->session->$name($url, $requestOptions);
        } catch (RequestException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $requestBody = $e->getRequest() ? $e->getRequest()->getBody() : '';

            throw new ApiRequestException(
                "API request failed for {$url}: " . $e->getMessage(),
                $statusCode,
                json_encode([
                    'url' => $url,
                    'request_body' => (string)$requestBody,
                    'response_body' => $responseBody,
                    'original_message' => $e->getMessage()
                ]),
                $e
            );
        }

        return $response;
    }

    private function isRequestOptionsArray(array $payload): bool
    {
        $requestOptionKeys = [
            'multipart',
            'form_params',
            'body',
            'json',
            'query',
            'sink',
            'debug',
            'cookies',
            'auth',
            'headers',
            'timeout',
        ];

        foreach ($requestOptionKeys as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function payloadToMultipart(array $payload): array
    {
        $multipart = [];

        foreach ($payload as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $multipart[] = [
                        'name' => $name . '[]',
                        'contents' => is_scalar($item) || $item === null ? (string)$item : json_encode($item),
                    ];
                }
                continue;
            }

            $multipart[] = [
                'name' => (string)$name,
                'contents' => is_scalar($value) || $value === null ? (string)$value : json_encode($value),
            ];
        }

        return $multipart;
    }

    private function __first($method, $endpoint, ...$args)
    {
        //add limit=1 to endpoint if not present
        if (strpos($endpoint, "limit=") === false) {
            if (strpos($endpoint, "?") === false) {
                $endpoint .= "?limit=1";
            } else {
                $endpoint .= "&limit=1";
            }
        }
        $response = $this->$method($endpoint, ...$args);
        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody()->getContents();
            $requestBody = $response->getRequest()->getBody();
            throw new ApiRequestException(
                "API request failed for {$endpoint}",
                $response->getStatusCode(),
                json_encode([
                    'endpoint' => $endpoint,
                    'request_body' => (string)$requestBody,
                    'response_body' => $responseBody
                ]),
                null
            );
        }
        $data = json_decode($response->getBody()->getContents(), true);
        if (!isset($data["data"]) || !is_array($data["data"])) {
            trigger_error("API did not return an array of results: " . json_encode($data), E_USER_ERROR);
        }
        if (empty($data["data"])) {
            return null;
        }
        return $data["data"][0] ?? null;
    }

    private function __all($method, $endpoint, ...$args)
    {
        $response = $this->$method($endpoint, ...$args);
        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody()->getContents();
            $requestBody = $response->getRequest()->getBody();
            throw new ApiRequestException(
                "API request failed for {$endpoint}",
                $response->getStatusCode(),
                json_encode([
                    'endpoint' => $endpoint,
                    'request_body' => (string)$requestBody,
                    'response_body' => $responseBody
                ]),
                null
            );
        }
        $data = json_decode($response->getBody()->getContents(), true);
        $ret = $data["data"];

        if (!isset($data["meta"]) || !isset($data["meta"]["per_page"])) {
            return $ret;
        }

        $per_page = $data["meta"]["per_page"];

        $with_trashed = false;
        $only_trashed = false;
        if (strpos($endpoint, "with_trashed=") !== false) {
            $with_trashed = true;
        }
        if (strpos($endpoint, "only_trashed=") !== false) {
            $only_trashed = true;
        }

        while (isset($data["links"]["next"]) && $data["links"]["next"] !== null) {
            $response = $this->$method(
                $data["links"]["next"]
                    . "&limit="
                    . $per_page
                    . ($with_trashed ? "&with_trashed=true" : "")
                    . ($only_trashed ? "&only_trashed=true" : ""),
                ...$args
            );
            if ($response->getStatusCode() !== 200) {
                $responseBody = $response->getBody()->getContents();
                $requestBody = $response->getRequest()->getBody();
                throw new ApiRequestException(
                    "API request failed for pagination {$endpoint}",
                    $response->getStatusCode(),
                    json_encode([
                        'endpoint' => $endpoint,
                        'request_body' => (string)$requestBody,
                        'response_body' => $responseBody
                    ]),
                    null
                );
            }
            $data = json_decode($response->getBody()->getContents(), true);
            $ret = array_merge($ret, $data["data"]);
        }
        return $ret;
    }

    public function create($endpoint, $payload = [], array $requestOptions = [])
    {
        if (!empty($requestOptions)) {
            if (isset($requestOptions['multipart'])) {
                $requestOptions['multipart'] = array_merge($this->payloadToMultipart((array)$payload), $requestOptions['multipart']);
                $response = $this->post($endpoint, $requestOptions);
            } elseif (isset($requestOptions['json']) && is_array($requestOptions['json'])) {
                $requestOptions['json'] = array_merge($payload, $requestOptions['json']);
                $response = $this->post($endpoint, $requestOptions);
            } elseif (!isset($requestOptions['form_params']) && !isset($requestOptions['body']) && !isset($requestOptions['json'])) {
                $requestOptions['json'] = $payload;
                $response = $this->post($endpoint, $requestOptions);
            } else {
                $response = $this->post($endpoint, $requestOptions);
            }
        } else {
            $response = $this->post($endpoint, $payload);
        }

        if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
            $responseBody = $response->getBody()->getContents();
            $requestBody = $response->getRequest()->getBody();
            throw new ApiRequestException(
                "API request failed for {$endpoint}",
                $response->getStatusCode(),
                json_encode([
                    'endpoint' => $endpoint,
                    'request_body' => (string)$requestBody,
                    'response_body' => $responseBody
                ]),
                null
            );
        }
        $data = json_decode($response->getBody()->getContents(), true);
        return $data["data"] ?? null;
    }

    public function update($endpoint, $payload = [], array $requestOptions = [])
    {
        if (!empty($requestOptions)) {
            if (isset($requestOptions['multipart'])) {
                $requestOptions['multipart'] = array_merge($this->payloadToMultipart((array)$payload), $requestOptions['multipart']);
                $response = $this->put($endpoint, $requestOptions);
            } elseif (isset($requestOptions['json']) && is_array($requestOptions['json'])) {
                $requestOptions['json'] = array_merge($payload, $requestOptions['json']);
                $response = $this->put($endpoint, $requestOptions);
            } elseif (!isset($requestOptions['form_params']) && !isset($requestOptions['body']) && !isset($requestOptions['json'])) {
                $requestOptions['json'] = $payload;
                $response = $this->put($endpoint, $requestOptions);
            } else {
                $response = $this->put($endpoint, $requestOptions);
            }
        } else {
            $response = $this->put($endpoint, $payload);
        }

        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody()->getContents();
            $requestBody = $response->getRequest()->getBody();
            throw new ApiRequestException(
                "API request failed for {$endpoint}",
                $response->getStatusCode(),
                json_encode([
                    'endpoint' => $endpoint,
                    'request_body' => (string)$requestBody,
                    'response_body' => $responseBody
                ]),
                null
            );
        }
        $data = json_decode($response->getBody()->getContents(), true);
        return $data["data"] ?? null;
    }

    public function first($endpoint, ...$args)
    {
        return $this->getFirst($endpoint, ...$args);
    }

    public function getFirst($endpoint, ...$args)
    {
        return $this->__first("get", $endpoint, ...$args);
    }

    public function record($endpoint, ...$args)
    {
        return $this->getRecord($endpoint, ...$args);
    }

    public function getRecord($endpoint, ...$args)
    {
        $response = $this->get($endpoint, ...$args);
        if ($response->getStatusCode() !== 200) {
            $responseBody = $response->getBody()->getContents();
            $requestBody = $response->getRequest()->getBody();
            throw new ApiRequestException(
                "API request failed for {$endpoint}",
                $response->getStatusCode(),
                json_encode([
                    'endpoint' => $endpoint,
                    'request_body' => (string)$requestBody,
                    'response_body' => $responseBody
                ]),
                null
            );
        }
        $data = json_decode($response->getBody()->getContents(), true);
        return $data["data"] ?? null;
    }

    public function all($endpoint, ...$args)
    {
        return $this->getAll($endpoint, ...$args);
    }

    public function getAll($endpoint, ...$args)
    {
        return $this->__all("get", $endpoint, ...$args);
    }

    public function searchFirst($endpoint, ...$args)
    {
        return $this->__first("post", $endpoint . "/search", ...$args);
    }

    public function searchAll($endpoint, ...$args)
    {
        return $this->__all("post", $endpoint . "/search", ...$args);
    }

    public function search($endpoint, ...$args)
    {
        return $this->post($endpoint . "/search", ...$args);
    }

    public function throwErr($response)
    {
        $uri = $response->getRequest()->getUri();
        $requestBody = $response->getRequest()->getBody();
        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();

        throw new ApiRequestException(
            "API error for {$uri}",
            $statusCode,
            json_encode([
                'uri' => (string)$uri,
                'request_body' => (string)$requestBody,
                'response_body' => $responseBody
            ]),
            null
        );
    }
}
