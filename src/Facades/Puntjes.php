<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Puntjes\Http\Response;
use Puntjes\Model\VendorBranding;
use Puntjes\Puntjes as Client;
use Puntjes\Resource\Campaigns;
use Puntjes\Resource\Customers;
use Puntjes\Resource\Products;
use Puntjes\Resource\Redemptions;
use Puntjes\Resource\Rewards;
use Puntjes\Resource\Statistics;
use Puntjes\Resource\Transactions;
use Puntjes\Resource\Vouchers;
use Puntjes\Resource\Wallets;

/**
 * Facade over the configured Puntjes client.
 *
 *     Puntjes::customers()->lookup(identifier: $card);
 *     Puntjes::transactions()->submit($transaction);
 *
 * The SDK exposes its endpoint groups as readonly PROPERTIES, which a facade cannot
 * proxy — `__callStatic` only forwards method calls. The accessors below bridge that,
 * so `Puntjes::customers()` reads naturally and keeps its return type for the IDE.
 *
 * @method static VendorBranding me()
 * @method static bool ping()
 * @method static Response request(string $method, string $path, array $query = [], ?array $body = null)
 *
 * @see Client
 */
final class Puntjes extends Facade
{
    public static function customers(): Customers
    {
        return self::client()->customers;
    }

    public static function transactions(): Transactions
    {
        return self::client()->transactions;
    }

    public static function wallets(): Wallets
    {
        return self::client()->wallets;
    }

    public static function rewards(): Rewards
    {
        return self::client()->rewards;
    }

    public static function redemptions(): Redemptions
    {
        return self::client()->redemptions;
    }

    public static function products(): Products
    {
        return self::client()->products;
    }

    public static function campaigns(): Campaigns
    {
        return self::client()->campaigns;
    }

    public static function statistics(): Statistics
    {
        return self::client()->statistics;
    }

    public static function vouchers(): Vouchers
    {
        return self::client()->vouchers;
    }

    /** The underlying SDK client, for anything the accessors above do not cover. */
    public static function client(): Client
    {
        /** @var Client */
        return self::getFacadeApplication()->make(Client::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
