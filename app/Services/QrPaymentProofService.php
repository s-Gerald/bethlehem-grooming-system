<?php

namespace App\Services;

use App\Models\QrPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class QrPaymentProofService
{
    /** Validate and write before acquiring any financial database locks. */
    public function store(?UploadedFile $file): array
    {
        abort_unless(config('qr_payments.enabled', false), 404);
        if (! $file) return [];
        $mime = $file->getMimeType();
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (! $file->isValid() || ! isset($extensions[$mime]) || $file->getSize() > 5 * 1024 * 1024) {
            $this->invalid('Select a PNG, JPEG, WebP or PDF receipt up to 5 MB.');
        }
        if ($mime === 'application/pdf') {
            // A real parser enforces limits, including compressed PDF page trees.
            // Never invoke a shell with an uploaded filename.
            $process = new Process([config('qr_payments.pdfinfo'), $file->getRealPath()]);
            $process->setTimeout(5);
            try { $process->run(); }
            catch (\Throwable) { $this->invalid('PDF validation is unavailable. Upload an image receipt instead.'); }
            $output = $process->getOutput();
            if (! $process->isSuccessful() || ! preg_match('/^Pages:\s+(\d+)\s*$/m', $output, $pages)
                || (int) $pages[1] < 1 || (int) $pages[1] > 2 || preg_match('/^Encrypted:\s+yes/im', $output)) {
                $this->invalid('Use an unencrypted PDF with no more than 2 pages.');
            }
        } else {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions['mime'] !== $mime || $dimensions[0] > 16000 || $dimensions[1] > 16000
                || $dimensions[0] * $dimensions[1] > 16000000) {
                $this->invalid('Use a receipt image of at most 16 megapixels and 16,000 pixels per side.');
            }
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $file->getClientOriginalName())), 0, 140);
        $path = $file->storeAs('payment-proofs/'.now()->format('Y/m'), bin2hex(random_bytes(20)).'.'.$extensions[$mime], 'local');
        abort_unless($path, 503, 'Could not save payment proof. Payment was not recorded. Retry or remove the receipt.');
        return ['proof_path' => $path, 'proof_name' => $name ?: 'Payment proof.'.$extensions[$mime],
            'proof_mime' => $mime, 'proof_size' => $file->getSize(), 'proof_hash' => hash_file('sha256', $file->getRealPath())];
    }

    public function discard(array $proof): void
    {
        if (isset($proof['proof_path'])) Storage::disk('local')->delete($proof['proof_path']);
    }

    /** Retain recorded proofs indefinitely; prune only abandoned uploads older than 24 hours. */
    public function pruneOrphans(): int
    {
        if (! config('qr_payments.enabled', false)) return 0;
        $disk = Storage::disk('local');
        $removed = 0;
        foreach (array_chunk($disk->allFiles('payment-proofs'), 200) as $paths) {
            $recorded = QrPayment::whereIn('proof_path', $paths)->pluck('proof_path')->flip();
            foreach ($paths as $path) {
                if (! isset($recorded[$path]) && $disk->lastModified($path) < now()->subDay()->timestamp) {
                    if ($disk->delete($path)) $removed++;
                }
            }
        }
        return $removed;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['receipt' => $message]);
    }
}
