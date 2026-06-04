<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Http;

use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Matatirosoln\DoctrineOdataDriver\Metadata\EdmxParser;
use Matatirosoln\SqlToOdata\Support\KeyValue;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class ODataClient
{
    private HttpClientInterface $http;
    private string $baseUrl;

    /**
     * @param string              $host
     * @param string              $user
     * @param string              $password
     * @param string              $dbname
     * @param int                 $port
     * @param string              $urlPrefix
     * @param bool                $ssl
     * @param CacheInterface|null $metadataCache
     *        PSR-16 cache for persisting $metadata across requests. When null
     *        (default) metadata is only cached for the lifetime of the current
     *        ODataConnection instance (i.e. the current request in a web app).
     * @param int                 $metadataTtl
     *        TTL in seconds for the PSR-16 cache entry. 0 means no expiry.
     *        Only used when $metadataCache is provided.
     */
    public function __construct(
        string                  $host,
        private string          $user,
        private string          $password,
        string                  $dbname,
        int                     $port = 443,
        string                  $urlPrefix = '/fmi/odata/v4',
        bool                    $ssl = true,
        private ?CacheInterface $metadataCache = null,
        private int             $metadataTtl = 0,
    ) {
        $scheme        = $ssl ? 'https' : 'http';
        $this->baseUrl = rtrim("$scheme://$host:$port$urlPrefix/$dbname", '/');

        $this->http = HttpClient::create([
            'timeout'     => 30,
            'verify_peer' => $ssl,
            'verify_host' => $ssl,
        ]);
    }

    /** @return array<string, mixed>
     * @throws ODataDriverException
     */
    public function get(string $entitySet, string $queryString = '', ?KeyValue $keyValue = null): array
    {
        // Key-path form: /EntitySet('uuid')?$select=... or /EntitySet(42)?$select=...
        // Collection form: /EntitySet?$select=...&$filter=...
        $url = $this->entityUrl($entitySet)
            . ($keyValue !== null ? $keyValue->toUrlSegment() : '')
            . $this->encodeQueryString($queryString);

        $data = $this->request('GET', $url);

        // Key-path responses return the entity directly without a 'value' wrapper.
        // Normalise to the standard {"value": [...]} envelope so ODataResult can
        // handle both collection and single-entity responses uniformly.
        if ($keyValue !== null && !array_key_exists('value', $data) && $data !== []) {
            $data = ['value' => [$data]];
        }

        return $data;
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
    public function patch(string $entitySet, array $body, ?KeyValue $keyValue = null, string $filter = ''): array
    {
        $url = $keyValue !== null
            ? $this->entityUrl($entitySet) . $keyValue->toUrlSegment()
            : $this->entityUrl($entitySet) . '?$filter=' . $this->encodeODataValue($filter);

        return $this->request('PATCH', $url, $body);
    }

    /**
     * @throws ODataDriverException
     */
    public function delete(string $entitySet, ?KeyValue $keyValue = null, string $filter = ''): void
    {
        $url = $keyValue !== null
            ? $this->entityUrl($entitySet) . $keyValue->toUrlSegment()
            : $this->entityUrl($entitySet) . '?$filter=' . $this->encodeODataValue($filter);

        $this->request('DELETE', $url);
    }

    public function getServerVersion(): string
    {
        return 'OData 4.0';
    }

    /**
     * Fetches and parses the OData $metadata endpoint (EDMX XML).
     *
     * XML is used rather than CSDL JSON for maximum compatibility: XML is the
     * format defined by OData v4.0 and all compliant servers support it. The
     * JSON format was added in OData v4.01.
     *
     * When a PSR-16 cache was provided at construction, the parsed result is
     * stored with the configured TTL and returned from cache on subsequent calls.
     * The cache key includes the full base URL so different OData endpoints
     * never share cached metadata.
     *
     * @return array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>}>
     * @throws ODataDriverException
     */
    public function fetchMetadata(): array
    {
        $cacheKey = 'odata_metadata_' . md5($this->baseUrl);

        if ($this->metadataCache !== null) {
            try {
                $cached = $this->metadataCache->get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
            } catch (\Throwable) {
                // Cache read failure is non-fatal — fall through to a fresh fetch
            }
        }

        $url = $this->baseUrl . '/$metadata';

        try {
            $response = $this->http->request('GET', $url, [
                'auth_basic' => [$this->user, $this->password],
                'headers'    => ['Accept' => 'application/xml'],
            ]);
            $xml = $response->getContent();
        } catch (TransportExceptionInterface $e) {
            throw new ODataDriverException("Failed to fetch OData \$metadata: {$e->getMessage()}", 0, $e);
        } catch (HttpExceptionInterface $e) {
            throw new ODataDriverException(
                "OData \$metadata returned HTTP " . $e->getResponse()->getStatusCode(),
                $e->getResponse()->getStatusCode(),
                $e,
            );
        }

        $metadata = (new EdmxParser())->parse($xml);

        if ($this->metadataCache !== null) {
            try {
                $ttl = $this->metadataTtl > 0 ? $this->metadataTtl : null;
                $this->metadataCache->set($cacheKey, $metadata, $ttl);
            } catch (\Throwable) {
                // Cache write failure is non-fatal
            }
        }

        return $metadata;
    }

    /**
     * Encodes characters in an OData query parameter value that must be
     * percent-encoded in a URL, while preserving OData structural characters
     * that are valid sub-delimiters in RFC 3986 query strings (,  ;  (  )  *).
     */
    private function encodeODataValue(string $value): string
    {
        return strtr($value, [
            ' '  => '%20',
            "'"  => '%27',
            '#'  => '%23',
            '['  => '%5B',
            ']'  => '%5D',
            '"'  => '%22',
        ]);
    }

    private function entityUrl(string $entitySet): string
    {
        return $this->baseUrl . '/' . ltrim($entitySet, '/');
    }

    /**
     * Properly encodes an OData query string produced by SelectParser.
     *
     * SelectParser emits values with raw spaces and quotes, e.g.:
     *   ?$select=Name,City&$filter=Name eq 'Alice'&$top=1
     *   /$count?$filter=Status eq 'Active'
     *
     * We must encode each parameter value individually while leaving the OData
     * parameter names ($select, $filter, …) and structural characters (?, &, =)
     * untouched.
     */
    private function encodeQueryString(string $queryString): string
    {
        if ($queryString === '' || $queryString === '/$count') {
            return $queryString;
        }

        // Separate a leading path segment (e.g. "/$count") from the query part.
        $path  = '';
        $query = $queryString;

        if (str_starts_with($queryString, '/')) {
            $qPos  = strpos($queryString, '?');
            $path  = $qPos !== false ? substr($queryString, 0, $qPos) : $queryString;
            $query = $qPos !== false ? substr($queryString, $qPos) : '';
        }

        if ($query === '' || $query === '?') {
            return $path . $query;
        }

        // Strip the leading '?' then split on '&' to get individual params.
        $raw    = ltrim($query, '?');
        $params = [];

        foreach (explode('&', $raw) as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                $params[] = $pair;
                continue;
            }

            $name  = substr($pair, 0, $eqPos);          // e.g. "$filter"
            $value = substr($pair, $eqPos + 1);          // e.g. "Name eq 'Alice'"

            // Encode only characters that must be encoded in a query string value.
            // We intentionally leave OData structural characters intact:
            //   , ; ( )  — used in $select, $filter, $apply, $expand values
            //   *        — wildcard in $select
            // We DO encode:
            //   space    — invalid in URLs; must be %20
            //   '        — FileMaker's OData parser requires single quotes encoded as %27
            $params[] = $name . '=' . $this->encodeODataValue($value);
        }

        return $path . '?' . implode('&', $params);
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
            $response = $e->getResponse();
            try {
                $statusCode = $response->getStatusCode();
            } catch (TransportExceptionInterface) {
                $statusCode = 0;
            }
            try {
                $responseBody = $response->getContent(false);
                $decoded      = json_decode($responseBody, true);
                $detail       = is_array($decoded)
                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    : $responseBody;
            } catch (\Throwable) {
                $detail = '(could not read response body)';
            }
            throw new ODataDriverException(
                "OData $method returned HTTP $statusCode for URL: $url\nResponse: $detail",
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
