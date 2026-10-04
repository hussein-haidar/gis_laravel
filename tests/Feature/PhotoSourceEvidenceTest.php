<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Services\Gis\WikimediaPhotoFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifikasi otomatis foto: koordinat sumber dan kategori Commons.
 *
 * Tujuannya mengurangi antrean manual: foto yang koordinat sumbernya dekat
 * lokasi atau kategorinya menyebut nama tempat tidak perlu diperiksa manusia,
 * sedangkan foto yang koordinatnya jauh harus ditolak.
 */
class PhotoSourceEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Category $tempat;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tempat = Category::create(['name' => 'Wisata Alam']);
        $this->location = Location::create([
            'name' => 'Museum Bank Indonesia',
            'category_id' => $this->tempat->id,
            'latitude' => -6.175392,
            'longitude' => 106.827153,
        ]);
    }

    private function fakeCommons(array $coordinates, array $categories = [], bool $missing = false): void
    {
        Http::fake([
            'commons.wikimedia.org/w/api.php*' => Http::response([
                'query' => [
                    'pages' => $missing
                        ? ['-1' => ['missing' => '']]
                        : [
                            '1' => [
                                'title' => 'File:Test.jpg',
                                'coordinates' => $coordinates === [] ? [] : [$coordinates],
                                'categories' => array_map(
                                    fn ($c) => ['title' => $c],
                                    $categories
                                ),
                            ],
                        ],
                ],
            ]),
            '*' => Http::response([]),
        ]);
    }

    public function test_file_title_is_derived_from_the_upload_url(): void
    {
        $title = WikimediaPhotoFetcher::commonsFileTitle(
            'Museum Bank Indonesia',
            'https://upload.wikimedia.org/wikipedia/commons/d/d6/Museum_Bank_Indonesia.jpg'
        );

        $this->assertSame('File:Museum_Bank_Indonesia.jpg', $title);
    }

    public function test_source_near_the_location_is_auto_approved(): void
    {
        // Sekitar 100 m dari koordinat lokasi.
        $this->fakeCommons(['lat' => -6.17590, 'lon' => 106.82715]);

        $verdict = (new WikimediaPhotoFetcher)->judgeSource(
            'Museum Bank Indonesia',
            'https://upload.wikimedia.org/wikipedia/commons/d/d6/Museum_Bank_Indonesia.jpg',
            -6.175392,
            106.827153,
            'Museum Bank Indonesia'
        );

        $this->assertSame('auto', $verdict['verdict']);
        $this->assertLessThan(200, $verdict['distance_m']);
    }

    public function test_source_far_from_the_location_is_rejected(): void
    {
        // Yokohama, Japan: jelas bukan Museum Bank Indonesia.
        $this->fakeCommons(['lat' => 35.4437, 'lon' => 139.6380]);

        $verdict = (new WikimediaPhotoFetcher)->judgeSource(
            'Museum Bank Indonesia',
            'https://upload.wikimedia.org/wikipedia/commons/d/d6/Museum_Bank_Indonesia.jpg',
            -6.175392,
            106.827153,
            'Museum Bank Indonesia'
        );

        $this->assertSame('too_far', $verdict['verdict']);
        $this->assertGreaterThan(5000, $verdict['distance_m']);
    }

    public function test_matching_category_is_enough_when_there_is_no_gps(): void
    {
        $this->fakeCommons([], ['Category:Museum Bank Indonesia', 'Category:Jakarta']);

        $verdict = (new WikimediaPhotoFetcher)->judgeSource(
            'Museum Bank Indonesia',
            'https://upload.wikimedia.org/wikipedia/commons/d/d6/Museum_Bank_Indonesia.jpg',
            -6.175392,
            106.827153,
            'Museum Bank Indonesia'
        );

        $this->assertSame('auto', $verdict['verdict']);
        $this->assertStringContainsString('Museum Bank Indonesia', $verdict['note']);
    }

    public function test_unrelated_category_stays_pending(): void
    {
        $this->fakeCommons([], ['Category:Bank Indonesia', 'Category:Architecture in Jakarta']);

        $verdict = (new WikimediaPhotoFetcher)->judgeSource(
            'Museum Bank Indonesia',
            'https://upload.wikimedia.org/wikipedia/commons/d/d6/Museum_Bank_Indonesia.jpg',
            -6.175392,
            106.827153,
            'Museum Bank Indonesia'
        );

        $this->assertSame('pending', $verdict['verdict']);
    }

    public function test_source_without_coordinates_or_categories_stays_pending(): void
    {
        $this->fakeCommons([], []);

        $verdict = (new WikimediaPhotoFetcher)->judgeSource(
            'Museum Bank Indonesia',
            'https://upload.wikimedia.org/wikipedia/commons/d/d6/Museum_Bank_Indonesia.jpg',
            -6.175392,
            106.827153,
            'Museum Bank Indonesia'
        );

        $this->assertSame('pending', $verdict['verdict']);
    }

    public function test_locate_sources_command_auto_approves_and_releases_photos(): void
    {
        $this->fakeCommons(['lat' => -6.17590, 'lon' => 106.82715]);

        $near = $this->location;
        $near->update([
            'photo' => 'location_photos/near.jpg',
            'photo_source_title' => 'Museum Bank Indonesia',
            'photo_source_url' => 'https://upload.wikimedia.org/wikipedia/commons/d/d6/Near.jpg',
            'photo_review_status' => Location::PHOTO_PENDING,
        ]);
        Storage::disk('public')->put('location_photos/near.jpg', 'x');

        $far = Location::create([
            'name' => 'Museum Bank Indonesia',
            'category_id' => $this->tempat->id,
            'latitude' => 35.4437,
            'longitude' => 139.6380,
            'photo' => 'location_photos/far.jpg',
            'photo_source_title' => 'Museum Bank Indonesia',
            'photo_source_url' => 'https://upload.wikimedia.org/wikipedia/commons/d/d6/Far.jpg',
            'photo_review_status' => Location::PHOTO_PENDING,
        ]);
        Storage::disk('public')->put('location_photos/far.jpg', 'x');

        $this->artisan('photos:locate-sources')->assertExitCode(0);

        $this->assertSame(Location::PHOTO_APPROVED, $near->fresh()->photo_review_status);
        $this->assertNotNull($near->fresh()->photo_source_distance_m);

        $this->assertNull($far->fresh()->photo);
        Storage::disk('public')->assertMissing('location_photos/far.jpg');
    }
}
