<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Response;

class PlaceholderController extends Controller
{
    /**
     * Gambar SVG placeholder deterministik untuk lokasi tanpa foto.
     * Ikon SVG berbeda per tipe kategori agar dinamis sesuai kategori.
     */
    public function show(Location $location): Response
    {
        $name = $location->name ?: 'Lokasi';
        $color = $location->category?->color ?: '#3b82f6';
        $categoryName = $location->category?->name ?: 'Umum';
        $initial = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}\s]/u', '', $name), 0, 1)) ?: '?';
        $short = mb_strimwidth($name, 0, 30, '…');
        $escName = e($short);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="260" viewBox="0 0 640 260">'
            . '<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0%" stop-color="#f8fafc"/>'
            . '<stop offset="100%" stop-color="' . $color . '" stop-opacity="0.28"/>'
            . '</linearGradient></defs>'
            . '<rect width="640" height="260" fill="url(#bg)"/>'
            . '<circle cx="320" cy="108" r="82" fill="' . $color . '" opacity="0.12"/>'
            . $iconPath
            . '<circle cx="320" cy="108" r="30" fill="' . $color . '" opacity="0.3"/>'
            . '<text x="320" y="112" font-family="Arial,sans-serif" font-size="28" font-weight="bold" fill="' . $color . '" text-anchor="middle" dominant-baseline="central">' . $initial . '</text>'
            . '<rect y="222" width="640" height="38" fill="' . $color . '" opacity="0.9"/>'
            . '<text x="320" y="246" font-family="Arial,sans-serif" font-size="17" font-weight="bold" fill="#ffffff" text-anchor="middle">' . $escName . '</text>'
            . '</svg>';

        return response($svg)
            ->header('Content-Type', 'image/svg+xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Kembalikan path SVG ikon berdasarkan tipe kategori.
     */
    private function categoryIconPath(string $categoryName): string
    {
        $low = strtolower($categoryName);
        $cx = 320; $cy = 108;

        if (str_contains($low, 'al') || str_contains($low, 'gunung') || str_contains($low, 'danau')
            || str_contains($low, 'air terjun') || str_contains($low, 'hutan') || str_contains($low, 'alam')) {
            return '<path d="M' . ($cx-28) . ',' . ($cy+16) . ' L' . ($cx-16) . ',' . ($cy-20) . ' L' . ($cx-4) . ',' . ($cy+16) . ' L' . ($cx+8) . ',' . ($cy-20) . ' L' . ($cx+20) . ',' . ($cy+16) . '" fill="none" stroke="' . '#ffffff" stroke-width="4" opacity="0.85"/>'
                . '<path d="M' . ($cx+2) . ',' . ($cy+26) . ' L' . ($cx+2) . ',' . ($cy+46) . ' M' . ($cx-6) . ',' . ($cy+32) . ' L' . ($cx-6) . ',' . ($cy+52) . ' M' . ($cx+10) . ',' . ($cy+32) . ' L' . ($cx+10) . ',' . ($cy+52) . '" fill="none" stroke="#22c55e" stroke-width="5" opacity="0.85"/>';
        }
        if (str_contains($low, 'buda') || str_contains($low, 'candi') || str_contains($low, 'peninggalan')
            || str_contains($low, 'warisan') || str_contains($low, 'sejarah')) {
            return '<rect x="' . ($cx-18) . '" y="' . ($cy-30) . '" width="36" height="30" fill="none" stroke="#f59e0b" stroke-width="4" opacity="0.85"/>'
                . '<rect x="' . ($cx-12) . '" y="' . ($cy-8) . '" width="24" height="22" fill="none" stroke="#f59e0b" stroke-width="3" opacity="0.85"/>'
                . '<rect x="' . ($cx-16) . '" y="' . ($cy+16) . '" width="8" height="10" fill="#f59e0b" opacity="0.85"/>'
                . '<rect x="' . ($cx+8) . '" y="' . ($cy+16) . '" width="8" height="10" fill="#f59e0b" opacity="0.85"/>';
        }
        if (str_contains($low, 'kuliner') || str_contains($low, 'makan') || str_contains($low, 'restoran') || str_contains($low, 'masakan')) {
            return '<circle cx="' . ($cx-10) . '" cy="' . $cy . '" r="5" fill="none" stroke="#f97316" stroke-width="3" opacity="0.85"/>'
                . '<circle cx="' . ($cx+12) . '" cy="' . $cy . '" r="5" fill="none" stroke="#f97316" stroke-width="3" opacity="0.85"/>'
                . '<line x1="' . ($cx-2) . '" y1="' . ($cy-3) . '" x2="' . ($cx+4) . '" y2="' . ($cy-3) . '" stroke="#f97316" stroke-width="3" opacity="0.85"/>'
                . '<line x1="' . ($cx-5) . '" y1="' . ($cy+2) . '" x2="' . ($cx+7) . '" y2="' . ($cy+2) . '" stroke="#f97316" stroke-width="3" opacity="0.85"/>';
        }
        if (str_contains($low, 'ibadah') || str_contains($low, 'masjid') || str_contains($low, 'gereja')
            || str_contains($low, 'pura') || str_contains($low, 'vihara') || str_contains($low, 'religi')) {
            return '<path d="M' . ($cx-16) . ',' . ($cy+10) . ' L' . ($cx+16) . ',' . ($cy+10) . ' L' . ($cx) . ',' . ($cy-22) . ' Z'
                . '" fill="none" stroke="#ef4444" stroke-width="4" opacity="0.85"/>'
                . '<line x1="' . $cx . '" y1="' . ($cy-22) . '" x2="' . $cx . '" y2="' . ($cy+14) . '" stroke="#ef4444" stroke-width="3" opacity="0.85"/>';
        }
        if (str_contains($low, 'pend') || str_contains($low, 'sekolah') || str_contains($low, 'univ')) {
            return '<path d="M' . ($cx-20) . ',' . ($cy+18) . ' L' . $cx . ',' . ($cy-20) . ' L' . ($cx+20) . ',' . ($cy+18) . ' Z'
                . '" fill="none" stroke="#0ea5e9" stroke-width="4" opacity="0.85"/>'
                . '<rect x="' . ($cx-10) . '" y="' . ($cy-2) . '" width="20" height="8" fill="none" stroke="#0ea5e9" stroke-width="3" opacity="0.85"/>';
        }
        if (str_contains($low, 'transport') || str_contains($low, 'kendaraan')) {
            return '<rect x="' . ($cx-22) . '" y="' . ($cy-6) . '" width="22" height="12" rx="3" fill="none" stroke="#3b82f6" stroke-width="4" opacity="0.85"/>'
                . '<circle cx="' . ($cx-10) . '" cy="' . ($cy+8) . '" r="5" fill="none" stroke="#3b82f6" stroke-width="3" opacity="0.85"/>'
                . '<circle cx="' . ($cx+14) . '" cy="' . ($cy+8) . '" r="5" fill="none" stroke="#3b82f6" stroke-width="3" opacity="0.85"/>'
                . '<line x1="' . ($cx+10) . '" y1="' . ($cy-6) . '" x2="' . ($cx+22) . '" y2="' . ($cy-6) . '" stroke="#3b82f6" stroke-width="3" opacity="0.85"/>';
        }
        if (str_contains($low, 'pariwisata') || str_contains($low, 'wisata')) {
            return '<circle cx="' . $cx . '" cy="' . $cy . '" r="22" fill="none" stroke="#22c55e" stroke-width="4" opacity="0.85"/>'
                . '<circle cx="' . $cx . '" cy="' . $cy . '" r="8" fill="none" stroke="#22c55e" stroke-width="3" opacity="0.85"/>'
                . '<line x1="' . ($cx+26) . '" y1="' . $cy . '" x2="' . ($cx+40) . '" y2="' . ($cy+6) . '" stroke="#22c55e" stroke-width="3" opacity="0.85"/>'
                . '<line x1="' . $cx . '" y1="' . ($cy+26) . '" x2="' . ($cx+6) . '" y2="' . ($cy+40) . '" stroke="#22c55e" stroke-width="3" opacity="0.85"/>';
        }
        return '<circle cx="' . $cx . '" cy="' . $cy . '" r="22" fill="none" stroke="#64748b" stroke-width="4" opacity="0.85"/>'
            . '<path d="M' . ($cx-24) . ',' . ($cy+2) . ' L' . ($cx+24) . ',' . ($cy+2) . ' L' . ($cx+16) . ',' . ($cy+18) . ' L' . ($cx-16) . ',' . ($cy+18) . ' Z"'
            . ' fill="none" stroke="#64748b" stroke-width="3" opacity="0.85"/>'
            . '<line x1="' . ($cx-4) . '" y1="' . ($cy-14) . '" x2="' . ($cx+4) . '" y2="' . ($cy-14) . '" stroke="#64748b" stroke-width="3" opacity="0.85"/>'
            . '<line x1="' . $cx . '" y1="' . ($cy-18) . '" x2="' . $cx . '" y2="' . ($cy-10) . '" stroke="#64748b" stroke-width="3" opacity="0.85"/>';
    }

    /**
     * Kembalikan label teks ikon untuk accessibility.
     */
    private function categoryIconLabel(string $categoryName): string
    {
        $low = strtolower($categoryName);
        if (str_contains($low, 'al') || str_contains($low, 'gunung') || str_contains($low, 'danau') || str_contains($low, 'air terjun')) return 'Nature';
        if (str_contains($low, 'buda') || str_contains($low, 'candi') || str_contains($low, 'peninggalan')) return 'Culture';
        if (str_contains($low, 'kuliner') || str_contains($low, 'makan')) return 'Food';
        if (str_contains($low, 'ibadah') || str_contains($low, 'masjid') || str_contains($low, 'gereja')) return 'Worship';
        if (str_contains($low, 'pend') || str_contains($low, 'sekolah')) return 'Education';
        if (str_contains($low, 'transport')) return 'Transport';
        if (str_contains($low, 'pariwisata') || str_contains($low, 'wisata')) return 'Tourism';
        return 'Location';
    }
}