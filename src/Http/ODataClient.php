<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;

class ODataClient
{
    private readonly Client $http;
    private readonly string $baseUrl;

    public function __construct(
        string $host,
        private readonly string $user,
        private readonly string $password,
        string $dbname,
        int $port = 443,
        string $urlPrefix = '/fmi/odata/v4',
        bool $ssl = true,
    ) {
        $scheme = $ssl ? 'https' : 'http';
        $this->baseUrl = rtrim("{$scheme}://{$host}:{$port}{$urlPrefix}/{$dbname}", '/');

        $this->http = new Client([
            'timeout'  => 30,
            'verify'   => $ssl,
        ]);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return array<string, mixed> */
    public function get(string $entitySet, string $queryString = ''): array
    {
        $url = $this->baseUrl . '/' . ltrim($entitySet, '/');
        if ($queryString !== '') {
            $url .= $queryString;
        }

        try {
            $response = $this->http->get($url, [
                'auth'    => [$this->user, $this->password],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw new ODataDriverException("OData GET failed: {$e->getMessage()}", 0, $e);
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new ODataDriverException('OData response was not valid JSON.');
        }

        return $data;
    }

    public function getServerVersion(): string
    {
        return 'OData 4.0';
    }
}
