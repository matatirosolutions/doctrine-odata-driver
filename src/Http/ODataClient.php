<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Http;

use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ODataClient
{
    private readonly HttpClientInterface $http;
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

        $this->http = HttpClient::create([
            'timeout'      => 30,
            'verify_peer'  => $ssl,
            'verify_host'  => $ssl,
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
            $response = $this->http->request('GET', $url, [
                'auth_basic' => [$this->user, $this->password],
                'headers'    => ['Accept' => 'application/json'],
            ]);

            $body = $response->getContent();
        } catch (TransportExceptionInterface $e) {
            throw new ODataDriverException("OData GET failed: {$e->getMessage()}", 0, $e);
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            throw new ODataDriverException(
                "OData GET returned HTTP {$e->getResponse()->getStatusCode()}: {$e->getMessage()}",
                $e->getResponse()->getStatusCode(),
                $e,
            );
        }

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
