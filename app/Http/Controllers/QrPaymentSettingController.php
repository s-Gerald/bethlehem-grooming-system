<?php

namespace App\Http\Controllers;

use App\Models\QrPaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Legacy manual QR endpoints are preserved; config/qr_payments.php controls access.
class QrPaymentSettingController extends Controller
{
    public function show()
    {
        abort_unless(config('qr_payments.enabled', false), 404);
        return response()->json(['settings' => QrPaymentSettings::findOrFail(1)->configuration(true)]);
    }

    public function configuration()
    {
        abort_unless(config('qr_payments.enabled', false), 404);
        return response()->json(['settings' => QrPaymentSettings::findOrFail(1)->configuration()]);
    }

    public function save(Request $request)
    {
        abort_unless(config('qr_payments.enabled', false), 404);
        // Multipart JSON preserves an explicitly empty selection.
        if (is_string($request->input('accepted_methods'))) {
            $request->merge(['accepted_methods' => json_decode($request->input('accepted_methods'), true)]);
        }
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
            'merchant_name' => 'nullable|string|max:150',
            'accepted_methods' => 'present|array|list|max:20',
            'accepted_methods.*' => ['bail', 'required', 'string', 'max:100', function ($attribute, $value, $fail) {
                if (in_array(mb_strtolower(trim($value)), ['cash', 'qrph', 'other', 'qr payment'], true)) {
                    $fail('Enter a custom payment method name.');
                }
            }],
            'image' => 'nullable|file|image|mimes:png,jpg,jpeg,webp|mimetypes:image/png,image/jpeg,image/webp|max:3072|dimensions:min_width=128,min_height=128,max_width=4096,max_height=4096',
            'official_qr_confirmed' => 'exclude_without:image|required|accepted',
        ]);
        $methods = array_map('trim', $data['accepted_methods']);
        $keys = array_map('mb_strtolower', $methods);
        if (in_array('', $methods, true) || count(array_unique($keys)) !== count($keys)) {
            throw ValidationException::withMessages(['accepted_methods' => 'Use unique, non-empty payment method names.']);
        }
        foreach ($methods as &$method) {
            foreach (config('qr_payments.default_methods') as $builtIn) {
                if (strcasecmp($method, $builtIn) === 0) $method = $builtIn;
            }
        }
        unset($method);
        $newPath = null;
        if ($request->hasFile('image')) {
            $newPath = $request->file('image')->store('qr-payments', 'local');
            abort_unless($newPath, 503, 'Could not save the QR image. Please retry.');
        }
        try {
            [$settings, $oldPath] = DB::transaction(function () use ($request, $data, $methods, $newPath) {
                $settings = QrPaymentSettings::whereKey(1)->lockForUpdate()->firstOrFail();
                abort_if($settings->version !== (int) $data['version'], 409, 'QR settings changed. Reload the tab before saving.');
                $oldPath = $settings->image_path;
                $settings->fill([
                    'enabled' => $data['enabled'],
                    'merchant_name' => trim($data['merchant_name'] ?? '') ?: null,
                    'accepted_methods' => $methods,
                    'updated_by' => $request->user()->user_id,
                    'version' => $settings->version + 1,
                ]);
                if ($newPath) {
                    $settings->image_path = $newPath;
                    $settings->image_hash = hash_file('sha256', $request->file('image')->getRealPath());
                }
                if ($settings->enabled && ! $settings->available()) {
                    $messages = ['image' => 'Add a QR Payment Image.', 'merchant_name' => 'Enter the receiving merchant/account name.',
                        'accepted_methods' => 'Select at least one payment method.'];
                    throw ValidationException::withMessages(array_intersect_key($messages, array_flip($settings->missingFields())));
                }
                $settings->save();
                return [$settings, $oldPath];
            });
        } catch (\Throwable $error) {
            if ($newPath) Storage::disk('local')->delete($newPath);
            throw $error;
        }
        if ($newPath && $oldPath) Storage::disk('local')->delete($oldPath);
        $configuration = $settings->configuration(true);
        $message = match ($configuration['status']) {
            'enabled' => 'QR Payments enabled', 'disabled' => 'Saved. QR Payments is disabled.', default => 'Saved. QR configuration is not ready.',
        };
        return response()->json(['success' => true, 'message' => $message, 'settings' => $configuration]);
    }

    public function image(Request $request)
    {
        abort_unless(config('qr_payments.enabled', false), 404);
        $settings = QrPaymentSettings::findOrFail(1);
        abort_unless($request->user()->role === 'admin' || $settings->available(), 404);
        abort_unless($settings->image_path && Storage::disk('local')->exists($settings->image_path), 404);
        abort_if($request->has('version') && (string) $request->query('version') !== (string) $settings->version,
            409, 'QR settings changed. Reopen the payment to load the current QR.');
        return response()->file(Storage::disk('local')->path($settings->image_path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ])->setPrivate();
    }
}
