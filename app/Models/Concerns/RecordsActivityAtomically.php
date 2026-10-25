<?php

namespace App\Models\Concerns;

/** Keep a standalone model write and its synchronous activity observer atomic. */
trait RecordsActivityAtomically
{
    public function save(array $options = [])
    {
        if ($this->getConnection()->transactionLevel() || ! app(\App\Services\ActivityRecorder::class)->available()) {
            return parent::save($options);
        }
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    protected function increment($column, $amount = 1, array $extra = [])
    {
        if ($this->getConnection()->transactionLevel() || ! app(\App\Services\ActivityRecorder::class)->available()) {
            return parent::increment($column, $amount, $extra);
        }
        return $this->getConnection()->transaction(fn () => parent::increment($column, $amount, $extra));
    }

    protected function decrement($column, $amount = 1, array $extra = [])
    {
        if ($this->getConnection()->transactionLevel() || ! app(\App\Services\ActivityRecorder::class)->available()) {
            return parent::decrement($column, $amount, $extra);
        }
        return $this->getConnection()->transaction(fn () => parent::decrement($column, $amount, $extra));
    }
}
