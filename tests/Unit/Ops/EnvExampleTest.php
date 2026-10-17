<?php

/*
 * Verification gap (01-08 truth 16, T-01-71): Laravel Boost's browser
 * logger injects a script element into every locally served page, which
 * breaks the CAL-06 "no script on /" check. A fresh copy of .env.example
 * must keep it off.
 */
it('turns the Laravel Boost browser logger off in .env.example', function () {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example')));

    expect($lines)->toContain('BOOST_BROWSER_LOGS_WATCHER=false');
});
