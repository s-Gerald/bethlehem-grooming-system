<?php

namespace App\Http\Controllers;

use App\Models\QrPaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class QrPaymentSettingController extends Controller
{
    public function show()
    {
        return response()->json(['settings' => QrPaymentSettings::findOrFail(1)->configuration(true)]);
    }

    public function configuration()
    {
        return response()->json(['settings' => QrPaymentSettings::findOrFail(1)->configuration()]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
            'merchant_name' => 'nullable|required_if:enabled,1,true|string|max:150',
            'provider_name' => 'nullable|required_if:enabled,1,true|string|max:100',
            'image' => 'nullable|file|image|mimes:png,jpg,jpeg,webp|mimetypes:image/png,image/jpeg,image/webp|max:3072|dimensions:min_width=128,min_height=128,max_width=4096,max_height=4096',
            'official_qr_confirmed' => 'exclude_without:image|required|accepted',
        ]);
        $newPath = null;
        if ($request->hasFile('image')) {
            $newPath = $request->file('image')->store('qr-payments', 'local');
            abort_unless($newPath, 503, 'Could not save the QR image. Please retry.');
        }
        try {
            [$settings, $oldPath] = DB::transaction(function () use ($request, $data, $newPath) {
                $settings = QrPaymentSettings::whereKey(1)->lockForUpdate()->firstOrFail();
                abort_if($settings->version !== (int) $data['version'], 409, 'QR settings changed. Reload the tab before saving.');
                $oldPath = $settings->image_path;
                $settings->fill([
                    'enabled' => $data['enabled'],
                    'merchant_name' => trim($data['merchant_name'] ?? '') ?: null,
                    'provider_name' => trim($data['provider_name'] ?? '') ?: null,
                    'updated_by' => $request->user()->user_id,
                    'version' => $settings->version + 1,
                ]);
                if ($newPath) {
                    $settings->image_path = $newPath;
                    $settings->image_hash = hash_file('sha256', $request->file('image')->getRealPath());
                }
                if ($settings->enabled && ! $settings->available()) {
                    throw ValidationException::withMessages(['image' => 'Add an official QR image, merchant name and receiving provider before enabling QR Ph.']);
                }
                $settings->save();
                return [$settings, $oldPath];
            });
        } catch (\Throwable $error) {
            if ($newPath) Storage::disk('local')->delete($newPath);
            throw $error;
        }
        if ($newPath && $oldPath) Storage::disk('local')->delete($oldPath);
        return response()->json(['success' => true, 'message' => 'QR payment settings saved.', 'settings' => $settings->configuration(true)]);
    }

    public function image(Request $request)
    {
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
