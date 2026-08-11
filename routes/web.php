<?php

use App\Livewire\DdevProjectList;
use Illuminate\Support\Facades\Route;

// The only surface this app has: the menubar popup window.
Route::get('/', DdevProjectList::class)->name('projects');
