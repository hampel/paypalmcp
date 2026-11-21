<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\PayPal\PayPalCallException;
use App\Support\PayPalAccount;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Hampel\PayPal\Api\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Show one subscription by its id - I- followed by letters and digits - with the payments it made. Two requests, plus a
    token if none is cached.

    `subscription` is PayPal's record: `status` (ACTIVE, SUSPENDED, CANCELLED, EXPIRED, APPROVAL_PENDING), `billing_info`
    with `next_billing_time`, `last_payment` and `failed_payments_count`, `plan_id`, `custom_id` (the seller's own
    reference) and the subscriber's name and email. A subscription made by a PayPal Standard button has no plan_id and
    no custom_id, and its amounts come back with one decimal place.

    `payments` are its charges over the last `months` (default 12): each with its transaction id, which is the same id
    the T0002 row has in transaction search, and its fee POSITIVE, where transaction search writes it negative.

    A T0002 row's `reference_id` is its subscription id.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class GetSubscription extends PayPalTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + [
            'subscription_id' => $schema->string()->description('The subscription id, I-...')->required(),
            'months' => $schema->integer()->min(1)->max(36)->default(12)->description('How many months of payments to list, 1 to 36. Default 12.'),
        ];
    }

    protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array
    {
        $id = strtoupper($this->text($request->get('subscription_id'), 'subscription_id') ?? '');

        if (preg_match('/^I-[A-Z0-9]+$/', $id) !== 1) {
            throw new PayPalCallException('`subscription_id` must be a PayPal subscription id: I- followed by letters and digits.');
        }

        $months = $this->whole($request->get('months'), 'months', 1, 36, 12);
        $to = CarbonImmutable::now('UTC');
        $from = $to->modify(sprintf('-%d months', $months));

        [$subscription, $payments] = $gateway->call($account, 2, static function (Client $paypal) use ($id, $from, $to): array {
            $subscription = $paypal->subscriptions()->find($id);

            return [$subscription, $subscription === null ? [] : $paypal->subscriptions()->transactions($id, $from, $to)];
        });

        if ($subscription === null) {
            throw new PayPalCallException(sprintf('"%s" has no subscription %s. A subscription belongs to the account that sold it; try the others in list-accounts.', $account->key, $id));
        }

        return [
            'subscription' => Present::subscription($subscription),
            'payments_from' => Present::time($from),
            'payments' => array_map(Present::subscriptionPayment(...), $payments),
            'dates' => Present::dateNote(),
        ];
    }
}
