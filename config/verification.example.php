<?php
// SAMPLE ONLY. Before enabling, implement and review the actual provider adapter.
// A generic HMAC assertion is NOT evidence that a licensed provider completed identity checks.
// Copy to config/verification.php outside the public web root. Do not commit secrets.
return [
    'enabled' => false,
    'provider' => 'approved_provider',
    'hmac_secret_hex' => 'REPLACE_WITH_AT_LEAST_32_RANDOM_BYTES_AS_HEX',
];
