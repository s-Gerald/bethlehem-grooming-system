<?php

return [
    // BSP/BancNet QR Ph sender/receiver list, 30 September 2026.
    // Receiving institution identity does not guarantee support for every QR type.
    'providers' => [
        'gcash' => 'GCash', 'maya' => 'Maya', 'bpi' => 'BPI', 'bdo' => 'BDO',
        'metrobank' => 'Metrobank', 'rcbc' => 'RCBC', 'unionbank' => 'UnionBank',
        'landbank' => 'LandBank', 'pnb' => 'PNB', 'security_bank' => 'Security Bank', 'aub' => 'AUB',
    ],
    'pdfinfo' => env('QR_PAYMENT_PDFINFO', 'pdfinfo'),
];
