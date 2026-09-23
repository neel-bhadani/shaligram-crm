<?php

namespace App\Services\LeadImport;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Private, expiring server state. Source files and plans never enter business tables. */
class LeadImportStore
{
    public function directory(User $user, string $token): string
    {
        abort_unless((bool) preg_match('/^[a-f0-9-]{36}$/D', $token), 404);

        return "lead-imports/{$user->id}/{$token}";
    }

    public function read(User $user, string $token): array
    {
        $directory = $this->directory($user, $token);
        $disk = Storage::disk('local');
        abort_unless($disk->exists("{$directory}/state.json"), 404, 'Upload expired. Please upload again.');
        try {
            $state = json_decode($disk->get("{$directory}/state.json"), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $disk->deleteDirectory($directory);
            abort(410, 'Import state could not be read. Please upload again.');
        }
        if ($state['expires_at'] < now()->timestamp) {
            $disk->deleteDirectory($directory);
            abort(410, 'Upload expired. Please upload again.');
        }

        return $state;
    }

    public function write(User $user, string $token, array $state): void
    {
        $path = Storage::disk('local')->path($this->directory($user, $token).'/state.json');
        file_put_contents($path.'.tmp', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
        rename($path.'.tmp', $path);
    }

    /** Serializes preview, cancellation and chunk requests, including retries. */
    public function locked(User $user, string $token, callable $work): mixed
    {
        $directory = $this->directory($user, $token);
        abort_unless(Storage::disk('local')->exists("{$directory}/state.json"), 404);
        $handle = fopen(Storage::disk('local')->path("{$directory}/lock"), 'c');
        abort_unless(flock($handle, LOCK_EX | LOCK_NB), 409, 'Import is busy. Retry this request.');
        try {
            return $work($this->read($user, $token));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function delete(User $user, string $token): void
    {
        Storage::disk('local')->deleteDirectory($this->directory($user, $token));
    }

    public function prune(): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        foreach ($disk->directories('lead-imports') as $userDirectory) {
            foreach ($disk->directories($userDirectory) as $directory) {
                $path = "{$directory}/state.json";
                $expiry = $disk->exists($path)
                    ? (json_decode($disk->get($path), true)['expires_at'] ?? 0)
                    : filemtime($disk->path($directory)) + 86400;
                if ($expiry < now()->timestamp) {
                    $handle = fopen($disk->path($directory.'/lock'), 'c');
                    if (flock($handle, LOCK_EX | LOCK_NB)) {
                        $disk->deleteDirectory($directory);
                        flock($handle, LOCK_UN);
                        $count++;
                    }
                    fclose($handle);
                }
            }
        }

        return $count;
    }
}
