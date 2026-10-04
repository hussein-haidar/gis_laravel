<?php

namespace App\Services\Gis;

use Illuminate\Support\Facades\Log;

/**
 * Validator kualitas foto lokasi.
 *
 * Diperlukan karena Wikimedia/Openverse sesekali mengembalikan berkas yang
 * secara teknis "valid" tapi sama sekali bukan foto: kanvas putih berisi
 * teks, bidang warna rata, atau gambar hitam penuh. Dulu WikimediaPhotoFetcher
 * hanya memanggil getimagesizefromstring() sehingga semua berkas itu lolos
 * dan terpasang ke lokasi yang salah.
 *
 * Pengukuran dilakukan pada sampel 200px agar stabil untuk gambar 4K maupun
 * thumbnail 400px.
 */
class PhotoQualityValidator
{
    /** Panjang sisi maksimum untuk sampling. */
    protected const SAMPLE = 200;

    /**
     * Batas piksel untuk analisis (50 MP). Di atas ini gambar pasti foto asli
     * dan GD akan menghabiskan memori besar hanya untuk didecode penuh.
     */
    protected const MAX_ANALYZE_PIXELS = 50_000_000;

    /** Piksel dianggap gelap bila luminance < nilai ini. */
    protected const DARK_LUMA = 128;

    /**
     * Ambang batas. Angka-angka ini diturunkan dari sebaran data aktual
     * repository, bukan tebakan:
     *
     * Foto asli      : dark 20-85%, warna dominan < 15%, thousands of colors
     * Teks di putih  : warna dominan putih >= 25% dan dark < 20%
     *                 (contoh: 1952_f922bd56.jpg top=28.7% gelap=0.4%)
     * Warna rata     : dark < 1% dan warna dominan >= 25%
     *                 (contoh: 1751_4f9d8849.webp top=42.1% gelap=0.0%)
     * Hitam/putih    : satu warna menutup >= 95% (contoh: 2243 top=97%)
     */
    protected const MAX_TOP_COLOR_PCT = 95.0;

    protected const MAX_TOP_COLOR_UNIQUE = 4;

    protected const TEXT_MIN_TOP_PCT = 25.0;

    protected const TEXT_MAX_DARK_PCT = 20.0;

    protected const FLAT_MAX_DARK_PCT = 1.0;

    protected const FLAT_MIN_TOP_PCT = 25.0;

    /**
     * @return array{
     *   ok: bool, reason: ?string,
     *   width: int, height: int, dark_pct: float, white_pct: float,
     *   top_pct: float, top_hex: string, colors: int
     * }
     */
    public function inspect(string $binary): array
    {
        $fail = function (string $reason, array $extra = []): array {
            return array_merge([
                'ok' => false,
                'reason' => $reason,
                'width' => 0,
                'height' => 0,
                'dark_pct' => 0.0,
                'white_pct' => 0.0,
                'top_pct' => 0.0,
                'top_hex' => '',
                'colors' => 0,
            ], $extra);
        };

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return $fail('unreadable');
        }

        [$w, $h] = $info;

        // Dimensi checked sebelum decoding: logo, ikon, dan peta panoramik
        // lolos dari analisis warna karena warnanya memang banyak, tapi
        // jelas bukan foto lokasi.
        $shortSide = min($w, $h);
        $longSide = max($w, $h);

        if ($shortSide < self::MIN_SHORT_SIDE) {
            return $fail('too_small', ['width' => $w, 'height' => $h]);
        }

        if ($longSide / max(1, $shortSide) >= self::MAX_ASPECT_RATIO) {
            return $fail('extreme_ratio', ['width' => $w, 'height' => $h]);
        }

        // Gambar raksasa tidak mungkin placeholder dan GD mendecode-nya pada
        // resolusi penuh, yang bisa menghabiskan ratusan MB. Lewati analisis
        // piksel untuk gambar di atas ambang ini.
        if ($w * $h > self::MAX_ANALYZE_PIXELS) {
            return [
                'ok' => true,
                'reason' => null,
                'width' => $w,
                'height' => $h,
                'dark_pct' => 0.0,
                'white_pct' => 0.0,
                'top_pct' => 0.0,
                'top_hex' => '',
                'colors' => 0,
            ];
        }

        $img = @imagecreatefromstring($binary);
        if ($img === false) {
            // SVG tidak didukung GD. Bukan otomatis rusak - biarkan lewat.
            return [
                'ok' => true,
                'reason' => null,
                'width' => $w,
                'height' => $h,
                'dark_pct' => 0.0,
                'white_pct' => 0.0,
                'top_pct' => 0.0,
                'top_hex' => '',
                'colors' => 0,
            ];
        }

        // Kedua sisi dibatasi supaya jumlah sampel tidak pernah melebihi
        // 200x200, termasuk untuk gambar dengan rasio sisi ekstrem.
        $sw = min(self::SAMPLE, $w);
        $sh = max(1, (int) round($h * ($sw / $w)));
        $sh = min(self::SAMPLE, $sh);

        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagedestroy($img);

        $counts = [];
        $dark = 0;
        $white = 0;
        $n = 0;

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $counts[$rgb] = ($counts[$rgb] ?? 0) + 1;
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if (0.299 * $r + 0.587 * $g + 0.114 * $b < self::DARK_LUMA) {
                    $dark++;
                }
                if ($r >= 254 && $g >= 254 && $b >= 254) {
                    $white++;
                }
                $n++;
            }
        }
        imagedestroy($small);

        arsort($counts);
        $topColor = array_key_first($counts);
        $topPct = reset($counts) / $n * 100;
        $darkPct = $dark / $n * 100;
        $whitePct = $white / $n * 100;
        $colors = count($counts);
        $topHex = sprintf('#%02X%02X%02X', ($topColor >> 16) & 0xFF, ($topColor >> 8) & 0xFF, $topColor & 0xFF);

        $result = [
            'ok' => true,
            'reason' => null,
            'width' => $w,
            'height' => $h,
            'dark_pct' => round($darkPct, 1),
            'white_pct' => round($whitePct, 1),
            'top_pct' => round($topPct, 1),
            'top_hex' => $topHex,
            'colors' => $colors,
        ];

        if ($colors <= self::MAX_TOP_COLOR_UNIQUE) {
            $result['ok'] = false;
            $result['reason'] = 'solid_color';

            return $result;
        }

        if ($topPct >= self::MAX_TOP_COLOR_PCT) {
            $result['ok'] = false;
            $result['reason'] = 'flat_fill';

            return $result;
        }

        if ($topHex === '#FFFFFF' && $topPct >= self::TEXT_MIN_TOP_PCT && $darkPct < self::TEXT_MAX_DARK_PCT) {
            $result['ok'] = false;
            $result['reason'] = 'text_on_white';

            return $result;
        }

        if ($darkPct < self::FLAT_MAX_DARK_PCT && $topPct >= self::FLAT_MIN_TOP_PCT) {
            $result['ok'] = false;
            $result['reason'] = 'flat_color';

            return $result;
        }

        return $result;
    }

    /**
     * Sisi terpendek minimum (px). Berkas 32x32 atau 240x180 yang lolos
     * biasanya logo, ikon, atau thumbnail peta, bukan foto tempat.
     */
    protected const MIN_SHORT_SIDE = 300;

    /**
     * Rasio sisi maksimum. Rasio >= 3 berarti banner, kolase, atau peta
     * panoramik - bukan foto satu tempat.
     */
    protected const MAX_ASPECT_RATIO = 3.0;

    /**
     * Alasan yang hanya berarti "kandidat buruk", bukan "gambar palsu".
     *
     * Logo 32x32 atau peta panoramik memang bukan foto tempat, tapi banyak
     * foto asli tempat yang syutingnya kecil atau letterbox (Candi Prambanan
     * 600x280, Museum Keraton 320x240). Berkas seperti ini dilepas supaya
     * bisa di-fetch ulang dengan kandidat lebih baik; record lokasi baru
     * dihapus bila setelah itu fotonya tetap kosong.
     *
     * @var array<int, string>
     */
    protected const SOFT_REASONS = ['too_small', 'extreme_ratio'];

    /** Alasan keras: isi gambar jelas bukan foto (kanvas polos, teks, rusak). */
    protected const HARD_REASONS = ['solid_color', 'flat_fill', 'text_on_white', 'flat_color', 'unreadable'];

    /** True bila hasil inspect() berarti "cari kandidat lain", bukan "hapus lokasi". */
    public function isSoftFailure(?string $reason): bool
    {
        return in_array($reason, self::SOFT_REASONS, true);
    }

    /** True bila hasilnya placeholder palsu yang tidak mungkin dipakai. */
    public function isHardFailure(?string $reason): bool
    {
        return in_array($reason, self::HARD_REASONS, true);
    }

    /** True bila berkasnya foto layak dipakai tanpa perlu di-fetch ulang. */
    public function isUsable(string $binary): bool
    {
        return $this->inspect($binary)['ok'];
    }

    public function inspectFile(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            return ['ok' => false, 'reason' => 'missing_file'];
        }

        return $this->inspect((string) file_get_contents($absolutePath));
    }

    /**
     * Votre la description d'un résultat de rejet pour le log.
     *
     * @param  array  $r  Résultat de inspect()
     */
    public function describe(array $r): string
    {
        return sprintf(
            '%s (%dx%d gelap=%.1f%% putih=%.1f%% top=%.1f%% %s)',
            $r['reason'] ?? 'unknown',
            $r['width'],
            $r['height'],
            $r['dark_pct'],
            $r['white_pct'],
            $r['top_pct'],
            $r['top_hex']
        );
    }

    /** Log helper yang tidak melempar exception. */
    public function logRejected(string $context, array $r): void
    {
        Log::warning("Photo rejected [{$context}]: ".$this->describe($r));
    }
}
