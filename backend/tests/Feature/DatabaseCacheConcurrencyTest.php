<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DatabaseCacheConcurrencyTest extends TestCase
{
    public function test_database_cache_counters_remain_atomic_with_parallel_sqlite_writers(): void
    {
        $directory = sys_get_temp_dir().'/qr-cache-race-'.Str::uuid();
        File::makeDirectory($directory, 0700, true);
        $database = $directory.'/cache.sqlite';
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO cache VALUES ('counter', 'i:0;', 2147483647)");
        $pdo = null;
        $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[, $ready, $go] = $argv;
touch($ready);
$deadline = microtime(true) + 15;
while (!is_file($go)) { if (microtime(true) > $deadline) { exit(2); } usleep(10000); clearstatcache(true, $go); }
for ($i = 0; $i < 100; $i++) {
    if (Illuminate\Support\Facades\Cache::store('database')->increment('counter') === false) { exit(3); }
}
CODE;
        $processes = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $process = new Process([PHP_BINARY, '-r', $script, $directory.'/ready-'.$i, $directory.'/go'], base_path(), ['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_CACHE_CONNECTION' => 'sqlite', 'CACHE_PREFIX' => '', 'CACHE_STORE' => 'database'], null, 25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(File::glob($directory.'/ready-*')) !== 3) {
                if (microtime(true) > $deadline) {
                    $this->fail('Cache counter processes did not become ready.');
                }
                usleep(10000);
            }
            touch($directory.'/go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }
            $pdo = new PDO('sqlite:'.$database);
            $this->assertSame('i:300;', $pdo->query("SELECT value FROM cache WHERE key = 'counter'")->fetchColumn());
            $pdo = null;
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            File::deleteDirectory($directory);
        }
    }
}
