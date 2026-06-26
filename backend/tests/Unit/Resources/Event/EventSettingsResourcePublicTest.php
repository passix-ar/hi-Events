<?php

namespace Tests\Unit\Resources\Event;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Resources\Event\EventSettingsResourcePublic;
use Illuminate\Http\Request;
use Tests\TestCase;

class EventSettingsResourcePublicTest extends TestCase
{
    private function createSettings(array $paymentProviders): EventSettingDomainObject
    {
        return (new EventSettingDomainObject())
            ->setPaymentProviders($paymentProviders);
    }

    public function test_stripe_is_hidden_when_no_secret_key_configured(): void
    {
        config(['services.stripe.secret_key' => null]);

        $settings = $this->createSettings([
            PaymentProviders::STRIPE->value,
            PaymentProviders::MERCADOPAGO->value,
            PaymentProviders::OFFLINE->value,
        ]);

        $resource = (new EventSettingsResourcePublic($settings))->toArray(Request::create('/'));

        $this->assertEqualsCanonicalizing(
            [PaymentProviders::MERCADOPAGO->value, PaymentProviders::OFFLINE->value],
            $resource['payment_providers'],
        );
    }

    public function test_stripe_is_kept_when_secret_key_configured(): void
    {
        config(['services.stripe.secret_key' => 'sk_test_123']);

        $settings = $this->createSettings([
            PaymentProviders::STRIPE->value,
            PaymentProviders::MERCADOPAGO->value,
        ]);

        $resource = (new EventSettingsResourcePublic($settings))->toArray(Request::create('/'));

        $this->assertContains(PaymentProviders::STRIPE->value, $resource['payment_providers']);
        $this->assertContains(PaymentProviders::MERCADOPAGO->value, $resource['payment_providers']);
    }

    public function test_handles_null_payment_providers(): void
    {
        config(['services.stripe.secret_key' => null]);

        $settings = $this->createSettings([]);
        $settings->setPaymentProviders(null);

        $resource = (new EventSettingsResourcePublic($settings))->toArray(Request::create('/'));

        $this->assertSame([], $resource['payment_providers']);
    }
}
