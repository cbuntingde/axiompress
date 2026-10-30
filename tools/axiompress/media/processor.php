<?php

declare(strict_types=1);

// This decoder executes only in the fixed resource-restricted gVisor image, never native HTTP core.
set_error_handler(static function (): never {
    throw new RuntimeException('Image decoder rejected input.');
});
try {
    $header = stream_get_contents(STDIN, 4);
    $length = strlen($header) === 4 ? unpack('Nlength', $header)['length'] : 0;
    if ($length < 1 || $length > 2097152) {
        throw new RuntimeException('Image input limit.');
    }
    $source = stream_get_contents(STDIN, $length);
    if ($source === false || strlen($source) !== $length) {
        throw new RuntimeException('Truncated image frame.');
    }
    $info = getimagesizefromstring($source);
    if (
        $info === false || !in_array($info[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP), true)
        || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096
        || $info[0] * $info[1] > 8388608
    ) {
        throw new RuntimeException('Unsupported image dimensions/type.');
    }
    $image = imagecreatefromstring($source);
    if ($image === false) {
        throw new RuntimeException('Invalid image.');
    }
    $ratio = min(1.0, 1600 / $info[0], 1600 / $info[1]);
    $width = max(1, (int) floor($info[0] * $ratio));
    $height = max(1, (int) floor($info[1] * $ratio));
    $output = imagecreatetruecolor($width, $height);
    if ($output === false) {
        throw new RuntimeException('Image allocation failed.');
    }
    imagealphablending($output, false);
    imagesavealpha($output, true);
    if (!imagecopyresampled($output, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1])) {
        throw new RuntimeException('Image transformation failed.');
    }
    ob_start();
    if (!imagepng($output, null, 6)) {
        throw new RuntimeException('Image encoding failed.');
    }
    $png = ob_get_clean();
    if ($png === false || strlen($png) > 2097152) {
        throw new RuntimeException('Image output limit.');
    }
    fwrite(STDOUT, json_encode(
        array('width' => $width, 'height' => $height, 'data' => base64_encode($png)),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    ));
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDOUT, '{"error":"invalid_image"}');
    exit(2);
}
