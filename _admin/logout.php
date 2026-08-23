<?php
$cfg = require __DIR__ . '/../_app/config.php';
require __DIR__ . '/../_app/auth.php';
start_session($cfg);
logout();
portal_redirect('/_admin/');
