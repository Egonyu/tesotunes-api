<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            if (! Schema::hasColumn('songs', 'isrc_code')) {
                $table->string('isrc_code')->nullable()->after('isrc');
            }

            if (! Schema::hasColumn('songs', 'bitrate_original')) {
                $table->unsignedInteger('bitrate_original')->nullable()->after('file_size_bytes');
            }

            if (! Schema::hasColumn('songs', 'sample_rate')) {
                $table->unsignedInteger('sample_rate')->nullable()->after('bitrate_original');
            }
        });

        if (Schema::hasColumn('songs', 'isrc') && Schema::hasColumn('songs', 'isrc_code')) {
            DB::table('songs')
                ->whereNull('isrc_code')
                ->whereNotNull('isrc')
                ->where('isrc', '!=', '')
                ->update(['isrc_code' => DB::raw('isrc')]);
        }

        Schema::table('isrc_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('isrc_codes', 'isrc_code')) {
                $table->string('isrc_code', 20)->nullable()->after('code');
                $table->index('isrc_code');
            }

            if (! Schema::hasColumn('isrc_codes', 'formatted_isrc')) {
                $table->string('formatted_isrc', 20)->nullable()->after('isrc_code');
            }

            if (! Schema::hasColumn('isrc_codes', 'artist_id')) {
                $table->unsignedBigInteger('artist_id')->nullable()->after('song_id');
            }

            if (! Schema::hasColumn('isrc_codes', 'album_id')) {
                $table->unsignedBigInteger('album_id')->nullable()->after('artist_id');
            }

            if (! Schema::hasColumn('isrc_codes', 'status')) {
                $table->string('status', 30)->default('pending')->after('designation_code');
            }

            if (! Schema::hasColumn('isrc_codes', 'registered_at')) {
                $table->timestamp('registered_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('isrc_codes', 'generated_at')) {
                $table->timestamp('generated_at')->nullable()->after('registered_at');
            }

            if (! Schema::hasColumn('isrc_codes', 'registration_authority')) {
                $table->string('registration_authority')->nullable()->after('generated_at');
            }

            if (! Schema::hasColumn('isrc_codes', 'registration_reference')) {
                $table->string('registration_reference')->nullable()->after('registration_authority');
            }

            if (! Schema::hasColumn('isrc_codes', 'cleared_for_distribution')) {
                $table->boolean('cleared_for_distribution')->default(false)->after('registration_reference');
            }

            if (! Schema::hasColumn('isrc_codes', 'distribution_cleared_at')) {
                $table->timestamp('distribution_cleared_at')->nullable()->after('cleared_for_distribution');
            }
        });

        if (Schema::hasColumn('isrc_codes', 'code') && Schema::hasColumn('isrc_codes', 'isrc_code')) {
            DB::table('isrc_codes')
                ->whereNull('isrc_code')
                ->whereNotNull('code')
                ->where('code', '!=', '')
                ->update([
                    'isrc_code' => DB::raw('code'),
                    'formatted_isrc' => DB::raw('code'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('isrc_codes', function (Blueprint $table) {
            if (Schema::hasColumn('isrc_codes', 'distribution_cleared_at')) {
                $table->dropColumn('distribution_cleared_at');
            }
            if (Schema::hasColumn('isrc_codes', 'cleared_for_distribution')) {
                $table->dropColumn('cleared_for_distribution');
            }
            if (Schema::hasColumn('isrc_codes', 'registration_reference')) {
                $table->dropColumn('registration_reference');
            }
            if (Schema::hasColumn('isrc_codes', 'registration_authority')) {
                $table->dropColumn('registration_authority');
            }
            if (Schema::hasColumn('isrc_codes', 'generated_at')) {
                $table->dropColumn('generated_at');
            }
            if (Schema::hasColumn('isrc_codes', 'registered_at')) {
                $table->dropColumn('registered_at');
            }
            if (Schema::hasColumn('isrc_codes', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('isrc_codes', 'album_id')) {
                $table->dropColumn('album_id');
            }
            if (Schema::hasColumn('isrc_codes', 'artist_id')) {
                $table->dropColumn('artist_id');
            }
            if (Schema::hasColumn('isrc_codes', 'formatted_isrc')) {
                $table->dropColumn('formatted_isrc');
            }
            if (Schema::hasColumn('isrc_codes', 'isrc_code')) {
                $table->dropIndex(['isrc_code']);
                $table->dropColumn('isrc_code');
            }
        });

        Schema::table('songs', function (Blueprint $table) {
            if (Schema::hasColumn('songs', 'sample_rate')) {
                $table->dropColumn('sample_rate');
            }
            if (Schema::hasColumn('songs', 'bitrate_original')) {
                $table->dropColumn('bitrate_original');
            }
            if (Schema::hasColumn('songs', 'isrc_code')) {
                $table->dropColumn('isrc_code');
            }
        });
    }
};
