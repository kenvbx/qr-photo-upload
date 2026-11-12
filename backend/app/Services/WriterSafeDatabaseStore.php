<?php

namespace App\Services;

use Closure;
use Illuminate\Cache\DatabaseStore;

class WriterSafeDatabaseStore extends DatabaseStore
{
    protected function incrementOrDecrement($key, $value, Closure $callback): int|false
    {
        if ($this->connection->getDriverName() !== 'sqlite') {
            return parent::incrementOrDecrement($key, $value, $callback);
        }

        // PHP 8.3 ignores SQLite transaction_mode. Reserve the writer before reading a counter.
        return $this->connection->transaction(function () use ($key, $value, $callback) {
            $this->table()->where('key', $this->prefix.$key)->update(['value' => $this->connection->raw('value')]);

            return parent::incrementOrDecrement($key, $value, $callback);
        });
    }
}
