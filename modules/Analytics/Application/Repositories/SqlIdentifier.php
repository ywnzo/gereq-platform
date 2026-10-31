<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Repositories;

use Doctrine\DBAL\Connection;

/**
 * Quotes one identifier of the frozen analytics SQL contract for the platform in use.
 *
 * The analytics repositories write explicit SQL against the column list documented in
 * `modules/Analytics/README.md` (no ORM persistence for the hot paths), so identifiers are
 * interpolated into the statements. `analytics_sites.key` is a reserved word on MySQL/MariaDB,
 * which makes quoting mandatory rather than cosmetic.
 *
 * DBAL 4.4 offers `quoteSingleIdentifier()` for a bare column/table name; `quoteIdentifier()` is
 * deprecated in favour of it (and splits qualified names on `.`, which is not what a column name
 * needs here). The platform decides the quoting character: backticks on MySQL/MariaDB, double
 * quotes on SQLite/PostgreSQL.
 */
final class SqlIdentifier
{
    public static function quote(Connection $connection, string $identifier): string
    {
        return $connection->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }
}
