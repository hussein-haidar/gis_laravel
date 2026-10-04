<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * photos:refill-all = refill foto tanpa perlu Izin manual per lokasi.
 *
 * Skenario yang diuji: satu lokasi dapat foto yang judul sumbernya cocok,
 * satu lokasi lagi tidak punya kandidat sehingga harus dihapus karena tidak
 * ada foto = tidak ada lokasi.
 */
class RefillLocationPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    private Category $tempat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tempat = Category::create(['name' => 'Wisata Alam']);

        Http::fake([
            'api.openverse.org/*' => Http::response([
                'results' => [],
            ]),
            'commons.wikimedia.org/*' => Http::response([
                'query' => ['pages' => []],
            ]),
            '*' => Http::response([]),
        ]);
    }

    private function place(string $name): Location
    {
        return Location::create([
            'name' => $name,
            'category_id' => $this->tempat->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
    }

    /**
     * Bytes JPEG asli yang berisi noise.
     *
     * Validator menolak gambar tidak terbaca ("unreadable") dan gambar
     * polos satu warna ("solid_color"), jadi fixture harus gambarJPEG yang
     * benar-benar berisi variation.
     */
    private function realJpegBytes(): string
    {
        $im = imagecreatetruecolor(600, 400);

        for ($x = 0; $x < 600; $x += 4) {
            for ($y = 0; $y < 400; $y += 4) {
                $color = imagecolorallocate(
                    $im,
                    random_int(0, 255),
                    random_int(0, 255),
                    random_int(0, 255)
                );
                imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $color);
            }
        }

        ob_start();
        imagejpeg($im, null, 70);

        return (string) ob_get_clean();
    }

    public function test_it_deletes_places_that_get_no_photo(): void
    {
        $place = $this->place('Tempat Tanpa Sumber');

        Artisan::call('photos:refill-all', ['--rounds' => 1, '--sleep' => 0]);

        $this->assertNull(Location::find($place->id));
    }

    public function test_it_keeps_places_whose_source_title_matches_the_name(): void
    {
        $place = $this->place('Candi Prambanan');

        // Simulasi hasil fetch yang benar: file tersimpan + metadata sumber.
        $path = 'location_photos/prambanan.jpg';
        Storage::disk('public')->put($path, $this->realJpegBytes());
        $place->update([
            'photo' => $path,
            'photo_source_title' => 'File:Candi Prambanan 2019.jpg',
            'photo_source_url' => 'https://upload.wikimedia.org/x',
            'photo_source_provider' => 'commons',
            'photo_fetched_at' => now(),
        ]);

        Artisan::call('photos:refill-all', ['--rounds' => 1, '--sleep' => 0]);

        $fresh = $place->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($path, $fresh->photo);
        $this->assertSame('File:Candi Prambanan 2019.jpg', $fresh->photo_source_title);
    }

    public function test_it_releases_photos_whose_source_belongs_to_another_place(): void
    {
        $place = $this->place('Kebun Binatang Ragunan');

        $path = 'location_photos/jurug.jpg';
        Storage::disk('public')->put($path, $this->realJpegBytes());
        $place->update([
            'photo' => $path,
            // Zoo yang salah: hanya berbagi kata umum "kebun" dan "binatang".
            'photo_source_title' => 'File:Kebun Binatang Jurug.jpg',
            'photo_source_url' => 'https://upload.wikimedia.org/x',
            'photo_source_provider' => 'commons',
            'photo_fetched_at' => now(),
        ]);

        Artisan::call('photos:refill-all', ['--rounds' => 1, '--sleep' => 0]);

        $this->assertNull(Location::find($place->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_limit_skips_deletion_so_untried_places_are_not_removed(): void
    {
        $a = $this->place('Tempat A');
        $b = $this->place('Tempat B');

        Artisan::call('photos:refill-all', [
            '--rounds' => 1,
            '--sleep' => 0,
            '--limit' => 1,
        ]);

        $this->assertNotNull(Location::find($a->id));
        $this->assertNotNull(Location::find($b->id));
        $this->assertStringContainsString(
            'lewati hapus lokasi',
            Str::of(Artisan::output())->replace("\r\n", "\n")->__toString()
        );
    }
}
