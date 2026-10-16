<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of a failed_jobs row (OPS-04). Nothing calls save() on it:
 * retry and forget go through artisan queue:retry / queue:forget. The payload
 * may hold serialized models, so it is hidden and never rendered or logged.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property CarbonImmutable $failed_at
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $hidden = ['payload', 'exception'];

    /**
     * Class basename of the job, e.g. 'OpsDrillJob'.
     */
    public function jobName(): string
    {
        $decoded = json_decode($this->payload, true);
        $displayName = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return is_string($displayName) && $displayName !== ''
            ? class_basename($displayName)
            : 'Unknown job';
    }

    /**
     * First line of the exception, at most 200 characters.
     */
    public function exceptionSummary(): string
    {
        $firstLine = strtok($this->exception, "\r\n");

        return mb_substr($firstLine === false ? '' : $firstLine, 0, 200);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_at' => 'immutable_datetime',
        ];
    }
}
