# Doctrine OData Driver

A [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html) driver that translates SQL into [OData v4](https://www.odata.org/) HTTP requests, allowing OData backends to be used as first-class database connections in Doctrine-based applications.

The primary target backend is **FileMaker Server's OData API**, though the driver is designed to work with any OData v4 compliant endpoint.

## Requirements

- PHP 8.4+
- Doctrine DBAL 4.x
- Symfony HttpClient 7.x

## Installation

```bash
composer require matatirosoln/doctrine-odata-driver
```

> **Note:** This package also requires [`matatirosoln/sql-to-odata`](https://github.com/matatirosolutions/sql-to-odata), which is not yet published to Packagist. Add the following to your `composer.json` before installing:
>
> ```json
> "repositories": [
>     {
>         "type": "vcs",
>         "url": "git@github.com:matatirosolutions/sql-to-odata.git"
>     }
> ]
> ```

## Usage

### Connecting

Pass `ODataDriver::class` as the `driverClass` in your DBAL connection parameters:

```php
use Doctrine\DBAL\DriverManager;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver;

$connection = DriverManager::getConnection([
    'driverClass' => ODataDriver::class,
    'host'        => 'your-filemaker-server.example.com',
    'user'        => 'your-odata-username',
    'password'    => 'your-odata-password',
    'dbname'      => 'YourDatabase',
]);
```

#### Connection parameters

| Parameter    | Required | Default          | Description                                      |
|--------------|----------|------------------|--------------------------------------------------|
| `host`       | Yes      | —                | Hostname of the OData server                     |
| `user`       | Yes      | —                | Username for HTTP Basic auth                     |
| `password`   | Yes      | —                | Password for HTTP Basic auth                     |
| `dbname`     | Yes      | —                | Database name (appears in the OData URL path)    |
| `port`       | No       | `443`            | Server port                                      |
| `url_prefix` | No       | `/fmi/odata/v4`  | URL path prefix before the database name         |
| `ssl`        | No       | `true`           | Whether to use HTTPS and verify SSL certificates |

The base OData URL is constructed as `{scheme}://{host}:{port}/{url_prefix}/{dbname}`.

### Querying

Use standard DBAL query methods — SQL is translated to OData requests automatically.

```php
// Fetch all rows
$rows = $connection->fetchAllAssociative('SELECT * FROM Contact');

// Filter with a WHERE clause
$rows = $connection->fetchAllAssociative(
    'SELECT Name, City FROM Contact WHERE City = ?',
    ['Auckland'],
);

// Order and limit
$rows = $connection->fetchAllAssociative(
    'SELECT Name, City FROM Contact ORDER BY Name ASC LIMIT 10',
);
```

### Writing

Full CRUD is supported. INSERT, UPDATE, and DELETE are translated to OData POST, PATCH, and DELETE requests respectively.

```php
// INSERT
$connection->executeStatement(
    'INSERT INTO Contact (Name, City) VALUES (?, ?)',
    ['Jane', 'Christchurch'],
);

// UPDATE (WHERE clause is required)
$connection->executeStatement(
    'UPDATE Contact SET City = ? WHERE Name = ?',
    ['Wellington', 'Jane'],
);

// DELETE (WHERE clause is required)
$connection->executeStatement(
    'DELETE FROM Contact WHERE Name = ?',
    ['Jane'],
);
```

> **Note:** UPDATE and DELETE without a WHERE clause will throw an exception. This is enforced to prevent accidental bulk modifications.

## FileMaker-specific notes

### Table occurrences, not layouts

FileMaker OData exposes **table occurrences** from the relationship graph as entity sets — not layouts (which are used by the FileMaker Data API) and not raw base tables. The entity set name in your SQL must match the table occurrence name exactly.

### Primary keys are UUIDs

FileMaker generates UUID primary keys server-side. The `id` field is returned in `SELECT *` responses, but FileMaker does not currently support:

- Using `id` in a `$select` clause (e.g. `SELECT id, Name FROM Contact` will fail)
- Filtering by `id` using `$filter` (e.g. `WHERE id = '...'`)

### OData annotations are stripped

FileMaker includes `@id` and `@editLink` metadata in every response row. These are automatically stripped before rows are returned to your application.

### Server setup

To use FileMaker OData you must:

1. Enable OData in **FileMaker Server Admin Console → Connectors → OData**
2. Ensure the connecting account's privilege set has the **`fmodata` extended privilege** enabled

## Running tests

### Unit tests

```bash
./vendor/bin/phpunit
```

### Integration tests

Integration tests run against a real OData server. Copy the example config and fill in your server details:

```bash
cp phpunit.integration.xml.example phpunit.integration.xml
# edit phpunit.integration.xml with your server details
./vendor/bin/phpunit -c phpunit.integration.xml
```

The integration tests expect a `Contact` entity set with `Name` (text) and `City` (text) fields, and at least the following seed records:

| Name  | City         |
|-------|--------------|
| Alice | Auckland     |
| Bob   | Wellington   |
| Jane  | Christchurch |

## Licence

MIT
