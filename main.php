#!/usr/bin/env php
<?php
declare(strict_types=1);

final class ApiException extends RuntimeException {}

final class ApiClient
{
    private string $baseUrl;
    private float $timeout;
    private int $retries;
    private array $headers;
    private bool $insecure;

    public function __construct(string $baseUrl, float $timeout, int $retries, array $headers, bool $insecure = false)
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            throw new ApiException('Base URL must be a valid HTTP or HTTPS URL');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            throw new ApiException('Base URL must not contain credentials, query or fragment');
        }
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->timeout = $timeout;
        $this->retries = $retries;
        $this->headers = $headers;
        $this->insecure = $insecure;
    }

    private function url(string $endpoint): string
    {
        if (preg_match('~^https?://~i', $endpoint)) {
            $url = $endpoint;
        } else {
            if (str_starts_with($endpoint, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $endpoint)) {
                throw new ApiException('Unsupported endpoint URL');
            }
            $url = $this->baseUrl . ltrim($endpoint, '/');
        }
        $parts = parse_url($url);
        $base = parse_url($this->baseUrl);
        if ($parts === false || $base === false || strtolower($parts['scheme'] ?? '') !== strtolower($base['scheme'] ?? '') || strtolower($parts['host'] ?? '') !== strtolower($base['host'] ?? '') || ($parts['port'] ?? null) !== ($base['port'] ?? null) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ApiException('Endpoint must use the configured API origin');
        }
        $path = $parts['path'] ?? '/';
        $basePath = parse_url($this->baseUrl, PHP_URL_PATH) ?: '/';
        if (!str_starts_with($path, $basePath) || preg_match('~(?:^|/)(?:\.{1,2}|%2e(?:%2e)?)(?:/|$)~i', $path)) {
            throw new ApiException('Endpoint must remain under the configured API base path');
        }
        return $url;
    }

    public function request(string $method, string $endpoint, ?array $data = null, array $params = []): array
    {
        $url = $this->url($endpoint);
        if ($params !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        $maxAttempts = in_array($method, ['GET', 'HEAD'], true) ? $this->retries + 1 : 1;
        $start = microtime(true);
        $last = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $responseHeaders = [];
            $ch = curl_init();
            if ($ch === false) {
                throw new ApiException('Cannot initialize cURL');
            }
            $headers = $this->headers;
            $headers['Accept'] ??= 'application/json';
            $headers['User-Agent'] ??= 'api-intergration-php/1.0';
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($this->timeout, 10.0) * 1000),
                CURLOPT_TIMEOUT_MS => (int) ceil($this->timeout * 1000),
                CURLOPT_SSL_VERIFYPEER => !$this->insecure,
                CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    if (str_starts_with($line, 'HTTP/')) {
                        $responseHeaders = [];
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return $length;
                },
            ];
            if ($method === 'HEAD') {
                $options[CURLOPT_NOBODY] = true;
            }
            if ($data !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers['Content-Type'] = 'application/json';
            }
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            $options[CURLOPT_HTTPHEADER] = $headerLines;
            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $duration = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_close($ch);
            $retryable = $body === false || in_array($status, [429, 500, 502, 503, 504], true);
            $last = [
                'method' => $method,
                'url' => $url,
                'status_code' => $status,
                'success' => $body !== false && $status >= 200 && $status < 300,
                'attempt' => $attempt,
                'elapsed_seconds' => round(microtime(true) - $start, 4),
                'request_seconds' => round($duration, 4),
                'content_type' => $responseHeaders['content-type'] ?? null,
                'rate_limit' => [
                    'limit' => $responseHeaders['x-ratelimit-limit'] ?? null,
                    'remaining' => $responseHeaders['x-ratelimit-remaining'] ?? null,
                    'reset' => $responseHeaders['x-ratelimit-reset'] ?? null,
                    'retry_after' => $responseHeaders['retry-after'] ?? null,
                ],
                'data' => null,
            ];
            if ($body !== false && $body !== '') {
                try {
                    $last['data'] = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $last['data'] = $body;
                }
            }
            if (!$last['success']) {
                $last['error'] = $body === false ? "Transport error: $error" : "HTTP $status";
            }
            if (!$retryable || $attempt === $maxAttempts) {
                break;
            }
            $retryAfter = $responseHeaders['retry-after'] ?? null;
            $wait = min(8.0, 0.5 * (2 ** ($attempt - 1)));
            if (is_string($retryAfter)) {
                if (ctype_digit($retryAfter)) {
                    $wait = min(30.0, (float) $retryAfter);
                } elseif (($when = strtotime($retryAfter)) !== false) {
                    $wait = min(30.0, max(0.0, $when - time()));
                }
            }
            usleep((int) ($wait * 1000000));
        }
        return $last;
    }

    public function paginate(string $endpoint, array $params, int $pages, int $pageSize, string $pageParam, string $sizeParam, float $delay): array
    {
        $items = [];
        $history = [];
        for ($page = 1; $page <= $pages; $page++) {
            $query = $params;
            $query[$pageParam] = $page;
            $query[$sizeParam] = $pageSize;
            $result = $this->request('GET', $endpoint, null, $query);
            $history[] = ['page' => $page, 'status_code' => $result['status_code'], 'success' => $result['success'], 'attempts' => $result['attempt']];
            if (!$result['success']) {
                return ['success' => false, 'pages_completed' => $page - 1, 'items_collected' => count($items), 'history' => $history, 'data' => $items, 'error' => $result['error']];
            }
            $payload = $result['data'];
            if (!is_array($payload) || !array_is_list($payload)) {
                return ['success' => false, 'pages_completed' => $page - 1, 'history' => $history, 'data' => $items, 'error' => 'Paginated response must be a JSON array'];
            }
            array_push($items, ...$payload);
            if (count($payload) < $pageSize) {
                break;
            }
            if ($delay > 0 && $page < $pages) {
                usleep((int) ($delay * 1000000));
            }
        }
        return ['success' => true, 'pages_requested' => $pages, 'pages_completed' => count($history), 'items_collected' => count($items), 'history' => $history, 'data' => $items];
    }
}

function usage(): string
{
    return <<<'HELP'
Usage: php main.php METHOD ENDPOINT [options]

Methods: get, post, put, patch, delete, head

Options:
  --base-url URL           API base URL
  --data JSON              JSON object or array request body
  --params JSON            JSON object of query parameters
  --token TOKEN            Bearer token (or API_TOKEN environment variable)
  --api-key KEY            API key (or API_KEY environment variable)
  --api-key-header NAME    API key header, default X-API-Key
  --header NAME:VALUE      Custom header; repeatable
  --timeout SECONDS        Request timeout, default 10
  --retries NUMBER         Retries for GET and HEAD, default 3
  --pages NUMBER           Maximum pages for GET, default 1
  --page-size NUMBER       Items per page, default 10
  --page-param NAME        Page query key, default _page
  --size-param NAME        Size query key, default _limit
  --delay SECONDS          Delay between pages, default 0
  --output FILE            Save JSON result atomically
  --compact                Print compact JSON
  --text                   Print human-readable summary
  --help                   Show this help

Examples:
  php main.php get posts/1
  php main.php get posts --pages 3 --page-size 5
  php main.php get posts --params '{"userId":2}'
  php main.php post posts --data '{"title":"Test","body":"Hello","userId":1}'
  php main.php patch posts/1 --data '{"title":"Updated"}'
  php main.php delete posts/1
HELP;
}

function options(array $argv): array
{
    $defaults = [
        'base-url' => 'https://jsonplaceholder.typicode.com',
        'data' => null,
        'params' => '{}',
        'token' => getenv('API_TOKEN') ?: null,
        'api-key' => getenv('API_KEY') ?: null,
        'api-key-header' => 'X-API-Key',
        'header' => [],
        'timeout' => '10',
        'retries' => '3',
        'pages' => '1',
        'page-size' => '10',
        'page-param' => '_page',
        'size-param' => '_limit',
        'delay' => '0',
        'output' => null,
        'compact' => false,
        'text' => false,
        'help' => false,
    ];
    $positional = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--') {
            array_push($positional, ...array_slice($argv, $i + 1));
            break;
        }
        if (!str_starts_with($arg, '--')) {
            $positional[] = $arg;
            continue;
        }
        $split = explode('=', substr($arg, 2), 2);
        $name = $split[0];
        if (!array_key_exists($name, $defaults)) {
            throw new ApiException("Unknown option --$name");
        }
        if (in_array($name, ['help', 'compact', 'text'], true)) {
            if (count($split) > 1) {
                throw new ApiException("--$name does not accept a value");
            }
            $defaults[$name] = true;
            continue;
        }
        $value = $split[1] ?? null;
        if ($value === null) {
            $value = $argv[++$i] ?? null;
        }
        if ($value === null) {
            throw new ApiException("Missing value for --$name");
        }
        if ($name === 'header') {
            $defaults['header'][] = $value;
        } else {
            $defaults[$name] = $value;
        }
    }
    $defaults['positional'] = $positional;
    return $defaults;
}

function jsonObject(string $input, string $name): array
{
    try {
        $value = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ApiException("Invalid $name JSON: " . $e->getMessage());
    }
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        throw new ApiException("$name must be a JSON object");
    }
    return $value;
}

function numericOption(mixed $value, string $name, bool $integer, float $minimum): int|float
{
    if (!is_scalar($value) || !is_numeric((string) $value)) {
        throw new ApiException("--$name must be numeric");
    }
    $number = (float) $value;
    if (!is_finite($number) || $number < $minimum || ($integer && floor($number) !== $number)) {
        throw new ApiException("Invalid value for --$name");
    }
    return $integer ? (int) $number : $number;
}

function headerName(string $name): bool
{
    return preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) === 1;
}

function saveJson(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new ApiException('Output directory must exist and be writable');
    }
    $temporary = tempnam($directory, '.api-');
    if ($temporary === false) {
        throw new ApiException('Cannot create temporary output file');
    }
    try {
        if (file_put_contents($temporary, $contents . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
            throw new ApiException('Cannot save output file');
        }
    } finally {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}

function main(array $argv): int
{
    $args = options($argv);
    if ($args['help']) {
        echo usage(), PHP_EOL;
        return 0;
    }
    if (!extension_loaded('curl')) {
        throw new ApiException('PHP cURL extension is required');
    }
    if (count($args['positional']) !== 2) {
        throw new ApiException('Expected METHOD and ENDPOINT; use --help');
    }
    [$method, $endpoint] = $args['positional'];
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
        throw new ApiException("Unsupported HTTP method: $method");
    }
    $timeout = numericOption($args['timeout'], 'timeout', false, 0.001);
    $retries = numericOption($args['retries'], 'retries', true, 0);
    $pages = numericOption($args['pages'], 'pages', true, 1);
    $pageSize = numericOption($args['page-size'], 'page-size', true, 1);
    $delay = numericOption($args['delay'], 'delay', false, 0);
    if ($pages > 1 && $method !== 'GET') {
        throw new ApiException('Pagination requires GET');
    }
    if (!headerName($args['api-key-header']) || !headerName($args['page-param']) || !headerName($args['size-param'])) {
        throw new ApiException('Invalid API key or pagination parameter name');
    }
    $params = jsonObject($args['params'], 'params');
    $data = null;
    if ($args['data'] !== null) {
        try {
            $data = json_decode($args['data'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Invalid data JSON: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new ApiException('Request data must be a JSON object or array');
        }
    }
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $data === null) {
        throw new ApiException("$method requires --data");
    }
    $headers = [];
    foreach ($args['header'] as $header) {
        if (!str_contains($header, ':')) {
            throw new ApiException('Custom headers must use NAME:VALUE');
        }
        [$name, $value] = explode(':', $header, 2);
        $name = trim($name);
        $value = trim($value);
        if (!headerName($name) || strpbrk($value, "\r\n") !== false) {
            throw new ApiException('Invalid custom header');
        }
        $headers[$name] = $value;
    }
    if ($args['token'] !== null) {
        if (strpbrk($args['token'], "\r\n") !== false) {
            throw new ApiException('Invalid bearer token');
        }
        $headers['Authorization'] = 'Bearer ' . $args['token'];
    }
    if ($args['api-key'] !== null) {
        if (strpbrk($args['api-key'], "\r\n") !== false) {
            throw new ApiException('Invalid API key');
        }
        $headers[$args['api-key-header']] = $args['api-key'];
    }
    $client = new ApiClient($args['base-url'], $timeout, $retries, $headers);
    $result = $pages > 1
        ? $client->paginate($endpoint, $params, $pages, $pageSize, $args['page-param'], $args['size-param'], $delay)
        : $client->request($method, $endpoint, $data, $params);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    if (!$args['compact']) {
        $flags |= JSON_PRETTY_PRINT;
    }
    $encoded = json_encode($result, $flags);
    if ($args['output'] !== null) {
        saveJson($args['output'], $encoded);
    }
    if ($args['text']) {
        echo 'Success: ', $result['success'] ? 'yes' : 'no', PHP_EOL;
        echo 'Status: ', $result['status_code'] ?? 'n/a', PHP_EOL;
        echo 'Items: ', $result['items_collected'] ?? (is_array($result['data'] ?? null) ? count($result['data']) : 'n/a'), PHP_EOL;
        if (isset($result['error'])) {
            echo 'Error: ', $result['error'], PHP_EOL;
        }
    } else {
        echo $encoded, PHP_EOL;
    }
    return $result['success'] ? 0 : 1;
}

try {
    exit(main($argv));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
