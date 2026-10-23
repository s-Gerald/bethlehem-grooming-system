<?php

namespace App\Http\Controllers;

use App\Models\QrPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QrPaymentProofController extends Controller
{
    public function show(Request $request, int $id)
    {
        abort_unless($request->user()?->canAccessGrooming(), 403);
        $payment = QrPayment::findOrFail($id);
        abort_unless($payment->payable_type === (new \App\Models\Booking)->getMorphClass(), 403);
        abort_unless($payment->proof_path && str_starts_with($payment->proof_path, 'payment-proofs/')
            && ! str_contains($payment->proof_path, '..') && Storage::disk('local')->exists($payment->proof_path), 404);
        return response()->file(Storage::disk('local')->path($payment->proof_path), [
            'Content-Type' => $payment->proof_mime,
            'Content-Disposition' => 'attachment',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->setPrivate();
    }
}
