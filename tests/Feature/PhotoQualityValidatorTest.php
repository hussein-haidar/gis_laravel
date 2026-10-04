<?php

namespace Tests\Feature;

use App\Services\Gis\PhotoQualityValidator;
use Tests\TestCase;

/**
 * Validasi kualitas foto.
 *
 * Setiap kasus memakai gambar yang benar-benar digambar dengan GD, bukan
 * fixture binary, supaya tes tidak bergantung pada aset eksternal dan
 * ambang batas yang diuji bisa dibuktikan dari pikselnya.
 */
class PhotoQualityValidatorTest extends TestCase
{
    private PhotoQualityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PhotoQualityValidator;
    }

    /**
     * Kanvas warnasolid.
     */
    private function solidCanvas(int $w, int $h, array $rgb): string
    {
        $img = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $color);

        ob_start();
        imagejpeg($img, null, 95);
        $bin = (string) ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    /**
     * "Foto": noise berwarna merampangan yang meniru tekstur alam.
     */
    private function photoLike(int $w, int $h, int $seed = 1): string
    {
        mt_srand($seed);
        $img = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorallocate(
                    $img,
                    mt_rand(10, 140),
                    mt_rand(20, 150),
                    mt_rand(15, 120)
                );
                imagesetpixel($img, $x, $y, $c);
            }
        }

        ob_start();
        imagejpeg($img, null, 92);
        $bin = (string) ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    /**
     * Placeholder teks: kanvas putih dengan garis-garis gelap simulating
     * huruf pada beberapa baris.
     */
    private function textOnWhite(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 20, 20, 20);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $white);

        // Beberapa baris "teks" tipis di area atas.
        for ($row = 0; $row < 6; $row++) {
            $y = 20 + $row * 14;
            for ($x = 20; $x < $w - 20; $x += 3) {
                imagesetpixel($img, $x, $y, $black);
                imagesetpixel($img, $x, $y + 1, $black);
            }
        }

        ob_start();
        imagejpeg($img, null, 92);
        $bin = (string) ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    public function test_it_accepts_a_normal_photo(): void
    {
        $result = $this->validator->inspect($this->photoLike(400, 300));

        $this->assertTrue($result['ok'], 'Foto bertekstur harus diterima: '.$this->validator->describe($result));
        $this->assertNull($result['reason']);
    }

    public function test_it_rejects_a_text_placeholder_on_white(): void
    {
        $result = $this->validator->inspect($this->textOnWhite(600, 400));

        $this->assertFalse($result['ok']);
        $this->assertSame('text_on_white', $result['reason']);
    }

    public function test_it_rejects_a_flat_black_fill(): void
    {
        $result = $this->validator->inspect($this->solidCanvas(600, 400, [0, 0, 0]));

        $this->assertFalse($result['ok']);
        $this->assertContains($result['reason'], ['solid_color', 'flat_fill']);
    }

    public function test_it_rejects_a_single_flat_color(): void
    {
        $result = $this->validator->inspect($this->solidCanvas(600, 600, [200, 235, 255]));

        $this->assertFalse($result['ok']);
        $this->assertSame('solid_color', $result['reason']);
    }

    public function test_it_rejects_unreadable_data(): void
    {
        $result = $this->validator->inspect('bukan gambar sama sekali');

        $this->assertFalse($result['ok']);
        $this->assertSame('unreadable', $result['reason']);
    }

    public function test_it_reports_dimensions_for_accepted_images(): void
    {
        $result = $this->validator->inspect($this->photoLike(640, 480, seed: 7));

        $this->assertTrue($result['ok']);
        $this->assertSame(640, $result['width']);
        $this->assertSame(480, $result['height']);
    }

    public function test_is_usable_matches_inspect(): void
    {
        $this->assertTrue($this->validator->isUsable($this->photoLike(400, 300)));
        $this->assertFalse($this->validator->isUsable($this->solidCanvas(400, 300, [0, 0, 0])));
    }

    /**
     * Logo atau ikon 32x32 harus ditolak, tapi sebagai kegagalan "lemah":
     * lokasi boleh di-fetch ulang dengan kandidat lain, tidak langsung
     * dihapus seperti placeholder yang isinya bukan foto.
     */
    public function test_it_rejects_a_tiny_logo_as_soft_failure(): void
    {
        $result = $this->validator->inspect($this->photoLike(64, 64, seed: 3));

        $this->assertFalse($result['ok']);
        $this->assertSame('too_small', $result['reason']);
        $this->assertTrue($this->validator->isSoftFailure($result['reason']));
        $this->assertFalse($this->validator->isHardFailure($result['reason']));
    }

    public function test_it_rejects_a_banner_as_soft_failure(): void
    {
        $result = $this->validator->inspect($this->photoLike(1800, 400, seed: 5));

        $this->assertFalse($result['ok']);
        $this->assertSame('extreme_ratio', $result['reason']);
        $this->assertTrue($this->validator->isSoftFailure($result['reason']));
    }

    public function test_placeholder_on_white_is_a_hard_failure(): void
    {
        $result = $this->validator->inspect($this->textOnWhite(800, 600));

        $this->assertSame('text_on_white', $result['reason']);
        $this->assertTrue($this->validator->isHardFailure($result['reason']));
        $this->assertFalse($this->validator->isSoftFailure($result['reason']));
    }

    public function test_inspect_file_reports_missing_file(): void
    {
        $result = $this->validator->inspectFile(storage_path('app/public/tidak-ada-foto-ini.jpg'));

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_file', $result['reason']);
    }

    /**
     * Gambar di atas batas analisis harus diterima tanpa didecode penuh.
     *
     * Kasus ini guarding terhadap OOM: GD mendecode JPEG pada resolusi
     * penuh, sehingga foto 50 MP membutuhkan ratusan MB. Validator harus
     * melewati analisis piksel untuk gambar sebesar itu.
     */
    public function test_it_skips_pixel_analysis_for_very_large_images(): void
    {
        // Header PNG valid dengan dimensi raksasa; isi tidak pernah didecode
        // karena guard harus menolak lebih dulu.
        $w = 10000;
        $h = 10000;

        $png = pack('H*', '89504e470d0a1a0a')
            .pack('N', 13).'IHDR'.pack('NN', $w, $h)
            .pack('C5', 8, 2, 0, 0, 0);

        $result = $this->validator->inspect($png);

        $this->assertTrue($result['ok']);
        $this->assertSame($w, $result['width']);
        $this->assertSame($h, $result['height']);
        $this->assertSame(0.0, $result['dark_pct']);
    }
}
