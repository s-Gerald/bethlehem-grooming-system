<?php

namespace App\Http\Controllers;

use App\Services\CustomerPhoneVerificationService;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    public function send(Request $request, CustomerPhoneVerificationService $verification)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'current_password' => ['nullable', 'string', 'max:255']]);
        $phone = preg_replace('/[\s()-]+/', '', $data['phone']);
        if (str_starts_with($phone, '+63')) {
            $phone = '0'.substr($phone, 3);
        }
        validator(['phone' => $phone], ['phone' => ['required', 'regex:/^09[0-9]{9}$/D']])->validate();

        return response()->json($verification->send($request->user(), $phone, $request->ip(), null, $data['current_password'] ?? null));
    }

    public function state(Request $request, CustomerPhoneVerificationService $verification)
    {
        return response()->json($verification->state($request->user()));
    }

    public function cancel(Request $request, CustomerPhoneVerificationService $verification)
    {
        $data = $request->validate(['challenge_id' => ['required', 'uuid']]);

        return response()->json($verification->cancel($request->user(), $data['challenge_id']));
    }

    public function resend(Request $request, CustomerPhoneVerificationService $verification)
    {
        $data = $request->validate(['challenge_id' => ['required', 'uuid']]);

        return response()->json($verification->resend($request->user(), $data['challenge_id'], $request->ip()));
    }

    public function verify(Request $request, CustomerPhoneVerificationService $verification)
    {
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D']]);

        return response()->json($verification->verify($request->user(), $data['challenge_id'], $data['code']));
    }
}
