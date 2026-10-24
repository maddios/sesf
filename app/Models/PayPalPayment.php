<?php

namespace App\Models;

use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use Carbon\CarbonInterface;
use Database\Factories\PayPalPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw PayPal IPN post stored for one payment (legacy table paypal_payment). Ports
 * app/models/PayPalPayment.php.
 *
 * The row is the IPN as received. Columns are not normalised, so SET columns (payment_status,
 * pending_reason, payment_type, payer_status, address_status, paypal_verified) are plain strings,
 * and a SET value can hold several comma-separated members.
 *
 * Sensitive columns are in $hidden, so toArray() and toJson() never include them. They are:
 * payer and buyer identity (payer_email, first_name, last_name, username), the postal address,
 * verify_sign, the legacy PASSWORD column, entirepost (the full raw post body), and the seller side
 * receiver_email and custom (the custom field can carry internal references).
 *
 * Mass assignment: $guarded is ['*'], so nothing can be filled. Rows are written by the IPN
 * handler with forceFill() or an explicit query, after it has verified the post.
 *
 * registration_id was never set by the legacy code, so it is usually null. The relation is still
 * defined for the rows that have it.
 *
 * Money: the legacy columns are float. They are read through Money2, which rounds on decimal strings.
 *
 * @property int $id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $item_name
 * @property string|null $receiver_email
 * @property string $item_number
 * @property int $quantity
 * @property string $invoice
 * @property string|null $custom
 * @property string $payment_status
 * @property string $pending_reason
 * @property string|null $mc_gross
 * @property string|null $mc_fee
 * @property string $payment_type
 * @property string $payment_date
 * @property string $txn_id
 * @property string|null $payer_email
 * @property string $payer_status
 * @property string $txn_type
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $address_city
 * @property string|null $address_street
 * @property string|null $address_state
 * @property string|null $address_zip
 * @property string|null $address_country
 * @property string $address_status
 * @property string $subscr_date
 * @property string $period1
 * @property string $period2
 * @property string $period3
 * @property string|null $amount1
 * @property string|null $amount2
 * @property string|null $amount3
 * @property int $recurring
 * @property int $reattempt
 * @property string|null $retry_at
 * @property int $recur_times
 * @property string|null $username
 * @property string|null $PASSWORD
 * @property string|null $subscr_id
 * @property string|null $entirepost
 * @property string $paypal_verified
 * @property string|null $verify_sign
 * @property int|null $num_cart_items
 * @property int|null $registration_id
 */
class PayPalPayment extends Model
{
    /** @use HasFactory<PayPalPaymentFactory> */
    use HasFactory, HasLegacyDates;

    protected $table = 'paypal_payment';

    public $timestamps = true;

    protected $guarded = ['*'];

    protected $hidden = [
        'receiver_email',
        'custom',
        'payer_email',
        'first_name',
        'last_name',
        'address_street',
        'address_city',
        'address_state',
        'address_zip',
        'address_country',
        'username',
        'PASSWORD',
        'verify_sign',
        'entirepost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'mc_gross' => Money2::class,
            'mc_fee' => Money2::class,
            'amount1' => Money2::class,
            'amount2' => Money2::class,
            'amount3' => Money2::class,
            'recurring' => 'integer',
            'reattempt' => 'integer',
            'recur_times' => 'integer',
            'num_cart_items' => 'integer',
            'registration_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Payments PayPal reported as Completed.
     *
     * @param  Builder<PayPalPayment>  $query
     * @return Builder<PayPalPayment>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('payment_status', 'Completed');
    }

    /**
     * The payment(s) with this PayPal transaction id.
     *
     * @param  Builder<PayPalPayment>  $query
     * @return Builder<PayPalPayment>
     */
    public function scopeForTxn(Builder $query, string $txnId): Builder
    {
        return $query->where('txn_id', $txnId);
    }

    /**
     * @return BelongsTo<ShowRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ShowRegistration::class, 'registration_id');
    }
}
