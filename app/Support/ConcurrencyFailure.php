<?php

namespace App\Support;

use PDOException;
use Throwable;

/**
 * Whether an exception is PostgreSQL aborting a transaction because of
 * concurrent work: a deadlock (SQLSTATE 40P01) or a serialization failure
 * (40001). Review WR-08: these are "busy, try again" for the admin, never a
 * 500, and a bulk action skips that draft and carries on.
 *
 * Laravel throws a QueryException for them at the outermost transaction and
 * a DeadlockException (whose previous is the QueryException) inside a
 * nested one, so the whole chain is searched for a PDO error with that
 * SQLSTATE. QueryException extends PDOException.
 */
final class ConcurrencyFailure
{
    /** @var list<string> */
    private const array SQLSTATES = ['40P01', '40001'];

    public static function causedBy(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException) {
                continue;
            }

            $state = $current->errorInfo[0] ?? $current->getCode();

            if (in_array((string) $state, self::SQLSTATES, true)) {
                return true;
            }
        }

        return false;
    }
}
