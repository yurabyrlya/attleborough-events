<?php

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\OccurrenceIcsController;
use Illuminate\Support\Facades\Route;

Route::get('/', CalendarController::class)->name('calendar');

// Public URL contract (costly to change): shared links and calendar files.
Route::get('/events/{slug}', EventController::class)
    ->where('slug', '[A-Za-z0-9-]{1,120}')
    ->name('events.show');

Route::get('/events/{slug}/{key}.ics', OccurrenceIcsController::class)
    ->where('slug', '[A-Za-z0-9-]{1,120}')
    ->where('key', '\d{4}-\d{2}-\d{2}')
    ->name('events.ics');
