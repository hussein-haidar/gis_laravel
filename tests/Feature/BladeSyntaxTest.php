<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Semua view harus bisa dikompilasi jadi PHP yang valid.
 *
 * Compile saja tidak cukup: directive seperti @json(...) memotong argumen di
 * kurung pertama, jadi @json($locations->map(fn ($l) => [...])) tetap bisa
 * dikompil tapi menghasilkan PHP rusak (Unclosed '[') yang baru meledak saat
 * halaman dibuka. Test ini mem-parse hasil kompilasi sehingga kelas bug itu
 * ketahuan tanpa harus membuka tiap halaman di browser.
 */
class BladeSyntaxTest extends TestCase
{
    public function test_every_blade_view_compiles_into_valid_php(): void
    {
        $failures = [];

        foreach ($this->bladeFiles() as $file) {
            $compiled = Blade::compileString((string) file_get_contents($file));

            try {
                token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $failures[] = $file.' -> '.$e->getMessage();
            }
        }

        $this->assertSame([], $failures, "View gagal jadi PHP valid:\n".implode("\n", $failures));
    }

    /**
     * @return array<int, string>
     */
    private function bladeFiles(): array
    {
        $dir = new RecursiveDirectoryIterator(resource_path('views'));
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($dir) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }
}
