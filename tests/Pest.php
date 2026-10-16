<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Tests run on PostgreSQL (phpunit.xml -> attleborough_testing), never SQLite.
| No test may make a real HTTP request.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
    })
    ->in('Unit');
