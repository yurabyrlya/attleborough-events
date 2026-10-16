<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RED scaffold.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    public function jobName(): string
    {
        return '';
    }

    public function exceptionSummary(): string
    {
        return '';
    }
}
