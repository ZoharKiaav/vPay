<?php

namespace Paymenter\Extensions\Others\AbsoluteHostingDomains\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class AbsoluteHostingClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $username,
        private readonly string $apiKey,
    ) {}

    public function version(): mixed
    {
        return $this->get('/version');
    }

    public function credits(): mixed
    {
        return $this->get('/billing/credits');
    }

    public function tlds(): mixed
    {
        return $this->get('/tlds');
    }

    public function tldPricing(): mixed
    {
        return $this->get('/tlds/pricing');
    }

    public function lookup(
        string $searchTerm,
        array $tldsToInclude = [],
        bool $premiumEnabled = false,
        bool $isIdnDomain = false,
        ?string $punyCodeSearchTerm = null,
    ): mixed {
        return $this->post('/domains/lookup', [
            'searchTerm' => $searchTerm,
            'punyCodeSearchTerm' => $punyCodeSearchTerm ?? $searchTerm,
            'tldsToInclude' => $tldsToInclude,
            'isIdnDomain' => $isIdnDomain,
            'premiumEnabled' => $premiumEnabled,
        ]);
    }

    public function get(string $action, array $query = []): mixed
    {
        return $this->request('GET', $action, $query);
    }

    public function domainInformation(string $domain): mixed
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($domain === '') {
            throw new AbsoluteHostingException(
                'A domain is required to retrieve provider information.',
            );
        }

        return $this->get(
            '/domains/' . rawurlencode($domain) . '/information',
        );
    }

    public function registerDomain(array $payload): mixed
    {
        return $this->post('/order/domains/register', $payload);
    }

    public function transferDomain(array $payload): mixed
    {
        return $this->post('/order/domains/transfer', $payload);
    }

    public function post(string $action, array $form = []): mixed
    {
        return $this->request('POST', $action, $form);
    }

    public function token(?int $timestamp = null): string
    {
        $timestamp ??= time();

        $hour = gmdate('y-m-d H', $timestamp);
        $signingKey = $this->username . ':' . $hour;
        $hexadecimalHmac = hash_hmac('sha256', $this->apiKey, $signingKey);

        return base64_encode($hexadecimalHmac);
    }

    public function authHeaders(?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'username' => $this->username,
            'token' => $this->token($timestamp),
            'timestamp' => (string) $timestamp,
            'User-Agent' => 'vPay Absolute Hosting Domains',
            'Accept' => 'application/json',
        ];
    }

    private function request(string $method, string $action, array $data): mixed
    {
        $timestamp = time();
        $request = $this->pendingRequest($timestamp);
        $url = $this->url($action);

        try {
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                'POST' => $request->asForm()->post($url, $data),
                default => throw new AbsoluteHostingException(
                    "Unsupported Absolute Hosting HTTP method: {$method}",
                ),
            };
        } catch (ConnectionException $exception) {
            throw new AbsoluteHostingException(
                'Unable to connect to the Absolute Hosting API.',
                previous: $exception,
            );
        }

        return $this->decode($response);
    }

    private function pendingRequest(int $timestamp): PendingRequest
    {
        return Http::withHeaders($this->authHeaders($timestamp))
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function url(string $action): string
    {
        return rtrim($this->endpoint, '/') . '/' . ltrim($action, '/');
    }

    private function decode(Response $response): mixed
    {
        $data = null;

        try {
            $data = $response->json();
        } catch (JsonException) {
            // Normalised below as an invalid JSON response.
        }

        if (!$response->successful()) {
            $message = is_array($data)
                ? (string) ($data['error'] ?? $data['message'] ?? 'Absolute Hosting API request failed.')
                : 'Absolute Hosting API request failed.';

            throw new AbsoluteHostingException(
                message: $message,
                statusCode: $response->status(),
                responseData: $data,
            );
        }

        if ($data === null && trim($response->body()) !== 'null') {
            throw new AbsoluteHostingException(
                message: 'Absolute Hosting API returned invalid JSON.',
                statusCode: $response->status(),
                responseData: $response->body(),
            );
        }

        return $data;
    }
}
