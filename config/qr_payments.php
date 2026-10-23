<?php

return [
    'default_methods' => ['GCash', 'Maya', 'Bank Transfer'],
    'pdfinfo' => env('QR_PAYMENT_PDFINFO', 'pdfinfo'),
];
