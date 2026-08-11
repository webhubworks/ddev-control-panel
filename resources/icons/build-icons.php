<?php

/**
 * Regenerates the app icon and the menu bar tray icon from ddev-mark.svg.
 *
 *   php resources/icons/build-icons.php
 *
 * Output goes to public/, which is what NativePHP actually reads: the
 * InstallsAppIcon trait copies public/icon.png and public/IconTemplate*.png into
 * both the Electron project and vendor/nativephp/desktop/resources/build on
 * every native:run and native:build. Writing straight into either of those
 * directories does not survive, because the copy overwrites it.
 *
 * No SVG rasteriser is assumed to be installed. macOS QuickLook is used to
 * render the SVG, which flattens it onto an opaque white background, so the
 * artwork's coverage is recovered from the red channel: the mark is #02a8e2
 * (red = 2) against white (red = 255), which makes red an accurate anti-aliased
 * alpha mask. Everything else is composited with GD.
 */
const SOURCE_SVG = __DIR__.'/ddev-mark.svg';

/**
 * The tray glyph is a separate, simplified drawing rather than the full mark:
 * at 16pt the mark's concentric traces are sub-pixel and turn to mush.
 */
const TRAY_SVG = __DIR__.'/tray-glyph.svg';

const RENDER_SIZE = 2048;
const ICON_SIZE = 1024;

/** macOS icon grid: the artwork occupies 824x824 of a 1024 canvas. */
const PLATE_INSET = 100;
const PLATE_RADIUS = 186;

/** How much of the icon width the mark spans, leaving margin inside the plate. */
const MARK_WIDTH_RATIO = 0.60;

/** ddev brand cyan, lightened at the top and deepened at the bottom. */
const GRADIENT_TOP = [0x2E, 0xBC, 0xEE];
const GRADIENT_BOTTOM = [0x01, 0x76, 0xAB];

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

/**
 * Rasterise the SVG at $size via QuickLook. The result is opaque, with the
 * artwork anti-aliased against white.
 */
function renderSvg(string $source, int $size): GdImage
{
    $work = sys_get_temp_dir().'/ddev-icon-'.basename($source, '.svg').'-'.$size;

    if (! is_dir($work) && ! mkdir($work, 0777, true)) {
        fail("could not create {$work}");
    }

    // QuickLook honours the SVG's intrinsic width/height, so they are rewritten
    // to the target size. Without that it renders at its native size and pads
    // the rest of the canvas.
    $svg = file_get_contents($source);
    $svg = preg_replace('/\bwidth="\d+(\.\d+)?"/', 'width="'.$size.'"', $svg, 1);
    $svg = preg_replace('/\bheight="\d+(\.\d+)?"/', 'height="'.$size.'"', $svg, 1);

    $svgPath = $work.'/mark.svg';
    file_put_contents($svgPath, $svg);

    $pngPath = $svgPath.'.png';
    @unlink($pngPath);

    exec(sprintf('qlmanage -t -s %d -o %s %s 2>/dev/null', $size, escapeshellarg($work), escapeshellarg($svgPath)));

    if (! is_file($pngPath)) {
        fail('qlmanage did not render the SVG');
    }

    $image = imagecreatefrompng($pngPath);

    if ($image === false) {
        fail('could not read the rendered PNG');
    }

    return $image;
}

/**
 * Tight bounding box of the artwork, found by looking for anything that is not
 * the white backdrop.
 *
 * @return array{int, int, int, int} x, y, width, height
 */
function contentBounds(GdImage $image): array
{
    $width = imagesx($image);
    $height = imagesy($image);

    $minX = $width;
    $minY = $height;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            if (((imagecolorat($image, $x, $y) >> 16) & 0xFF) < 200) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    if ($maxX < 0) {
        fail('the rendered SVG appears to be blank');
    }

    return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
}

/**
 * Turn the white-backed render into a white silhouette with a real alpha
 * channel, cropped to the artwork.
 */
function silhouette(GdImage $render, array $bounds): GdImage
{
    [$x, $y, $width, $height] = $bounds;

    $out = imagecreatetruecolor($width, $height);
    imagealphablending($out, false);
    imagesavealpha($out, true);

    for ($row = 0; $row < $height; $row++) {
        for ($column = 0; $column < $width; $column++) {
            $red = (imagecolorat($render, $x + $column, $y + $row) >> 16) & 0xFF;

            // Coverage of a pixel that is a blend of white (255) and the mark (2).
            $coverage = max(0.0, min(1.0, (255 - $red) / 253));
            $alpha = (int) round((1 - $coverage) * 127);

            imagesetpixel($out, $column, $row, imagecolorallocatealpha($out, 255, 255, 255, $alpha));
        }
    }

    return $out;
}

function blankCanvas(int $size): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocatealpha($image, 0, 0, 0, 127));

    return $image;
}

function roundedRectangle(GdImage $image, int $x, int $y, int $width, int $height, int $radius, int $color): void
{
    $diameter = $radius * 2;

    imagefilledrectangle($image, $x + $radius, $y, $x + $width - $radius, $y + $height, $color);
    imagefilledrectangle($image, $x, $y + $radius, $x + $width, $y + $height - $radius, $color);

    $corners = [
        [$x + $radius, $y + $radius],
        [$x + $width - $radius, $y + $radius],
        [$x + $radius, $y + $height - $radius],
        [$x + $width - $radius, $y + $height - $radius],
    ];

    foreach ($corners as [$cx, $cy]) {
        imagefilledellipse($image, $cx, $cy, $diameter, $diameter, $color);
    }
}

/**
 * Anti-aliased coverage mask for the rounded plate: drawn at 2x with GD's
 * aliased primitives, then resampled down.
 */
function plateMask(int $size): GdImage
{
    $scale = 2;
    $big = imagecreatetruecolor($size * $scale, $size * $scale);
    imagealphablending($big, true);
    imagefilledrectangle($big, 0, 0, $size * $scale, $size * $scale, imagecolorallocate($big, 0, 0, 0));

    roundedRectangle(
        $big,
        PLATE_INSET * $scale,
        PLATE_INSET * $scale,
        ($size - 2 * PLATE_INSET) * $scale,
        ($size - 2 * PLATE_INSET) * $scale,
        PLATE_RADIUS * $scale,
        imagecolorallocate($big, 255, 255, 255),
    );

    $mask = imagecreatetruecolor($size, $size);
    imagecopyresampled($mask, $big, 0, 0, 0, 0, $size, $size, $size * $scale, $size * $scale);

    return $mask;
}

function buildAppIcon(GdImage $markSilhouette): GdImage
{
    $markWidth = (int) round(ICON_SIZE * MARK_WIDTH_RATIO);
    $markHeight = (int) round($markWidth * imagesy($markSilhouette) / imagesx($markSilhouette));

    // The mark on its own transparent layer, centred, so it can be sampled per pixel.
    $markLayer = blankCanvas(ICON_SIZE);
    imagealphablending($markLayer, false);
    imagecopyresampled(
        $markLayer,
        $markSilhouette,
        (int) round((ICON_SIZE - $markWidth) / 2),
        (int) round((ICON_SIZE - $markHeight) / 2),
        0,
        0,
        $markWidth,
        $markHeight,
        imagesx($markSilhouette),
        imagesy($markSilhouette),
    );

    $mask = plateMask(ICON_SIZE);
    $icon = blankCanvas(ICON_SIZE);

    for ($y = 0; $y < ICON_SIZE; $y++) {
        // Vertical brand gradient.
        $t = $y / (ICON_SIZE - 1);
        $background = [
            (int) round(GRADIENT_TOP[0] + (GRADIENT_BOTTOM[0] - GRADIENT_TOP[0]) * $t),
            (int) round(GRADIENT_TOP[1] + (GRADIENT_BOTTOM[1] - GRADIENT_TOP[1]) * $t),
            (int) round(GRADIENT_TOP[2] + (GRADIENT_BOTTOM[2] - GRADIENT_TOP[2]) * $t),
        ];

        for ($x = 0; $x < ICON_SIZE; $x++) {
            $plate = ((imagecolorat($mask, $x, $y) >> 16) & 0xFF) / 255;

            if ($plate <= 0.0) {
                continue;
            }

            // White mark composited over the gradient, both clipped to the plate.
            $markAlpha = (imagecolorat($markLayer, $x, $y) >> 24) & 0x7F;
            $markCoverage = (127 - $markAlpha) / 127;

            $red = (int) round($background[0] + (255 - $background[0]) * $markCoverage);
            $green = (int) round($background[1] + (255 - $background[1]) * $markCoverage);
            $blue = (int) round($background[2] + (255 - $background[2]) * $markCoverage);

            imagesetpixel($icon, $x, $y, imagecolorallocatealpha($icon, $red, $green, $blue, (int) round((1 - $plate) * 127)));
        }
    }

    return $icon;
}

/**
 * The tray icon is a black-plus-alpha template image, which macOS recolours for
 * the light and dark menu bar, including the inverted highlighted state.
 */
function buildTrayIcon(GdImage $silhouette, int $size): GdImage
{
    $sourceWidth = imagesx($silhouette);
    $sourceHeight = imagesy($silhouette);

    // Black rather than white: a template image is a mask, and macOS reads the
    // alpha channel, but keeping the RGB black avoids surprises anywhere the
    // image is used untinted.
    $black = imagecreatetruecolor($sourceWidth, $sourceHeight);
    imagealphablending($black, false);
    imagesavealpha($black, true);

    for ($y = 0; $y < $sourceHeight; $y++) {
        for ($x = 0; $x < $sourceWidth; $x++) {
            $alpha = (imagecolorat($silhouette, $x, $y) >> 24) & 0x7F;
            imagesetpixel($black, $x, $y, imagecolorallocatealpha($black, 0, 0, 0, $alpha));
        }
    }

    // Fit within the square, preserving the aspect ratio, and centre it.
    $scale = min($size / $sourceWidth, $size / $sourceHeight);
    $targetWidth = (int) round($sourceWidth * $scale);
    $targetHeight = (int) round($sourceHeight * $scale);

    $icon = blankCanvas($size);
    imagealphablending($icon, false);
    imagecopyresampled(
        $icon,
        $black,
        (int) round(($size - $targetWidth) / 2),
        (int) round(($size - $targetHeight) / 2),
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight,
    );

    return $icon;
}

function write(GdImage $image, string $path): void
{
    if (! imagepng($image, $path)) {
        fail("could not write {$path}");
    }

    printf("%s (%dx%d, %s)\n", basename($path), imagesx($image), imagesy($image), number_format(filesize($path)).' bytes');
}

$publicDirectory = dirname(__DIR__, 2).'/public';

if (! is_dir($publicDirectory)) {
    fail("public/ is missing at {$publicDirectory}");
}

echo 'Rendering '.basename(SOURCE_SVG).' at '.RENDER_SIZE."px..\n";

$markRender = renderSvg(SOURCE_SVG, RENDER_SIZE);
$markBounds = contentBounds($markRender);

printf("  artwork bounds %dx%d at (%d, %d)\n", $markBounds[2], $markBounds[3], $markBounds[0], $markBounds[1]);

$appIcon = buildAppIcon(silhouette($markRender, $markBounds));

write($appIcon, $publicDirectory.'/icon.png');

// Also written into the published Electron project, because that is
// electron-builder's buildResources directory and the source of the packaged
// .icns. InstallsAppIcon intends to copy it there but resolves the path through
// electronPath('build/icon.png'), which looks for a package.json inside build/,
// does not find one, and silently falls back to the vendor copy instead.
$electronBuildDirectory = dirname(__DIR__, 2).'/nativephp/electron/build';

if (is_dir($electronBuildDirectory)) {
    write($appIcon, $electronBuildDirectory.'/icon.png');
}

echo 'Rendering '.basename(TRAY_SVG)." for the menu bar..\n";

// Rendered generously large so the downsample to 16 and 32 is clean.
$trayRender = renderSvg(TRAY_SVG, 512);
$trayGlyph = silhouette($trayRender, contentBounds($trayRender));

write(buildTrayIcon($trayGlyph, 16), $publicDirectory.'/IconTemplate.png');
write(buildTrayIcon($trayGlyph, 32), $publicDirectory.'/IconTemplate@2x.png');

echo "Done. Restart native:run to pick these up.\n";
