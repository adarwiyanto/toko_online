<?php
require_once __DIR__ . '/config.local.php';

echo '<pre>';
echo "getenv:\n"; var_dump(getenv('HOPE_UPLOAD_BASE'));
echo "CONST:\n"; var_dump(HOPE_UPLOAD_BASE);
echo "Exists:\n"; var_dump(is_dir(HOPE_UPLOAD_BASE));
echo "Writable:\n"; var_dump(is_writable(HOPE_UPLOAD_BASE));
echo '</pre>';
