<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['id', 'en'], true), 404);

        $request->session()->put('locale', $locale);

        return redirect()->back()->cookie('locale', $locale, 60 * 24 * 30);
    }
}