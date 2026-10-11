<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Puntjes\Generated\PuntjesClient;

/**
 * Facade over the configured Puntjes client.
 *
 *     Puntjes::rewards()->get()->wait()->getData();
 *     Puntjes::transactions()->post($body)->wait()->getData();
 *
 * The `@method` lines below are written by `scripts/facade-methods.php` from the core
 * client, so they follow each core release. Never edit them by hand.
 *
 * @method static \Puntjes\Generated\Campaigns\CampaignsRequestBuilder campaigns()
 * @method static \Puntjes\Generated\Customers\CustomersRequestBuilder customers()
 * @method static \Puntjes\Generated\Health\HealthRequestBuilder health()
 * @method static \Puntjes\Generated\Me\MeRequestBuilder me()
 * @method static \Puntjes\Generated\Products\ProductsRequestBuilder products()
 * @method static \Puntjes\Generated\Redemptions\RedemptionsRequestBuilder redemptions()
 * @method static \Puntjes\Generated\Rewards\RewardsRequestBuilder rewards()
 * @method static \Puntjes\Generated\Statistics\StatisticsRequestBuilder statistics()
 * @method static \Puntjes\Generated\Transactions\TransactionsRequestBuilder transactions()
 * @method static \Puntjes\Generated\Vouchers\VouchersRequestBuilder vouchers()
 *
 * @see PuntjesClient
 */
final class Puntjes extends Facade
{
    /** The underlying generated client. */
    public static function client(): PuntjesClient
    {
        /** @var PuntjesClient */
        return self::getFacadeApplication()->make(PuntjesClient::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return PuntjesClient::class;
    }
}
