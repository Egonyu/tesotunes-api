<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standardize role names to lowercase with underscores.
     *
     * This fixes the role name mismatch between middleware expectations
     * (lowercase: 'admin', 'super_admin') and database values
     * (title case: 'Admin', 'Super Admin').
     */
    public function up(): void
    {
        $mappings = [
            'Super Admin'    => 'super_admin',
            'Admin'          => 'admin',
            'Moderator'      => 'moderator',
            'Artist'         => 'artist',
            'User'           => 'user',
            'Label Manager'  => 'label_manager',
            'Promoter'       => 'promoter',
            'Producer'       => 'producer',
            'DJ'             => 'dj',
        ];

        foreach ($mappings as $oldName => $newName) {
            DB::table('roles')
                ->where('name', $oldName)
                ->update(['name' => $newName]);
        }
    }

    /**
     * Revert role names back to title case.
     */
    public function down(): void
    {
        $mappings = [
            'super_admin'    => 'Super Admin',
            'admin'          => 'Admin',
            'moderator'      => 'Moderator',
            'artist'         => 'Artist',
            'user'           => 'User',
            'label_manager'  => 'Label Manager',
            'promoter'       => 'Promoter',
            'producer'       => 'Producer',
            'dj'             => 'DJ',
        ];

        foreach ($mappings as $oldName => $newName) {
            DB::table('roles')
                ->where('name', $oldName)
                ->update(['name' => $newName]);
        }
    }
};
