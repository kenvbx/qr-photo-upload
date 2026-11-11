<?php

namespace Tests\Feature;

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentUploadTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mysql_concurrent_same_key_creates_only_one_receipt_and_file(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires an isolated MySQL test database.');
        }
        $collection = $this->collection();
        $key = (string) Str::uuid();
        $results = $this->race($collection, [$key, $key], [40, 40]);
        $this->assertSame([false, true], array_values(array_unique(array_column($results, 'replayed'), SORT_REGULAR)));
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseCount('upload_receipts', 1);
    }

    public function test_mysql_parallel_distinct_photos_cannot_exceed_session_quota(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires an isolated MySQL test database.');
        }
        $collection = $this->collection();
        for ($index = 0; $index < 99; $index++) {
            $collection->photos()->create(['path' => 'missing/'.$index.'.png', 'mime' => 'image/png', 'bytes' => 1, 'width' => 1, 'height' => 1, 'sha256' => hash('sha256', 'existing-'.$index)]);
        }
        $results = $this->race($collection, [(string) Str::uuid(), (string) Str::uuid()], [40, 41]);
        $this->assertSame(1, count(array_filter($results, fn (array $result) => ($result['uploaded'] ?? 0) === 1)));
        $this->assertSame(1, count(array_filter($results, fn (array $result) => ($result['status'] ?? '') === 'rejected')));
        $this->assertDatabaseCount('photos', 100);
        $this->assertDatabaseCount('upload_receipts', 1);
    }

    private function collection(): UploadSession
    {
        return UploadSession::create(['user_id' => User::factory()->create()->id, 'title' => 'Concurrent upload test', 'token' => bin2hex(random_bytes(32)), 'expires_at' => now()->addDay()]);
    }

    /** @param array<int, string> $keys
     * @param  array<int, int>  $widths
     * @return array<int, array<string, mixed>>
     */
    private function race(UploadSession $collection, array $keys, array $widths): array
    {
        $directory = sys_get_temp_dir().'/qr-race-'.Str::uuid();
        File::makeDirectory($directory, 0700, true);
        $database = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'PRIVATE_STORAGE_ROOT' => $directory.'/photos'];
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[, $id, $key, $source, $ready, $go]=$argv;
touch($ready);
$deadline=microtime(true)+15;
while(!is_file($go)) { if(microtime(true)>$deadline) { exit(2); } usleep(10000); clearstatcache(true,$go); }
try {
 $result=app(App\Services\PhotoUpload::class)->store(App\Models\UploadSession::findOrFail($id),[new Illuminate\Http\UploadedFile($source,'race.png',null,null,true)],$key);
 echo json_encode($result,JSON_THROW_ON_ERROR);
} catch(Illuminate\Validation\ValidationException $exception) {
 echo json_encode(['status'=>'rejected','errors'=>$exception->errors()],JSON_THROW_ON_ERROR);
}
PHP;
        $processes = [];
        try {
            foreach ($keys as $index => $key) {
                $source = $directory.'/'.$index.'.png';
                $file = UploadedFile::fake()->image('source.png', $widths[$index], 30);
                File::copy($file->getRealPath(), $source);
                $processes[] = new Process([PHP_BINARY, '-r', $script, (string) $collection->id, $key, $source, $directory.'/ready-'.$index, $directory.'/go'], base_path(), $environment, null, 25);
            }
            DB::disconnect();
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Upload workers failed to become ready.');
                }
                usleep(10000);
                clearstatcache();
            }
            touch($directory.'/go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $stored = File::isDirectory($directory.'/photos') ? File::allFiles($directory.'/photos') : [];
            $this->assertCount(1, $stored);
            usort($results, fn (array $a, array $b) => (int) ($a['replayed'] ?? false) <=> (int) ($b['replayed'] ?? false));

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            File::deleteDirectory($directory);
            DB::reconnect();
        }
    }
}
