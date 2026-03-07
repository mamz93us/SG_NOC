<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\PhonebookController;

/*
|--------------------------------------------------------------------------
| Public Route (XML for Phones)
|--------------------------------------------------------------------------
*/


Route::get('/phonebook.xml', [PhonebookController::class, 'generate'])
    ->withoutMiddleware(['web'])
    ->name('phonebook.xml');


/*
|--------------------------------------------------------------------------
| Admin Routes (Protected by auth)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {

    // Dashboard
    Route::get('/', function () {
        return view('admin.dashboard');
    })->name('dashboard');

    // Branches
    Route::resource('branches', BranchController::class)
        ->except(['show']);

    // Contacts
    Route::resource('contacts', ContactController::class)
        ->except(['show']);

    // XML preview
    Route::get('/xml-preview', [PhonebookController::class, 'preview'])
        ->name('xml.preview');
});

/*
|--------------------------------------------------------------------------
| Auth Routes (Laravel Breeze)
|--------------------------------------------------------------------------
*/

// Disable registration
Route::any('/register', function () {
    abort(404);
});

require __DIR__.'/auth.php';
