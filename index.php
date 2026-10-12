<?php
// The Solo Parent ID Online Services portal is the only dashboard.
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
redirect(base_url('portal.php'));
