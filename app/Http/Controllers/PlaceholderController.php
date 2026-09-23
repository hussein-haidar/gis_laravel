<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Response;

class PlaceholderController extends Controller
{
    /**
     * Gambar SVG placeholder deterministik untuk lokasi tanpa foto.
     * Warna mengikuti warna kategori + inisial nama lokasi.
     */
    public function show(Location $location): Response
    {
        $name = $location->name ?: 'Lokasi';
        $color = $location->category?->color ?: '#3b82f6';
        $initial = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}\s]/u', '', $name), 0, 1)) ?: '?';
        $short = mb_strimwidth($name, 0, 30, '…');
        $escName = e($short);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="260" viewBox="0 0 640 260">'
            . '<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0%" stop-color="#f8fafc"/>'
            . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0.28"/>'
            . '</linearGradient></defs>'
            . '<rect width="640" height="260" fill="url(#bg)"/>'
            . '<circle cx="320" cy="118" r="80" fill="' . $color . '" opacity="0.15"/>'
            . '<circle cx="320" cy="118" r="58" fill="' . $color . '" opacity="0.28"/>'
            . '<text x="320" y="152" font-family="Arial, sans-serif" font-size="76" font-weight="bold" fill="' . $color . '" text-anchor="middle">' . $initial . '</text>'
            . '<rect y="210" width="640" height="50" fill="' . $color . '" opacity="0.9"/>'
            . '<text x="320" y="244" font-family="Arial, sans-serif" font-size="22" font-weight="bold" fill="#ffffff" text-anchor="middle">' . $escName . '</text>'
            . '</svg>';

        return response($svg)
            ->header('Content-Type', 'image/svg+xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}