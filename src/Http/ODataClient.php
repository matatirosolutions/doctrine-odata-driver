<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Http;

use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class ODataClient
{
    private HttpClientInterface $http;
    private string $baseUrl;

    public function __construct(
        string         $host,
        private string $user,
        private string $password,
        string         $dbname,
        int            $port = 443,
        string         $urlPrefix = '/fmi/odata/v4',
        bool           $ssl = true,
    ) {
        $scheme = $ssl ? 'https' : 'http';
        $this->baseUrl = rtrim("$scheme://$host:$port$urlPrefix/$dbname", '/');

        $this->http = HttpClient::create([
            'timeout'      => 30,
            'verify_peer'  => $ssl,
            'verify_host'  => $ssl,
        ]);
    }

    /** @return array<string, mixed>
     * @throws ODataDriverException
     */
    public function get(string $entitySet, string $queryString = ''): array
    {
        $url = $this->entityUrl($entitySet) . $queryString;
        return $this->request('GET', $url);
    }

    /** @param array<string, mixed> $body
     * @throws ODataDriverException
     */
    public function post(string $entitySet, array $body): array
    {
        return $this->request('POST', $this->entityUrl($entitySet), $body);
    }

    /** @param array<string, mixed> $body
     * @throws ODataDriverException
     */
    public function patch(string $entitySet, string $filter, array $body): array
    {
        $url = $this->entityUrl($entitySet) . '?$filter=' . rawurlencode($filter);
        return $this->request('PATCH', $url, $body);
    }

    /**
     * @throws ODataDriverException
     */
    public function delete(string $entitySet, string $filter): void
    {
        $url = $this->entityUrl($entitySet) . '?$filter=' . rawurlencode($filter);
        $this->request('DELETE', $url);
    }

    public function getServerVersion(): string
    {
        return 'OData 4.0';
    }

    private function entityUrl(string $entitySet): string
    {
        return $this->baseUrl . '/' . ltrim($entitySet, '/');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws ODataDriverException
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $options = [
            'auth_basic' => [$this->user, $this->password],
            'headers'    => ['Accept' => 'application/json'],
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response   = $this->http->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $rawBody    = $statusCode !== 204 ? $response->getContent() : '';
        } catch (TransportExceptionInterface $e) {
            throw new ODataDriverException("OData $method failed: {$e->getMessage()}", 0, $e);
        } catch (HttpExceptionInterface $e) {
            try {
                $statusCode = $e->getResponse()->getStatusCode();
            } catch (TransportExceptionInterface) {
                $statusCode = 0;
            }
            throw new ODataDriverException(
                "OData $method returned HTTP $statusCode: {$e->getMessage()}",
                $statusCode,
                $e,
            );
        }

        if ($rawBody === '') {
            return [];
        }

        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            throw new ODataDriverException('OData response was not valid JSON.');
        }

        return $data;
    }
}
