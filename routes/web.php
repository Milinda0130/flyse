<?php

use Illuminate\Support\Facades\Route;

// The React app (resources/js) handles its own routing; every non-API URL loads it.
Route::view('/{any?}', 'app')->where('any', '^(?!api/|up$).*$');
