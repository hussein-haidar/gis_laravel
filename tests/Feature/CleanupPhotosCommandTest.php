<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Services\Gis\PhotoQualityValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pembersihan foto lokasi.
 *
 * Fokus tes: foto placeholder/foto lemah harus dilepas, lokasi yang fotonya
 * kosong dihapus (bukan dibiarkan dengan photo NULL), dan tidak ada fallback
 * placeholder di frontend.
 */
class CleanupPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    private Category $tempat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tempat = Category::create(['name' => 'Wisata Alam']);
    }

    private function photoLike(int $w, int $h, int $seed = 1): string
    {
        mt_srand($seed);
        $img = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, mt_rand(10, 140), mt_rand(20, 150), mt_rand(15, 120)));
            }
        }

        ob_start();
        imagejpeg($img, null, 92);
        $bin = (string) ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    private function solidCanvas(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 10, 90, 160));

        ob_start();
        imagejpeg($img, null, 95);
        $bin = (string) ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    private function locationWithPhoto(string $name, string $file, string $binary): Location
    {
        Storage::disk('public')->put('location_photos/'.$file, $binary);

        return Location::create([
            'name' => $name,
            'category_id' => $this->tempat->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
            'photo' => 'location_photos/'.$file,
        ]);
    }

    public function test_it_removes_placeholder_files_and_clears_the_reference(): void
    {
        $loc = $this->locationWithPhoto('Tempat Palsu', 'palsu.jpg', $this->solidCanvas(600, 400));

        Artisan::call('photos:cleanup', ['--placeholders' => true]);

        Storage::disk('public')->assertMissing('location_photos/palsu.jpg');
        $this->assertNull($loc->fresh()->photo);
    }

    public function test_it_deletes_locations_whose_photo_is_empty(): void
    {
        $tanpaFoto = Location::create([
            'name' => 'Tanpa Foto',
            'category_id' => $this->tempat->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);

        $berfoto = $this->locationWithPhoto('Ada Foto', 'ada.jpg', $this->photoLike(800, 600));

        Artisan::call('photos:cleanup', ['--delete-empty' => true]);

        $this->assertDatabaseMissing('locations', ['id' => $tanpaFoto->id]);
        $this->assertDatabaseHas('locations', ['id' => $berfoto->id]);
    }

    public function test_delete_empty_also_removes_locations_whose_file_vanished(): void
    {
        $loc = $this->locationWithPhoto('Hilang', 'hilang.jpg', $this->photoLike(800, 600));

        // Foto dihapus dari storage tanpa menyentuh kolom photo.
        Storage::disk('public')->delete('location_photos/hilang.jpg');

        Artisan::call('photos:cleanup', ['--delete-empty' => true]);

        $this->assertDatabaseMissing('locations', ['id' => $loc->id]);
    }

    public function test_dry_run_keeps_everything_untouched(): void
    {
        $loc = $this->locationWithPhoto('Palsu', 'palsu2.jpg', $this->solidCanvas(600, 400));

        Artisan::call('photos:cleanup', ['--placeholders' => true, '--delete-empty' => true, '--dry-run' => true]);

        Storage::disk('public')->assertExists('location_photos/palsu2.jpg');
        $this->assertNotNull($loc->fresh()->photo);
    }

    public function test_photo_display_is_empty_string_when_there_is_no_photo(): void
    {
        $loc = Location::create([
            'name' => 'Tanpa Foto',
            'category_id' => $this->tempat->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);

        $this->assertNull($loc->photo_url);
        $this->assertSame('', $loc->photo_display);
    }

    public function test_photo_display_points_to_the_stored_file(): void
    {
        $loc = $this->locationWithPhoto('Ada Foto', 'ada2.jpg', $this->photoLike(800, 600));

        $this->assertStringContainsString('location_photos/ada2.jpg', $loc->photo_display);
    }

    public function test_the_placeholder_route_no_longer_exists(): void
    {
        $loc = $this->locationWithPhoto('Ada Foto', 'ada3.jpg', $this->photoLike(800, 600));

        $this->get('/placeholder/'.$loc->id)->assertNotFound();
    }

    public function test_weak_failures_are_soft_and_placeholders_are_hard(): void
    {
        $validator = new PhotoQualityValidator;

        $this->assertTrue($validator->isSoftFailure('too_small'));
        $this->assertTrue($validator->isSoftFailure('extreme_ratio'));
        $this->assertFalse($validator->isHardFailure('too_small'));

        $this->assertTrue($validator->isHardFailure('solid_color'));
        $this->assertTrue($validator->isHardFailure('text_on_white'));
        $this->assertFalse($validator->isSoftFailure('solid_color'));
    }
}
