<?php

use Carbon\CarbonImmutable;

// Fixture/clock actions only; editorial acceptance writes exclusively use HTTP.
$app = require __DIR__.'/phase-zero-bootstrap.php';
$action = $argv[1] ?? '';
$storage = getenv('PHASE0_STORAGE');
if ($action === 'image') {
    $image = imagecreatetruecolor(1600, 900);
    for ($y = 0; $y < 900; $y += 20) {
        for ($x = 0; $x < 1600; $x += 20) {
            $color = imagecolorallocate($image, ($x * 7 + $y) % 256, ($y * 9 + $x) % 256, ($x + $y * 3) % 256);
            imagefilledrectangle($image, $x, $y, $x + 19, $y + 19, $color);
        }
    }
    imagepng($image, $storage.'/phase-three-upload.png');
    imagedestroy($image);
    echo $storage.'/phase-three-upload.png';
} elseif ($action === 'clock') {
    $time = CarbonImmutable::parse($argv[2] ?? 'now')->toISOString();
    file_put_contents($storage.'/phase-three-clock.json', json_encode(['now' => $time]));
    echo $time;
} elseif ($action === 'reset-clock') {
    @unlink($storage.'/phase-three-clock.json');
} else {
    throw new InvalidArgumentException('Expected image, clock or reset-clock.');
}
