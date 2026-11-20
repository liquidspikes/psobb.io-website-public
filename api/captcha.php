<?php
require_once __DIR__ . '/config.php';
start_secure_session();

// Clean buffer before emitting image bytes
if (ob_get_length()) {
    ob_clean();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$width = 120;
$height = 40;
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No I, 1, O, 0
$maxCharIdx = strlen($chars) - 1;
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $chars[random_int(0, $maxCharIdx)];
}
$_SESSION['captcha'] = $code;

if (function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/png');
    $im = imagecreatetruecolor($width, $height);
    $bg = imagecolorallocate($im, 20, 20, 20);
    $text_color = imagecolorallocate($im, 255, 255, 255);
    $line_color = imagecolorallocate($im, 64, 64, 64);

    imagefilledrectangle($im, 0, 0, $width, $height, $bg);
    for ($i = 0; $i < 5; $i++) {
        imageline($im, 0, random_int(0, $height - 1), $width, random_int(0, $height - 1), $line_color);
    }

    $font = 5;
    $x = 20;
    $y = 12;
    for ($i = 0; $i < 5; $i++) {
        imagestring($im, $font, $x + ($i * 15), $y + random_int(-5, 5), $code[$i], $text_color);
    }

    imagepng($im);
    imagedestroy($im);
    exit;
}

// Pure-PHP SVG fallback when php-gd is unavailable
header('Content-Type: image/svg+xml; charset=utf-8');
$svgLines = [];
for ($i = 0; $i < 5; $i++) {
    $y1 = random_int(0, $height);
    $y2 = random_int(0, $height);
    $svgLines[] = "<line x1=\"0\" y1=\"{$y1}\" x2=\"{$width}\" y2=\"{$y2}\" stroke=\"#404040\" stroke-width=\"1\"/>";
}
$svgChars = [];
for ($i = 0; $i < 5; $i++) {
    $cx = 18 + ($i * 20);
    $cy = 27 + random_int(-4, 4);
    $rot = random_int(-12, 12);
    $ch = htmlspecialchars($code[$i], ENT_QUOTES, 'UTF-8');
    $svgChars[] = "<text x=\"{$cx}\" y=\"{$cy}\" fill=\"#ffffff\" font-family=\"monospace\" font-size=\"22\" font-weight=\"bold\" transform=\"rotate({$rot} {$cx} {$cy})\">{$ch}</text>";
}
echo '<?xml version="1.0" encoding="UTF-8"?>'
    . "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\" viewBox=\"0 0 {$width} {$height}\">"
    . "<rect width=\"{$width}\" height=\"{$height}\" fill=\"#141414\"/>"
    . implode('', $svgLines)
    . implode('', $svgChars)
    . '</svg>';
?>
