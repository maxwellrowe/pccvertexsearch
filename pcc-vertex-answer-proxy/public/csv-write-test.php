<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "This write test has been retired. Server-side filesystem storage is disabled.\n";
