<?php
// Front controller: delegate all requests to users_table.php for routing.
// This ensures /api/* routes are handled by users_table.php and the UI is served for non-API requests.
require __DIR__ . '/users_table.php';
exit;