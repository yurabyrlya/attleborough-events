<?php

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\OccurrenceIcsController;
use App\Http\Controllers\SubmissionController;
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

// Public URL contract (costly to change): organisers bookmark /submit (D-04).
Route::get('/submit', [SubmissionController::class, 'create'])->name('submit.create');
Route::post('/submit', [SubmissionController::class, 'store'])
    ->middleware('throttle:submissions')
    ->name('submit.store');
Route::get('/submit/thanks', [SubmissionController::class, 'thanks'])->name('submit.thanks');

// Public URL contract (costly to change): Phase 5 expands the notice at the same URL (D-04).
Route::view('/privacy', 'privacy')->name('privacy');
