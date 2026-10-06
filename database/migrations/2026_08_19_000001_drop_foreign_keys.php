<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return; // SQLite doesn't support INFORMATION_SCHEMA
        }

        $this->dropForeignIfExists('activity_logs', 'user_id');
        $this->dropForeignIfExists('locations', 'category_id');
        $this->dropForeignIfExists('users', 'role_id');
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        });
    }

    protected function dropForeignIfExists(string $table, string $column): void
    {
        $fkName = $this->getForeignKeyName($table, $column);
        if ($fkName) {
            Schema::table($table, function (Blueprint $table) use ($fkName) {
                $table->dropForeign($fkName);
            });
        }
    }

    protected function getForeignKeyName(string $table, string $column): ?string
    {
        $result = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ? 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$table, $column]);

        return $result[0]->CONSTRAINT_NAME ?? null;
    }
};
