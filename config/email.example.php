<?php
// Optional shared-host PHP mail() transport. Copy to config/email.php (PRIVATE).
// The account confirmation feature stays disabled without enabled=true.
// This is NOT age/identity verification, adult-content approval or SMTP validation.
return [
    'enabled' => false,
    'base_url' => 'https://members.example.com',  // Fixed HTTPS origin, never HTTP_HOST
    'from' => 'accounts@example.com',
];
