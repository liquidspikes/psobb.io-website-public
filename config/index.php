<?php
http_response_code(403);
header('HTTP/1.1 403 Forbidden');
header('Content-Type: text/plain');
die('Forbidden: Direct access to this directory is not allowed.');
