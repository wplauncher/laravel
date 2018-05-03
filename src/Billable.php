<?php

namespace Laravel\Cashier;

use Exception;
use Carbon\Carbon;
use InvalidArgumentException;
use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Illuminate\Support\Collection;
use Stripe\Charge as StripeCharge;
use Stripe\Refund as StripeRefund;
use Stripe\Invoice as StripeInvoice;
use Stripe\Customer as StripeCustomer;
use Stripe\BankAccount as StripeBankAccount;
use Stripe\InvoiceItem as StripeInvoiceItem;
use Stripe\Error\InvalidRequest as StripeErrorInvalidRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait Billable
{
    /**
     * The Stripe API key.
     *
     * @var string
     */
    protected static $stripeKey;

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int  $amount
     * @param  array  $options
     * @return \Stripe\Charge
     *
     * @throws \InvalidArgumentException
     */
    public function charge($amount, array $options = [])
    {
        $options = array_merge([
            'currency' => $this->preferredCurrency(),
        ], $options);

        $options['amount'] = $amount;
        //change field name stripe_id -> UsersStripeId
        if (! array_key_exists('source', $options) && $this->UsersStripeId) {
            //change field name stripe_id -> UsersStripeId
            $options['customer'] = $this->UsersStripeId;
        }

        if (! array_key_exists('source', $options) && ! array_key_exists('customer', $options)) {
            throw new InvalidArgumentException('No payment source provided.');
        }

        return StripeCharge::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Refund a customer for a charge.
     *
     * @param  string  $charge
     * @param  array  $options
     * @return \Stripe\Charge
     *
     * @throws \InvalidArgumentException
     */
    public function refund($charge, array $options = [])
    {
        $options['charge'] = $charge;

        return StripeRefund::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Determines if the customer currently has a card on file.
     *
     * @return bool
     */
    public function hasCardOnFile()
    {
        ////change field name card_brand -> UsersCardBrand
        return (bool) $this->UsersCardBrand;
    }

    /**
     * Add an invoice item to the customer's upcoming invoice.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return \Stripe\InvoiceItem
     *
     * @throws \InvalidArgumentException
     */
    public function tab($description, $amount, array $options = [])
    {
        //change field name stripe_id -> UsersStripeId
        if (! $this->UsersStripeId) {
            throw new InvalidArgumentException(class_basename($this).' is not a Stripe customer. See the createAsStripeCustomer method.');
        }
        //change field name stripe_id -> UsersStripeId
        $options = array_merge([
            'customer' => $this->UsersStripeId,
            'amount' => $amount,
            'currency' => $this->preferredCurrency(),
            'description' => $description,
        ], $options);

        return StripeInvoiceItem::create(
            $options, ['api_key' => $this->getStripeKey()]
        );
    }

    /**
     * Invoice the customer for the given amount and generate an invoice immediately.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return \Laravel\Cashier\Invoice|bool
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        $this->tab($description, $amount, $options);

        return $this->invoice();
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the Stripe model is on trial.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }
        //change field name stripe_plan -> SubscriptionsStripePlan
        return $subscription && $subscription->onTrial() &&
               $subscription->SubscriptionsStripePlan === $plan;
    }

    /**
     * Determine if the Stripe model is on a "generic" trial at the model level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        //change field name trial_ends_at -> SubscriptionsTrialEndsAt
        return $this->SubscriptionsTrialEndsAt && Carbon::now()->lt($this->SubscriptionsTrialEndsAt);
    }

    /**
     * Determine if the Stripe model has a given subscription.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }
        //change field name stripe_plan -> SubscriptionsStripePlan
        return $subscription->valid() &&
               $subscription->SubscriptionsStripePlan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string  $subscription
     * @return \Laravel\Cashier\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        //change field name created_at -> SubscriptionsCreatedAt
        return $this->subscriptions->sortByDesc(function ($value) {
            return $value->SubscriptionsCreatedAt->getTimestamp();
        })
        ->first(function ($value) use ($subscription) {
            return $value->name === $subscription;
        });
    }

    /**
     * Get all of the subscriptions for the Stripe model.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        //change field name created_at -> SubscriptionsCreatedAt
        return $this->hasMany(Subscription::class, $this->getForeignKey())->orderBy('SubscriptionsCreatedAt', 'desc');
    }

    /**
     * Invoice the billable entity outside of regular billing cycle.
     *
     * @return \Stripe\Invoice|bool
     */
    public function invoice()
    {
        //change field name stripe_id -> UsersStripeId
        if ($this->UsersStripeId) {
            try {
                return StripeInvoice::create(['customer' => $this->UsersStripeId], $this->getStripeKey())->pay();
            } catch (StripeErrorInvalidRequest $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the entity's upcoming invoice.
     *
     * @return \Laravel\Cashier\Invoice|null
     */
    public function upcomingInvoice()
    {
        try {
            //change field name stripe_id -> UsersStripeId
            $stripeInvoice = StripeInvoice::upcoming(
                ['customer' => $this->UsersStripeId], ['api_key' => $this->getStripeKey()]
            );

            return new Invoice($this, $stripeInvoice);
        } catch (StripeErrorInvalidRequest $e) {
            //
        }
    }

    /**
     * Find an invoice by ID.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice|null
     */
    public function findInvoice($id)
    {
        try {
            return new Invoice($this, StripeInvoice::retrieve($id, $this->getStripeKey()));
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException;
        }
        //change field name stripe_id -> UsersStripeId
        if ($invoice->customer !== $this->UsersStripeId) {
            throw new AccessDeniedHttpException;
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param  string  $id
     * @param  array  $data
     * @param  string  $storagePath
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
        return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param  bool  $includePending
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = [];

        $parameters = array_merge(['limit' => 24], $parameters);

        $stripeInvoices = $this->asStripeCustomer()->invoices($parameters);

        // Here we will loop through the Stripe invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Stripe objects are. Then, we'll return the array.
        if (! is_null($stripeInvoices)) {
            foreach ($stripeInvoices->data as $invoice) {
                if ($invoice->paid || $includePending) {
                    $invoices[] = new Invoice($this, $invoice);
                }
            }
        }

        return new Collection($invoices);
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Get a collection of the entity's cards.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function cards($parameters = [])
    {
        $cards = [];

        $parameters = array_merge(['limit' => 24], $parameters);

        $stripeCards = $this->asStripeCustomer()->sources->all(
            ['object' => 'card'] + $parameters
        );

        if (! is_null($stripeCards)) {
            foreach ($stripeCards->data as $card) {
                $cards[] = new Card($this, $card);
            }
        }

        return new Collection($cards);
    }

    /**
     * Get the default card for the entity.
     *
     * @return \Stripe\Card|null
     */
    public function defaultCard()
    {
        $customer = $this->asStripeCustomer();

        foreach ($customer->sources->data as $card) {
            if ($card->id === $customer->default_source) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Update customer's credit card.
     *
     * @param  string  $token
     * @return void
     */
    public function updateCard($token)
    {
        $customer = $this->asStripeCustomer();

        $token = StripeToken::retrieve($token, ['api_key' => $this->getStripeKey()]);

        // If the given token already has the card as their default source, we can just
        // bail out of the method now. We don't need to keep adding the same card to
        // a model's account every time we go through this particular method call.
        if ($token[$token->type]->id === $customer->default_source) {
            return;
        }

        $card = $customer->sources->create(['source' => $token]);

        $customer->default_source = $card->id;

        $customer->save();

        // Next we will get the default source for this model so we can update the last
        // four digits and the card brand on the record in the database. This allows
        // us to display the information on the front-end when updating the cards.
        $source = $customer->default_source
                    ? $customer->sources->retrieve($customer->default_source)
                    : null;

        $this->fillCardDetails($source);

        $this->save();
    }

    /**
     * Synchronises the customer's card from Stripe back into the database.
     *
     * @return $this
     */
    public function updateCardFromStripe()
    {
        $defaultCard = $this->defaultCard();
        //change field name card_brand -> UsersCardBrand
        //change field name card_last_four -> UsersCardLastFour
        if ($defaultCard) {
            $this->fillCardDetails($defaultCard)->save();
        } else {
            $this->forceFill([
                'UsersCardBrand' => null,
                'UsersCardLastFour' => null,
            ])->save();
        }

        return $this;
    }

    /**
     * Fills the model's properties with the source from Stripe.
     *
     * @param  \Stripe\Card|\Stripe\BankAccount|null  $card
     * @return $this
     */
    protected function fillCardDetails($card)
    {
        //change field name card_brand -> UsersCardBrand
        //change field name card_last_four -> UsersCardLastFour
        if ($card instanceof StripeCard) {
            $this->UsersCardBrand = $card->brand;
            $this->UsersCardLastFour = $card->last4;
        } elseif ($card instanceof StripeBankAccount) {
            $this->UsersCardBrand = 'Bank Account';
            $this->UsersCardLastFour = $card->last4;
        }

        return $this;
    }

    /**
     * Deletes the entity's cards.
     *
     * @return void
     */
    public function deleteCards()
    {
        $this->cards()->each(function ($card) {
            $card->delete();
        });

        $this->updateCardFromStripe();
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param  string  $coupon
     * @return void
     */
    public function applyCoupon($coupon)
    {
        $customer = $this->asStripeCustomer();

        $customer->coupon = $coupon;

        $customer->save();
    }

    /**
     * Determine if the Stripe model is actively subscribed to one of the given plans.
     *
     * @param  array|string  $plans
     * @param  string  $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }
        //change field name stripe_plan -> SubscriptionsStripePlan
        foreach ((array) $plans as $plan) {
            if ($subscription->SubscriptionsStripePlan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        //change field name stripe_plan -> SubscriptionsStripePlan
        return ! is_null($this->subscriptions->first(function ($value) use ($plan) {
            return $value->SubscriptionsStripePlan === $plan && $value->valid();
        }));
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasStripeId()
    {
        //change field name stripe_id -> UsersStripeId - this however may be referring to both subscriptions and customers so this may need to be changed
        return ! is_null($this->UsersStripeId);
    }

    /**
     * Create a Stripe customer for the given Stripe model.
     *
     * @param  string  $token
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function createAsStripeCustomer($token, array $options = [])
    {
        //change field name email -> UsersEmail
        $options = array_key_exists('email', $options)
                ? $options : array_merge($options, ['email' => $this->UsersEmail]);

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $customer = StripeCustomer::create(
            $options, $this->getStripeKey()
        );
        //change field name stripe_id -> UsersStripeId
        $this->UsersStripeId = $customer->id;

        $this->save();

        // Next we will add the credit card to the user's account on Stripe using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (! is_null($token)) {
            $this->updateCard($token);
        }

        return $customer;
    }

    /**
     * Get the Stripe customer for the Stripe model.
     *
     * @return \Stripe\Customer
     */
    public function asStripeCustomer()
    {
        //change field name stripe_id -> UsersStripeId
        return StripeCustomer::retrieve($this->UsersStripeId, $this->getStripeKey());
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    public function preferredCurrency()
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Get the Stripe API key.
     *
     * @return string
     */
    public static function getStripeKey()
    {
        if (static::$stripeKey) {
            return static::$stripeKey;
        }

        if ($key = getenv('STRIPE_SECRET')) {
            return $key;
        }

        return config('services.stripe.secret');
    }

    /**
     * Set the Stripe API key.
     *
     * @param  string  $key
     * @return void
     */
    public static function setStripeKey($key)
    {
        static::$stripeKey = $key;
    }
}
