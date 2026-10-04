<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Services\Gis\WikimediaPhotoFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit sumber foto: judul berkas asal harus menyebut nama lokasi.
 *
 * Ini yang menjamin "sumber gambar = nama tempat". Foto tanpa metadata atau
 * judulnya tidak menyebut nama lokasi dilepas supaya di-fetch ulang.
 */
class VerifyPhotoSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Category $tempat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tempat = Category::create(['name' => 'Wisata Alam']);
    }

    private function location(string $name, ?string $sourceTitle): Location
    {
        $file = 'location_photos/'.md5($name).'.jpg';
        Storage::disk('public')->put($file, 'dummy-binary');

        $loc = Location::create([
            'name' => $name,
            'category_id' => $this->tempat->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
            'photo' => $file,
        ]);

        if ($sourceTitle !== null) {
            $loc->update([
                'photo_source_title' => $sourceTitle,
                'photo_source_url' => 'https://upload.wikimedia.org/x',
                'photo_source_provider' => 'commons',
                'photo_fetched_at' => now(),
            ]);
        }

        return $loc->fresh();
    }

    public function test_title_matching_requires_most_of_the_location_name(): void
    {
        $fetcher = new WikimediaPhotoFetcher;

        $this->assertTrue($fetcher->titleMatchesLocation(
            'File:Candi Prambanan 2019.jpg',
            'Candi Prambanan'
        ));

        // "Prambanan Temple" tidak menyebut "candi" -> dicurigai milik tempat lain.
        $this->assertFalse($fetcher->titleMatchesLocation(
            'File:Prambanan Temple.jpg',
            'Candi Prambanan'
        ));

        // Nama panjang dengan prefik:judul hanya perlu menyebut sebagian
        // besar kata, bukan semua.
        $this->assertTrue($fetcher->titleMatchesLocation(
            'File:Museum Kereta Api Ambarawa.jpg',
            'Museum Kereta Api Ambarawa'
        ));

        // Kasus nyata yang salah sebelum ambang rasio ada: museum berbeda
        // yang hanya berbagi kata "Sasmitaloka".
        $this->assertFalse($fetcher->titleMatchesLocation(
            'File:Museum Sasmitaloka Panglima Besar Jenderal Soedirman.jpg',
            'Museum Sasmitaloka Jenderal Besar DR. A.H. Nasution'
        ));

        // Kata jenis tempat ("kebun", "binatang") tidak boleh dianggap
        // kecocokan: Ragunan dan Jurug adalah zoo berbeda.
        $this->assertFalse($fetcher->titleMatchesLocation(
            'File:Taman satwa taru Jurug - kebun binatang Jurug.jpg',
            'Kebun Binatang Ragunan'
        ));

        // Tapi zoo dengan nama khas yang sama tetap cocok.
        $this->assertTrue($fetcher->titleMatchesLocation(
            'File:Kebun Binatang Ragunan 2015.jpg',
            'Kebun Binatang Ragunan'
        ));
    }

    public function test_it_reports_ok_mismatch_and_unverified(): void
    {
        $this->location('Candi Prambanan', 'File:Candi Prambanan 2019.jpg');
        $this->location('Pantai Seminyak', 'File:Seminyak Beach Bali.jpg');
        $this->location('Museum Seni', null);

        Artisan::call('photos:verify-sources');
        $output = Artisan::output();

        $this->assertStringContainsString('Sesuai nama tempat  : 1', $output);
        $this->assertStringContainsString('Nama tidak cocok   : 1', $output);
        $this->assertStringContainsString('Tanpa metadata     : 1', $output);
    }

    public function test_fix_releases_mismatched_and_unverified_photos(): void
    {
        $ok = $this->location('Candi Prambanan', 'File:Candi Prambanan 2019.jpg');
        $mismatch = $this->location('Pantai Seminyak', 'File:Seminyak Beach Bali.jpg');
        $unverified = $this->location('Museum Seni', null);

        Artisan::call('photos:verify-sources', ['--fix' => true]);

        $this->assertNotNull($ok->fresh()->photo);
        $this->assertNotNull($ok->fresh()->photo_source_title);

        $this->assertNull($mismatch->fresh()->photo);
        $this->assertNull($mismatch->fresh()->photo_source_title);
        Storage::disk('public')->assertMissing($mismatch->photo);

        $this->assertNull($unverified->fresh()->photo);
        $this->assertNull($unverified->fresh()->photo_source_url);
    }

    public function test_report_only_mode_never_touches_data(): void
    {
        $loc = $this->location('Museum Seni', null);

        Artisan::call('photos:verify-sources');

        $this->assertNotNull($loc->fresh()->photo);
    }
}
