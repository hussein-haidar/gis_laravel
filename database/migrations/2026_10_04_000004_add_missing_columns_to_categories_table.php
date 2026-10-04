<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom kategori yang ada di database produksi tapi belum pernah ada di
     * migration, jadi install baru dan test database tidak punya kolom ini.
     * Model Category sudah memakainya (scope active(), ordered(), relasi
     * parent), dan halaman peta publik memfilter is_active.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0);
            }

            if (! Schema::hasColumn('categories', 'icon')) {
                $table->string('icon')->nullable();
            }

            if (! Schema::hasColumn('categories', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            if (! Schema::hasColumn('categories', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['sort_order', 'icon', 'is_active', 'parent_id'],
            fn (string $column) => Schema::hasColumn('categories', $column)
        ));

        if ($columns !== []) {
            Schema::table('categories', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
