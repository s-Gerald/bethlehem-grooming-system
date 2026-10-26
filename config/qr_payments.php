<?php

return [
    // LEGACY QR PAYMENT: TEMPORARILY DISABLED. Manual QR/OCR code is preserved.
    // Reactivation requires an intentional source change; database settings cannot enable it.
    // PayMongo Online Payment (including QR Ph) is separate and must remain active.
    'enabled' => false,
    'default_methods' => ['GCash', 'Maya', 'Bank Transfer'],
    'pdfinfo' => env('QR_PAYMENT_PDFINFO', 'pdfinfo'),
];
