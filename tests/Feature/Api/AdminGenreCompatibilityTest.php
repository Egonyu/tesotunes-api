<?php

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class AdminGenreCompatibilityTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureGenreColumnsExist();
        $this->admin = $this->createUserWithRole('admin');
    }

    public function test_admin_can_create_genre_with_icon_picker_payload(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/genres', [
            'name' => 'Teso Cultural Gospel',
            'description' => 'Faith-based music in Ateso that blends church messages with traditional rhythms.',
            'color' => '#8B5CF6',
            'icon' => '🎹',
            'is_active' => true,
            'sort_order' => 4,
            'meta_title' => 'Teso Cultural Gospel',
            'meta_description' => 'Faith-based music in Ateso.',
            'meta_keywords' => 'teso,gospel,ateseo',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Genre created successfully.');

        $genre = Genre::query()->where('name', 'Teso Cultural Gospel')->firstOrFail();

        $this->assertSame('🎹', $genre->icon);
        $this->assertSame('Teso Cultural Gospel', $genre->meta_title);
        $this->assertSame('Faith-based music in Ateso.', $genre->meta_description);
        $this->assertSame('teso,gospel,ateseo', $genre->meta_keywords);
    }

    public function test_admin_genre_show_returns_icon_contract(): void
    {
        $genre = Genre::factory()->create([
            'icon' => '🎧',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/genres/{$genre->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.icon', '🎧')
            ->assertJsonPath('data.emoji', '🎧');
    }

    private function ensureGenreColumnsExist(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            if (! Schema::hasColumn('genres', 'icon')) {
                $table->string('icon', 50)->nullable()->after('color');
            }

            if (! Schema::hasColumn('genres', 'meta_title')) {
                $table->string('meta_title')->nullable()->after('sort_order');
            }

            if (! Schema::hasColumn('genres', 'meta_description')) {
                $table->string('meta_description', 500)->nullable()->after('meta_title');
            }

            if (! Schema::hasColumn('genres', 'meta_keywords')) {
                $table->string('meta_keywords')->nullable()->after('meta_description');
            }
        });
    }
}
