<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Aplikasi pemanggil API arsip dengan token Sanctum.
 * Dibuat lewat: php artisan arsip:client-create {name}
 */
class ApiClient extends Model
{
    use HasApiTokens;

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
