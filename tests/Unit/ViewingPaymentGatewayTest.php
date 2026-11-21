<?php

use Liberu\RealEstate\Viewings\Domain\Payments\NullPaymentGateway;
use Liberu\RealEstate\Viewings\Domain\Payments\PaymentGateway;
use Liberu\RealEstate\Viewings\Domain\Payments\PaymentRequest;
use Liberu\RealEstate\Viewings\Domain\Payments\PaymentStatus;

it('leaves viewing payments pending when no payment gateway is configured', function () {
    $gateway = app(PaymentGateway::class);
    $request = new PaymentRequest(2500, 'GBP', 'Property viewing');

    expect($gateway)->toBeInstanceOf(NullPaymentGateway::class)
        ->and($request->metadata)->toBe([]);

    $result = $gateway->charge($request);

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->providerReference)->toBeNull()
        ->and($result->message)->toContain('No payment gateway is configured');
});
