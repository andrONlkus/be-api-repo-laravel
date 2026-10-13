<?php

namespace App\Services\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

trait UsesDmsConnection
{
    protected function db(): Connection
    {
        return DB::connection('dms');
    }
}
