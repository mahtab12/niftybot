<?php

declare(strict_types=1);

$theme = dirname(__DIR__) . '/web/themes/custom/niftyoption';
$svg = $theme . '/assets/images/favicon.svg';

if (!is_file($svg)) {
  fwrite(STDERR, "Missing SVG: {$svg}\n");
  exit(1);
}

if (!extension_loaded('imagick')) {
  fwrite(STDERR, "Imagick extension is required.\n");
  exit(1);
}

$imagick = new Imagick();
$imagick->setBackgroundColor(new ImagickPixel('transparent'));
$imagick->readImage($svg);

foreach ([16, 32, 180] as $size) {
  $frame = clone $imagick;
  $frame->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1);
  $frame->setImageFormat('png');
  if ($size === 180) {
    $frame->writeImage($theme . '/assets/images/apple-touch-icon.png');
  }
  else {
    $frame->writeImage($theme . '/assets/images/favicon-' . $size . 'x' . $size . '.png');
  }
  $frame->clear();
}

$ico = new Imagick();
$ico->setBackgroundColor(new ImagickPixel('transparent'));
$ico->readImage($svg);
$ico->resizeImage(32, 32, Imagick::FILTER_LANCZOS, 1);
$ico->setImageFormat('ico');
$ico->writeImage($theme . '/favicon.ico');
$ico->clear();

copy($theme . '/favicon.ico', $theme . '/assets/images/favicon.ico');

echo "Generated StrikeFlow favicon assets in {$theme}\n";
