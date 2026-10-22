<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentAmountValidationTest extends TestCase
{
    #[DataProvider('manualOnlineMethods')]
    public function test_new_grooming_payments_reject_manual_online_methods(
        string $action,
        string $method,
    ): void {
        $request = Request::create('/api/admin/bookings/1/'.$action, 'POST', [
            'final_price' => 500,
            'amount_paid' => 500,
            'payment_method' => $method,
        ]);

        try {
            $controllerMethod = $action === 'pay' ? 'store' : 'payNow';
            (new PaymentController)->{$controllerMethod}($request, 1);
            $this->fail('The payment method should have failed validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_method', $exception->errors());
        }
    }

    public static function manualOnlineMethods(): array
    {
        $cases = [];
        foreach (['pay', 'pay-now'] as $action) {
            foreach (['online', 'qrph', 'gcash', 'maya', 'card', 'others'] as $method) {
                $cases["{$action} {$method}"] = [$action, $method];
            }
        }

        return $cases;
    }

    #[DataProvider('paymentsAboveMaximum')]
    public function test_it_rejects_a_payment_above_the_total_based_maximum(
        float $totalDue,
        float $amountPaid,
        string $expectedMessage,
    ): void {
        $request = Request::create('/api/admin/bookings/1/pay', 'POST', [
            'final_price' => $totalDue,
            'amount_paid' => $amountPaid,
        ]);

        try {
            (new PaymentController)->store($request, 1);
            $this->fail('The payment amount should have failed validation.');
        } catch (ValidationException $exception) {
            $this->assertSame($expectedMessage, $exception->errors()['amount_paid'][0]);
        }
    }

    public static function paymentsAboveMaximum(): array
    {
        return [
            '₱1,600 total' => [1600.0, 3000.01, 'Amount paid cannot exceed ₱3,000.00.'],
            '₱12,755 total' => [12755.0, 14000.01, 'Amount paid cannot exceed ₱14,000.00.'],
        ];
    }
}
