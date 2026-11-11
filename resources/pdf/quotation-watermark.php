<?php

/**
 * Background for the FLIGHT QUOTATION PDF only (A4 at 1.5x, 1191 x 1684 px):
 *  - top to middle: a very faint FLYSE logo watermark;
 *  - bottom: a light-blue sky band with a layer of clouds seen from above and an aircraft
 *    wing on the right (as in the reference design), fading to white for the footer.
 *
 * Regenerate:  php resources/pdf/quotation-watermark.php
 * Output:      resources/pdf/quotation-watermark.jpg
 */
ini_set('memory_limit', '1024M');
$W = 1191;
$H = 1684;
$out = __DIR__.'/quotation-watermark.jpg';
$logoFile = __DIR__.'/../asset/Main logo PNG.webp';

$img = imagecreatetruecolor($W, $H);
imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
imagealphablending($img, true);

// ---------------------------------------------------------------- faint logo, top to middle
if (is_file($logoFile) && ($logo = @imagecreatefromwebp($logoFile))) {
    $lw = (int) ($W * 0.62);
    $lh = (int) round(imagesy($logo) * $lw / imagesx($logo));
    $scaled = imagecreatetruecolor($lw, $lh);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
    imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
    // Blend onto the page at ~7% strength (keeps the tan colour, very light).
    $strength = 0.07;
    $ox = intdiv($W - $lw, 2);
    $oy = (int) ($H * 0.40) - intdiv($lh, 2);
    for ($y = 0; $y < $lh; $y++) {
        for ($x = 0; $x < $lw; $x++) {
            $c = imagecolorat($scaled, $x, $y);
            $a = (127 - (($c >> 24) & 0x7F)) / 127 * $strength;
            if ($a <= 0) {
                continue;
            }
            $r = (int) round(255 * (1 - $a) + (($c >> 16) & 0xFF) * $a);
            $g = (int) round(255 * (1 - $a) + (($c >> 8) & 0xFF) * $a);
            $b = (int) round(255 * (1 - $a) + ($c & 0xFF) * $a);
            imagesetpixel($img, $ox + $x, $oy + $y, imagecolorallocate($img, $r, $g, $b));
        }
    }
}

// ---------------------------------------------------------------- cloud layer (value noise, drawn small then enlarged)
$s = 3;
$w = intdiv($W, $s);
$top = 0.785;     // band starts (fraction of page height)
$bottom = 0.958;  // band ends; white below for the footer
$y0 = (int) ($H * $top / $s);
$y1 = (int) ($H * $bottom / $s);
$bh = $y1 - $y0;

mt_srand(11);
$grids = [];
foreach ([[8, 0.5], [16, 0.27], [32, 0.15], [64, 0.08]] as [$cells, $weight]) {
    $gw = $cells + 2;
    $gh = (int) ceil($cells * $bh * 1.8 / $w) + 3; // rows cover the stretched band height
    $g = [];
    for ($i = 0; $i < $gw * $gh; $i++) {
        $g[] = mt_rand() / mt_getrandmax();
    }
    $grids[] = [$cells, $weight, $gw, $g];
}
$noise = function (float $x, float $y) use ($grids, $w): float {
    $v = 0;
    foreach ($grids as [$cells, $weight, $gw, $g]) {
        $fx = $x / $w * $cells;
        $fy = $y / $w * $cells;
        $ix = (int) $fx;
        $iy = (int) $fy;
        $tx = $fx - $ix;
        $ty = $fy - $iy;
        $tx = $tx * $tx * (3 - 2 * $tx);
        $ty = $ty * $ty * (3 - 2 * $ty);
        $a = $g[$iy * $gw + $ix];
        $b = $g[$iy * $gw + $ix + 1];
        $c = $g[($iy + 1) * $gw + $ix];
        $d = $g[($iy + 1) * $gw + $ix + 1];
        $v += $weight * (($a * (1 - $tx) + $b * $tx) * (1 - $ty) + ($c * (1 - $tx) + $d * $tx) * $ty);
    }

    return $v;
};

$band = imagecreatetruecolor($w, $bh);
for ($y = 0; $y < $bh; $y++) {
    $t = $y / $bh;                                   // 0 top of band .. 1 bottom
    $fadeIn = min(1, $t / 0.35);                     // sky fades in from white
    $fadeOut = $t > 0.86 ? max(0, 1 - ($t - 0.86) / 0.14) : 1; // back to white for the footer
    $env = $fadeIn * $fadeOut;
    // Sky colour: light blue, a little deeper lower down.
    $sky = [222 - 18 * $t, 234 - 12 * $t, 248 - 4 * $t];
    // Clouds get denser towards the bottom of the band.
    $density = 0.35 + 0.55 * $t;
    for ($x = 0; $x < $w; $x++) {
        $n = $noise($x, $y * 1.8);                   // stretched: flat cloud layer seen from above
        $cloud = max(0, min(1, ($n - (1 - $density) * 0.85) * 2.4));
        $shade = 1 - 0.1 * max(0, 0.6 - $n);         // slight blue shading in the valleys
        $r = $sky[0] + (255 * $shade - $sky[0]) * $cloud;
        $g = $sky[1] + (255 * $shade - $sky[1]) * $cloud;
        $b = $sky[2] + (255 - $sky[2]) * $cloud;
        // Blend with white by the envelope.
        $r = 255 + ($r - 255) * $env;
        $g = 255 + ($g - 255) * $env;
        $b = 255 + ($b - 255) * $env;
        imagesetpixel($band, $x, $y, imagecolorallocate($band, (int) $r, (int) $g, (int) $b));
    }
}
imagefilter($band, IMG_FILTER_GAUSSIAN_BLUR);
imagecopyresampled($img, $band, 0, (int) ($H * $top), 0, 0, $W, $bh * $s, $w, $bh);

// ---------------------------------------------------------------- aircraft wing on the right (4x supersampled)
$ss = 4;
$wx0 = (int) ($W * 0.55);
$wy0 = (int) ($H * 0.80);
$ww = $W - $wx0;
$wh = (int) ($H * 0.958) - $wy0;
$layer = imagecreatetruecolor($ww * $ss, $wh * $ss);
imagealphablending($layer, false);
imagesavealpha($layer, true);
imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
$P = fn (float $fx, float $fy) => [(int) round(($fx * $W - $wx0) * $ss), (int) round(($fy * $H - $wy0) * $ss)];
// Wing: from the lower edge (towards the viewer) up to the tip on the right, with a winglet.
$wing = [$P(0.56, 0.958), $P(0.74, 0.910), $P(0.896, 0.871), $P(0.906, 0.876), $P(0.86, 0.905), $P(0.79, 0.958)];
$winglet = [$P(0.896, 0.873), $P(0.912, 0.852), $P(0.917, 0.854), $P(0.904, 0.877)];
$flatten = fn (array $pts) => array_merge(...$pts);
// Shadowed underside, then a lighter top surface.
imagefilledpolygon($layer, $flatten($wing), imagecolorallocatealpha($layer, 120, 138, 160, 40));
imagefilledpolygon($layer, $flatten($winglet), imagecolorallocatealpha($layer, 100, 118, 142, 40));
$topSurface = [$P(0.59, 0.958), $P(0.75, 0.913), $P(0.893, 0.875), $P(0.83, 0.910), $P(0.70, 0.958)];
imagefilledpolygon($layer, $flatten($topSurface), imagecolorallocatealpha($layer, 196, 208, 222, 30));
// Leading-edge highlight.
imagesetthickness($layer, 2 * $ss);
imageline($layer, ...[...$P(0.56, 0.958), ...$P(0.896, 0.871), imagecolorallocatealpha($layer, 236, 242, 249, 20)]);
$small = imagecreatetruecolor($ww, $wh);
imagealphablending($small, false);
imagesavealpha($small, true);
imagecopyresampled($small, $layer, 0, 0, 0, 0, $ww, $wh, $ww * $ss, $wh * $ss);
imagecopy($img, $small, $wx0, $wy0, 0, 0, $ww, $wh);

// The wing fades out at the bottom like the clouds (white strip for the footer).
for ($y = (int) ($H * 0.94); $y < (int) ($H * 0.958); $y++) {
    $a = (int) round(127 * (1 - ($y - $H * 0.94) / ($H * 0.018)));
    imageline($img, $wx0, $y, $W, $y, imagecolorallocatealpha($img, 255, 255, 255, max(0, min(127, $a))));
}

imagejpeg($img, $out, 86);
echo "Saved $out (".round(filesize($out) / 1024)." KB)\n";
