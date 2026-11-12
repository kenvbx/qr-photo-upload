<?php

namespace Tests\Feature;

use App\Models\ResumableUpload;
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
        $this->assertDatabaseCount('jobs', 1);
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
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_mysql_parallel_uploads_cannot_exceed_byte_quota(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires an isolated MySQL test database.');
        }
        $collection = $this->collection();
        $collection->update(['quota_bytes' => max(UploadedFile::fake()->image('a.png', 40, 30)->getSize(), UploadedFile::fake()->image('b.png', 41, 30)->getSize())]);
        $results = $this->race($collection, [(string) Str::uuid(), (string) Str::uuid()], [40, 41]);
        $this->assertSame(1, count(array_filter($results, fn (array $result) => ($result['uploaded'] ?? 0) === 1)));
        $this->assertSame(1, count(array_filter($results, fn (array $result) => ($result['status'] ?? '') === 'rejected')));
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseCount('upload_receipts', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_mysql_thumbnail_generation_racing_deletion_cannot_leave_an_orphan_derivative(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires an isolated MySQL test database.');
        }
        $collection = $this->collection();
        $directory = sys_get_temp_dir().'/qr-thumbnail-race-'.Str::uuid();
        File::makeDirectory($directory.'/photos/photos', 0700, true);
        $source = UploadedFile::fake()->image('source.png', 4000, 3000);
        File::copy($source->getRealPath(), $directory.'/photos/photos/source.png');
        $photo = $collection->photos()->create(['path' => 'photos/source.png', 'mime' => 'image/png', 'bytes' => $source->getSize(), 'width' => 4000, 'height' => 3000]);
        $database = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'PRIVATE_STORAGE_ROOT' => $directory.'/photos'];
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[, $id, $action, $userId, $ready, $go]=$argv;
touch($ready);
$deadline=microtime(true)+15;
while(!is_file($go)) { if(microtime(true)>$deadline) { exit(2); } usleep(10000); clearstatcache(true,$go); }
if($action==='generate') {
 app(App\Services\PhotoThumbnail::class)->generate((int)$id);
} else {
 $photo=App\Models\Photo::findOrFail($id);
 if(!app(App\Services\PhotoRemoval::class)->remove($photo,(int)$userId)) { exit(3); }
}
PHP;
        $processes = [];
        try {
            foreach (['generate', 'delete'] as $action) {
                $processes[] = new Process([PHP_BINARY, '-d', 'memory_limit=256M', '-r', $script, (string) $photo->id, $action, (string) $collection->user_id, $directory.'/ready-'.$action, $directory.'/go'], base_path(), $environment, null, 25);
            }
            DB::disconnect();
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/ready-generate') || ! is_file($directory.'/ready-delete')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Thumbnail workers failed to become ready.');
                }
                usleep(10000);
                clearstatcache();
            }
            touch($directory.'/go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
            $this->assertCount(0, File::allFiles($directory.'/photos'));
            $this->assertDatabaseHas('audit_events', ['action' => 'photo.deleted', 'resource_id' => $photo->id]);
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

    public function test_mysql_simultaneous_zip_requests_reuse_one_export_and_job(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires an isolated MySQL test database.');
        }
        $collection = $this->collection();
        $directory = sys_get_temp_dir().'/qr-export-race-'.Str::uuid();
        File::makeDirectory($directory.'/photos', 0700, true);
        File::put($directory.'/photos/source.png', 'original');
        $photo = $collection->photos()->create(['path' => 'source.png', 'mime' => 'image/png', 'bytes' => 8, 'width' => 40, 'height' => 30]);
        $database = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'PRIVATE_STORAGE_ROOT' => $directory.'/photos'];
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[, $collectionId, $photoId, $ready, $go]=$argv;
touch($ready);
$deadline=microtime(true)+15;
while(!is_file($go)) { if(microtime(true)>$deadline) { exit(2); } usleep(10000); clearstatcache(true,$go); }
$export=app(App\Services\PhotoExportBuilder::class)->request(App\Models\UploadSession::findOrFail($collectionId),[(int)$photoId]);
echo $export->id;
PHP;
        $processes = [];
        try {
            foreach ([0, 1] as $index) {
                $processes[] = new Process([PHP_BINARY, '-r', $script, (string) $collection->id, (string) $photo->id, $directory.'/ready-'.$index, $directory.'/go'], base_path(), $environment, null, 25);
            }
            DB::disconnect();
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                if (microtime(true) > $deadline) {
                    $this->fail('ZIP request workers failed to become ready.');
                }
                usleep(10000);
                clearstatcache();
            }
            touch($directory.'/go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertSame($processes[0]->getOutput(), $processes[1]->getOutput());
            $this->assertDatabaseCount('photo_exports', 1);
            $this->assertDatabaseCount('jobs', 1);
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

    public function test_mysql_concurrent_resumable_finish_publishes_one_photo_and_job(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires an isolated MySQL test database.');
        }
        $collection = $this->collection();
        $id = (string) Str::uuid();
        $directory = sys_get_temp_dir().'/qr-resume-race-'.Str::uuid();
        File::makeDirectory($directory.'/photos/resumable', 0700, true);
        $source = UploadedFile::fake()->image('source.png', 400, 300);
        File::copy($source->getRealPath(), $directory.'/photos/resumable/'.$id.'.part');
        ResumableUpload::create(['id' => $id, 'upload_session_id' => $collection->id, 'secret_hash' => hash('sha256', str_repeat('a', 64)), 'name' => 'source.png', 'mime' => 'image/png', 'bytes' => $source->getSize(), 'offset' => $source->getSize(), 'sha256' => hash_file('sha256', $source->getRealPath()), 'expires_at' => now()->addDay()]);
        $database = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'PRIVATE_STORAGE_ROOT' => $directory.'/photos'];
        $script = <<<'PHP_SCRIPT'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[, $token, $id, $ready, $go]=$argv;
$request=Illuminate\Http\Request::create('/resume','POST');
$request->headers->set('X-Upload-Secret',str_repeat('a',64));
$request->setLaravelSession(new Illuminate\Session\Store('test',new Illuminate\Session\ArraySessionHandler(120)));
touch($ready); $deadline=microtime(true)+15;
while(!is_file($go)) { if(microtime(true)>$deadline) { exit(2); } usleep(10000); clearstatcache(true,$go); }
$response=app(App\Http\Controllers\ResumableUploadController::class)->finish($request,$token,$id,app(App\Services\PhotoUpload::class));
echo $response->getContent();
PHP_SCRIPT;
        $processes = [];
        try {
            foreach ([0, 1] as $index) {
                $processes[] = new Process([PHP_BINARY, '-r', $script, $collection->token, $id, $directory.'/ready-'.$index, $directory.'/go'], base_path(), $environment, null, 25);
            }
            DB::disconnect();
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Resumable workers failed to become ready.');
                } usleep(10000);
                clearstatcache();
            }
            touch($directory.'/go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(1, $result['data']['uploaded']);
            }
            DB::reconnect();
            $this->assertDatabaseCount('photos', 1);
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseCount('upload_receipts', 1);
            $this->assertFileDoesNotExist($directory.'/photos/resumable/'.$id.'.part');
            $this->assertCount(1, File::allFiles($directory.'/photos/photos'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            } File::deleteDirectory($directory);
            DB::reconnect();
        }
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
