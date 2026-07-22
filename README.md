# Doctrine OData Driver

A [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html) driver that translates SQL into [OData v4](https://www.odata.org/) HTTP requests, allowing OData backends to be used as first-class database connections in Doctrine-based applications.

The primary target backend is **FileMaker Server's OData API**, though the driver is designed to work with any OData v4 compliant endpoint.

## Requirements

- PHP 8.4+
- Doctrine DBAL 4.x
- Doctrine ORM 3.x
- Symfony HttpClient 7.x or 8.x

## Installation

```bash
composer require matatirosoln/doctrine-odata-driver
```

## Symfony configuration

Add the driver to your `doctrine.yaml`:

```yaml
doctrine:
    dbal:
        driver_class: Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver
        host: '%env(ODATA_HOST)%'
        dbname: '%env(ODATA_DATABASE)%'
        user: '%env(ODATA_USER)%'
        password: '%env(ODATA_PASSWORD)%'
        options:
            ssl: true
```

Add the corresponding variables to your `.env`:

```dotenv
ODATA_HOST=your-odata-server.example.com
ODATA_DATABASE=YourDatabaseName
ODATA_USER=your-username
ODATA_PASSWORD=your-password
```

### Connection options

The following keys are supported under `options:` in `doctrine.yaml` (or as top-level keys when using `DriverManager::getConnection()` directly):

| Option           | Default         | Description                                                                                                          |
|------------------|-----------------|----------------------------------------------------------------------------------------------------------------------|
| `ssl`            | `true`          | Use HTTPS and verify SSL certificates.                                                                               |
| `port`           | `443`           | Server port.                                                                                                         |
| `timeout`        | `30`            | Default HTTP request timeout in seconds. Binary methods (container upload/download) accept a per-call override.      |
| `url_prefix`     | `/fmi/odata/v4` | URL path prefix before the database name. Change for non-FileMaker servers.                                          |
| `quote_guids`    | `false`         | Keep UUID values as quoted strings in `$filter` expressions. Required for FileMaker; see below.                      |
| `metadata_ttl`   | `0`             | TTL in seconds for the PSR-16 metadata cache. `0` means no expiry. Only used when `metadata_cache` is also set.     |
| `metadata_cache` | `null`          | A `Psr\SimpleCache\CacheInterface` instance for caching `$metadata` across requests. Cannot be set via YAML; see below. |

The base OData URL is constructed as `{scheme}://{host}:{port}/{url_prefix}/{dbname}`.

## Metadata caching

The driver fetches the OData `$metadata` endpoint once per `ODataConnection` instance (i.e. once per web request in a typical Symfony app) and caches the result in memory for the duration of that connection. This means every web request makes exactly one `$metadata` HTTP call before the first entity query.

For long-running processes (queue workers, console commands) or high-traffic applications where even one `$metadata` call per request is too much, inject a PSR-16 `CacheInterface` to persist the parsed metadata across requests.

Because `doctrine.yaml` only supports scalar values, the cache instance cannot be set there directly. The recommended approach in Symfony is to install [`matatirosoln/doctrine-odata-bundle`](https://github.com/matatirosolutions/doctrine-odata-bundle), which wires the cache automatically via a DBAL middleware — no factory or manual wiring required.

If you need to wire the cache manually (e.g. outside Symfony, or without the bundle), pass the instance in the connection params:

```php
use Doctrine\DBAL\DriverManager;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver;

$connection = DriverManager::getConnection([
    'driverClass'    => ODataDriver::class,
    'host'           => 'your-server.example.com',
    'user'           => 'username',
    'password'       => 'password',
    'dbname'         => 'YourDatabase',
    'metadata_cache' => $psr16CacheInstance,
    'metadata_ttl'   => 3600,
]);
```

## Primary key detection

The driver automatically uses OData [key-path URLs](https://docs.oasis-open.org/odata/odata/v4.01/odata-v4.01-part2-url-conventions.html#sec_AddressingEntities) (`/EntitySet('key')`) for single-entity lookups — which is the correct OData v4 form — rather than `?$filter=pk eq value` (a collection filtered to one result).

To do this it needs to know which field is the primary key for each entity set. This is discovered automatically at runtime from the OData `$metadata` EDMX response. When the driver first connects, it fetches `/$metadata` and parses it using `EdmxParser` to extract each entity set's primary key field name. That parsed result is held in `ODataConnection::$parsedMetadata` and accessed by each statement as needed — **no additional configuration is required**.

For this to work, your entities must have an explicit table name and column name on the identifier:

```php
#[ORM\Entity]
#[ORM\Table(name: 'Contact')]          // must match the OData entity set name
class Contact
{
    #[ORM\Id]
    #[ORM\Column(name: '__pk_ContactID')]  // must match the OData field name
    public private(set) string $id { ... }
}
```

## Standard usage with Doctrine ORM

Once configured, use Doctrine ORM normally:

```php
// Find by primary key — generates /Contact('uuid')?$select=...
$contact = $repository->find('08EC1E80-89DB-4513-8E3D-9D33D6BA006C');

// Find by field — generates /Contact?$select=...&$filter=City eq 'Auckland'
$contacts = $repository->findBy(['city' => 'Auckland']);

// Persist a new entity
$contact = new Contact('new-uuid');
$contact->name = 'Jane';
$contact->city = 'Christchurch';
$entityManager->persist($contact);
$entityManager->flush(); // → POST /Contact

// Update
$contact->city = 'Wellington';
$entityManager->flush(); // → PATCH /Contact('uuid')

// Delete
$entityManager->remove($contact);
$entityManager->flush(); // → DELETE /Contact('uuid')
```

## DBAL-only usage

The driver can also be used directly via DBAL without the ORM:

```php
use Doctrine\DBAL\DriverManager;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver;

$connection = DriverManager::getConnection([
    'driverClass' => ODataDriver::class,
    'host'        => 'your-odata-server.example.com',
    'user'        => 'username',
    'password'    => 'password',
    'dbname'      => 'YourDatabase',
    'quote_guids' => true,   // if connecting to FileMaker
]);

$rows = $connection->fetchAllAssociative(
    "SELECT Name, City FROM Contact WHERE City = ?",
    ['Auckland'],
);
```

## FileMaker-specific notes

### `quote_guids: true`

By default the driver emits GUID values as bare `Edm.Guid` literals in `$filter` expressions, as required by the OData v4 specification:

```
$filter=pk eq 08EC1E80-89DB-4513-8E3D-9D33D6BA006C
```

FileMaker's OData parser cannot handle this — it interprets the leading hex segment as a number and fails. Setting `quote_guids: true` keeps GUIDs as quoted string literals:

```
$filter=pk eq '08EC1E80-89DB-4513-8E3D-9D33D6BA006C'
```

This option is only needed for FileMaker. Leave it unset (or `false`) for any OData v4 spec-compliant server.

> **Note:** Single-entity primary-key lookups use the key-path URL form (`/Contact('uuid')`) and are not affected by this option — `quote_guids` only applies to GUIDs that appear in compound `$filter` expressions.

### Table occurrences, not layouts

FileMaker OData exposes **table occurrences** from the relationship graph as entity sets — not layouts (which are used by the FileMaker Data API) and not raw base tables. The entity set name in your SQL and your `#[ORM\Table(name: '...')]` attribute must match the table occurrence name exactly.

### OData annotations are stripped

FileMaker includes `@odata.id`, `@odata.editLink`, and similar metadata annotations in every response. These are automatically stripped before rows are returned to your application.

### Server setup

To use FileMaker OData you must:

1. Enable OData in **FileMaker Server Admin Console → Connectors → OData**
2. Ensure the connecting account's privilege set has the **`fmodata` extended privilege** enabled

## How SQL is translated to OData

| SQL construct                          | OData equivalent                                      |
|----------------------------------------|-------------------------------------------------------|
| `SELECT *`                             | No `$select` (all fields returned)                    |
| `SELECT a, b`                          | `?$select=a,b`                                        |
| `WHERE field = value`                  | `?$filter=field eq value`                             |
| `WHERE pk = 'uuid'` (single equality)  | `/EntitySet('uuid')` key-path (no `$filter`)          |
| `ORDER BY col ASC`                     | `?$orderby=col asc`                                   |
| `LIMIT n`                              | `?$top=n`                                             |
| `LIMIT n OFFSET m`                     | `?$top=n&$skip=m`                                     |
| `COUNT(*)`                             | `/$count`                                             |
| `INSERT INTO …`                        | `POST /EntitySet`                                     |
| `UPDATE … WHERE pk = 'uuid'`           | `PATCH /EntitySet('uuid')`                            |
| `DELETE … WHERE pk = 'uuid'`           | `DELETE /EntitySet('uuid')`                           |

SQL `UPDATE` and `DELETE` without a `WHERE` clause will throw an exception to prevent accidental bulk modifications.

## Known limitations

- **Joins** are not supported — OData has no equivalent of SQL JOIN. Model relationships using Doctrine associations rather than raw join queries.
- **Transactions** are accepted as no-ops. OData operations are auto-committed per HTTP request; if multiple changes are flushed together and one fails, earlier changes cannot be rolled back.
- **Subqueries** in `FROM`, `SELECT`, or `WHERE` are not supported.
- **`SELECT DISTINCT`** is not supported.

## Running tests

```bash
./vendor/bin/phpunit
```

## Licence

MIT

## Contact

Steve Winter — Matatiro Solutions Ltd — [steve@msdev.nz](mailto:steve@msdev.nz)
