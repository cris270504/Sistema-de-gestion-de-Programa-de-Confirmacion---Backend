<?php

namespace App\Tenancy\Facades;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void set(?int $parroquiaId)
 * @method static ?int parroquiaId()
 * @method static void markPrivileged(bool $privileged = true)
 * @method static bool isPrivileged()
 * @method static bool shouldScope()
 * @method static mixed runPrivileged(callable $callback)
 * @method static mixed runFor(?int $parroquiaId, callable $callback)
 *
 * @see TenantContext
 */
class Tenant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContext::class;
    }
}
