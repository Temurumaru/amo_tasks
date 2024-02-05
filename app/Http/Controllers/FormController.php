<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FormController extends Controller
{
    public function index(): \Illuminate\Contracts\View\View
    {
        return view('home');
    }
}
