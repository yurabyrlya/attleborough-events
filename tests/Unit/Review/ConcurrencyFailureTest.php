<?php

use App\Support\ConcurrencyFailure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;

/*
 * Review WR-08: deadlocks (40P01) and serialization failures (40001) are
 * "busy, try again"; every other database error is a real fault.
 */

function concurrencyQueryException(string $sqlState): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$sqlState}]: test");
    $pdo->errorInfo = [$sqlState, 7, 'ERROR:  test'];

    return new QueryException('pgsql', 'select 1', [], $pdo);
}

it('recognises a deadlock and a serialization failure, at the top level and nested', function (string $sqlState) {
    $query = concurrencyQueryException($sqlState);

    expect(ConcurrencyFailure::causedBy($query))->toBeTrue()
        // Inside a nested transaction Laravel rethrows it as a DeadlockException.
        ->and(ConcurrencyFailure::causedBy(new DeadlockException($query->getMessage(), 0, $query)))->toBeTrue();
})->with(['deadlock' => '40P01', 'serialization failure' => '40001']);

it('does not treat other database errors or plain exceptions as concurrency conflicts', function () {
    expect(ConcurrencyFailure::causedBy(concurrencyQueryException('23505')))->toBeFalse()
        ->and(ConcurrencyFailure::causedBy(new RuntimeException('deadlock detected')))->toBeFalse()
        ->and(ConcurrencyFailure::causedBy(new InvalidArgumentException('40P01')))->toBeFalse();
});
