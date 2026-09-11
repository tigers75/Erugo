<?php

namespace Tests\Feature;

use App\Jobs\CreateShareZip;
use App\Models\File;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemoveShareFilesTest extends TestCase
{
    use RefreshDatabase;

    private array $pathsToClean = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->pathsToClean) as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $items = array_diff(scandir($path) ?: [], ['.', '..']);
                foreach ($items as $item) {
                    $itemPath = $path . DIRECTORY_SEPARATOR . $item;
                    if (is_file($itemPath) || is_link($itemPath)) {
                        @unlink($itemPath);
                    }
                }
                @rmdir($path);
            }
        }

        parent::tearDown();
    }

    private function makeUser(bool $admin = false): User
    {
        return User::factory()->create(['admin' => $admin]);
    }

    private function makeShare(User $owner, array $files): Share
    {
        $longId = 'remove-' . Str::random(10);
        $path = $owner->id . '/' . $longId;
        $shareDir = storage_path('app/shares/' . $path);
        mkdir($shareDir, 0750, true);
        $this->pathsToClean[] = $shareDir;
        $this->pathsToClean[] = dirname($shareDir);

        $share = Share::create([
            'user_id' => $owner->id,
            'name' => 'Removal Test',
            'description' => '',
            'path' => $path,
            'long_id' => $longId,
            'size' => array_sum(array_column($files, 'size')),
            'file_count' => count($files),
            'download_limit' => null,
            'download_count' => 3,
            'require_email' => false,
            'expires_at' => now()->addDays(30),
            'status' => 'ready',
        ]);

        foreach ($files as $index => $attributes) {
            $name = $attributes['name'] ?? "file{$index}.txt";
            $content = str_repeat(chr(65 + $index), $attributes['size']);
            file_put_contents($shareDir . '/' . $name, $content);

            File::create([
                'name' => $name,
                'original_name' => $name,
                'size' => $attributes['size'],
                'type' => 'text/plain',
                'share_id' => $share->id,
                'temp_path' => null,
                'full_path' => null,
                'storage_id' => $attributes['storage_id'] ?? (string) Str::uuid(),
            ]);
        }

        return $share->fresh(['files']);
    }

    public function test_owner_can_remove_one_file(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, [
            ['name' => 'keep.txt', 'size' => 10],
            ['name' => 'remove.txt', 'size' => 20],
        ]);
        $removed = $share->files->firstWhere('name', 'remove.txt');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => [$removed->id]])
            ->assertOk();

        $share->refresh();
        $this->assertEquals(1, $share->file_count);
        $this->assertEquals(10, $share->size);
        $this->assertEquals('pending', $share->status);
        $this->assertDatabaseMissing('files', ['id' => $removed->id]);
        $this->assertFileDoesNotExist(storage_path('app/shares/' . $share->path . '/remove.txt'));
    }

    public function test_owner_can_remove_multiple_files(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, [
            ['name' => 'one.txt', 'size' => 10],
            ['name' => 'two.txt', 'size' => 20],
            ['name' => 'three.txt', 'size' => 30],
        ]);
        $ids = $share->files->whereIn('name', ['one.txt', 'two.txt'])->pluck('id')->all();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => $ids])
            ->assertOk();

        $share->refresh();
        $this->assertEquals(1, $share->file_count);
        $this->assertEquals(30, $share->size);
    }

    public function test_cannot_remove_the_last_file(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, [['name' => 'last.txt', 'size' => 10]]);
        $file = $share->files->first();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => [$file->id]])
            ->assertStatus(422);

        $this->assertDatabaseHas('files', ['id' => $file->id]);
        $this->assertFileExists(storage_path('app/shares/' . $share->path . '/last.txt'));
    }

    public function test_file_must_belong_to_the_share(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, [
            ['name' => 'one.txt', 'size' => 10],
            ['name' => 'two.txt', 'size' => 20],
        ]);
        $otherShare = $this->makeShare($owner, [['name' => 'other.txt', 'size' => 30]]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => [$otherShare->files->first()->id]])
            ->assertStatus(404);

        $this->assertEquals(2, $share->fresh()->file_count);
    }

    public function test_non_owner_cannot_remove_files(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $share = $this->makeShare($owner, [
            ['name' => 'one.txt', 'size' => 10],
            ['name' => 'two.txt', 'size' => 20],
        ]);

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => [$share->files->first()->id]])
            ->assertStatus(401);

        $this->assertEquals(2, $share->fresh()->file_count);
    }

    public function test_admin_can_remove_files_from_any_share(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $admin = $this->makeUser(true);
        $share = $this->makeShare($owner, [
            ['name' => 'one.txt', 'size' => 10],
            ['name' => 'two.txt', 'size' => 20],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => [$share->files->first()->id]])
            ->assertOk();
    }

    public function test_removing_hard_link_does_not_remove_cloned_share_file(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $storageId = (string) Str::uuid();
        $source = $this->makeShare($owner, [
            ['name' => 'shared.txt', 'size' => 12, 'storage_id' => $storageId],
            ['name' => 'keep.txt', 'size' => 8],
        ]);
        $clone = $this->makeShare($owner, [
            ['name' => 'placeholder.txt', 'size' => 12, 'storage_id' => $storageId],
        ]);

        $sourcePath = storage_path('app/shares/' . $source->path . '/shared.txt');
        $cloneDir = storage_path('app/shares/' . $clone->path);
        @unlink($cloneDir . '/placeholder.txt');
        link($sourcePath, $cloneDir . '/shared.txt');
        $cloneFile = $clone->files->first();
        $cloneFile->name = 'shared.txt';
        $cloneFile->save();

        $sourceFile = $source->files->firstWhere('name', 'shared.txt');
        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/shares/{$source->id}/files", ['file_ids' => [$sourceFile->id]])
            ->assertOk();

        $this->assertFileExists($cloneDir . '/shared.txt');
        $this->assertEquals(str_repeat('A', 12), file_get_contents($cloneDir . '/shared.txt'));
        $this->assertDatabaseHas('files', [
            'id' => $cloneFile->id,
            'storage_id' => $storageId,
        ]);
    }

    public function test_zip_is_invalidated_and_rebuild_is_queued(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, [
            ['name' => 'one.txt', 'size' => 10],
            ['name' => 'two.txt', 'size' => 20],
        ]);
        $zipPath = storage_path('app/shares/' . $share->path . '.zip');
        file_put_contents($zipPath, 'stale zip');
        $this->pathsToClean[] = $zipPath;

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => [$share->files->first()->id]])
            ->assertOk();

        $this->assertFileDoesNotExist($zipPath);
        Queue::assertPushed(CreateShareZip::class, fn (CreateShareZip $job) => $job->share->id === $share->id);
    }

    public function test_long_id_and_existing_share_properties_are_unchanged(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, [
            ['name' => 'one.txt', 'size' => 10],
            ['name' => 'two.txt', 'size' => 20],
        ]);
        $before = $share->only([
            'long_id', 'path', 'expires_at', 'download_limit', 'download_count', 'require_email',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/shares/{$share->id}/files", ['file_ids' => [$share->files->first()->id]])
            ->assertOk();

        $share->refresh();
        foreach ($before as $key => $value) {
            $this->assertEquals($value, $share->{$key}, "{$key} must not change");
        }
    }
}
