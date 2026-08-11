<?php

/**
 * Regenerates the app icon and the menu bar icons.
 *
 *   php resources/icons/build-icons.php
 *
 * Output goes to public/, which is the only durable location: NativePHP's
 * InstallsAppIcon trait copies public/icon.png and public/IconTemplate*.png into
 * the Electron project and into vendor/nativephp/desktop/resources/build on
 * every native:run and native:build. Every one of those copies is @-suppressed,
 * so a file that is missing or misnamed fails silently and the app ships the
 * NativePHP logo with nothing in the build output to say so.
 */

/** The supplied 1024x1024 artwork: a dark plate with the ddev mark. */
const APP_ICON_SOURCE = __DIR__.'/app-icon.png';

/** The official ddev mark, used for the menu bar. */
const MARK_SVG = __DIR__.'/ddev-mark.svg';

const ICON_SIZE = 1024;

/**
 * macOS draws app icons inset in their canvas: the plate occupies 824 of 1024,
 * leaving a transparent margin. The supplied artwork is full bleed, which is the
 * iOS convention, so it is scaled down into that box. Set this to ICON_SIZE for
 * an edge-to-edge icon instead.
 */
const PLATE_SIZE = 824;

/**
 * The mark's blue, sampled from app-icon.png rather than taken from ddev's
 * official #02a8e2, so the menu bar matches the app icon it sits next to.
 */
const MARK_BLUE = [0x3F, 0x92, 0xFF];

/**
 * Upstream tray sizes: NativePHP's own IconTemplate.png is 22x22 and its @2x is
 * 44x44. Anything else is scaled by the system and looks soft.
 *
 * @var array<int, string>
 */
const TRAY_SIZES = [22 => '', 44 => '@2x'];

/**
 * Fraction of the frame the mark fills. Higher than a simple glyph would need,
 * because the mark is dense and every pixel counts at 22px.
 */
const TRAY_CONTENT_RATIO = 0.95;

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

function blankCanvas(int $width, int $height): GdImage
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocatealpha($image, 0, 0, 0, 127));

    return $image;
}

/**
 * The supplied artwork, scaled onto the macOS icon grid.
 */
function buildAppIcon(): GdImage
{
    if (! is_file(APP_ICON_SOURCE)) {
        fail('missing '.APP_ICON_SOURCE);
    }

    $source = imagecreatefrompng(APP_ICON_SOURCE);

    if ($source === false) {
        fail('could not read '.APP_ICON_SOURCE);
    }

    $icon = blankCanvas(ICON_SIZE, ICON_SIZE);
    $offset = (int) round((ICON_SIZE - PLATE_SIZE) / 2);

    imagecopyresampled(
        $icon,
        $source,
        $offset,
        $offset,
        0,
        0,
        PLATE_SIZE,
        PLATE_SIZE,
        imagesx($source),
        imagesy($source),
    );

    return $icon;
}

/**
 * Rasterise an SVG at $size via QuickLook, which flattens it onto opaque white.
 *
 * No SVG rasteriser (rsvg-convert, ImageMagick, sharp) is assumed to be
 * installed, and regenerating an icon must not require one.
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

    $svgPath = $work.'/glyph.svg';
    file_put_contents($svgPath, $svg);

    $pngPath = $svgPath.'.png';
    @unlink($pngPath);

    exec(sprintf('qlmanage -t -s %d -o %s %s 2>/dev/null', $size, escapeshellarg($work), escapeshellarg($svgPath)));

    if (! is_file($pngPath)) {
        fail('qlmanage did not render '.basename($source));
    }

    $image = imagecreatefrompng($pngPath);

    if ($image === false) {
        fail('could not read the rendered PNG');
    }

    return $image;
}

/**
 * Tight bounding box of the artwork, found by looking for anything that is not
 * the white backdrop QuickLook rendered onto.
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
 * Recolour the white-backed render into $colour over transparency, cropped to
 * the artwork.
 *
 * The mark is drawn in #02a8e2, whose red channel is 2 against a white 255, so
 * red is an accurate anti-aliased coverage mask.
 *
 * @param  array{int, int, int, int}  $bounds
 * @param  array{int, int, int}  $colour
 */
function silhouette(GdImage $render, array $bounds, array $colour): GdImage
{
    [$x, $y, $width, $height] = $bounds;

    $out = blankCanvas($width, $height);

    for ($row = 0; $row < $height; $row++) {
        for ($column = 0; $column < $width; $column++) {
            $red = (imagecolorat($render, $x + $column, $y + $row) >> 16) & 0xFF;
            $coverage = max(0.0, min(1.0, (255 - $red) / 253));

            imagesetpixel($out, $column, $row, imagecolorallocatealpha(
                $out,
                $colour[0],
                $colour[1],
                $colour[2],
                (int) round((1 - $coverage) * 127),
            ));
        }
    }

    return $out;
}

/**
 * Fit the mark into a square menu bar image, preserving its aspect ratio.
 */
function buildTrayIcon(GdImage $glyph, int $size): GdImage
{
    $sourceWidth = imagesx($glyph);
    $sourceHeight = imagesy($glyph);

    $box = $size * TRAY_CONTENT_RATIO;
    $scale = min($box / $sourceWidth, $box / $sourceHeight);
    $targetWidth = (int) round($sourceWidth * $scale);
    $targetHeight = (int) round($sourceHeight * $scale);

    $icon = blankCanvas($size, $size);

    imagecopyresampled(
        $icon,
        $glyph,
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

    printf(
        "  %-42s %2dx%-4d %s bytes\n",
        str_replace(dirname(__DIR__, 2).'/', '', $path),
        imagesx($image),
        imagesy($image),
        number_format(filesize($path)),
    );
}

$root = dirname(__DIR__, 2);
$publicDirectory = $root.'/public';

if (! is_dir($publicDirectory)) {
    fail("public/ is missing at {$publicDirectory}");
}

echo 'App icon from '.basename(APP_ICON_SOURCE).' (inset to '.PLATE_SIZE.' of '.ICON_SIZE.")\n";

$appIcon = buildAppIcon();

write($appIcon, $publicDirectory.'/icon.png');

// Also written into the published Electron project: that is electron-builder's
// buildResources directory and the source of the packaged .icns. InstallsAppIcon
// intends to copy it there, but resolves electronPath('build/icon.png'), which
// looks for a package.json inside build/, does not find one, and silently falls
// back to the vendor copy instead.
$electronBuildDirectory = $root.'/nativephp/electron/build';

if (is_dir($electronBuildDirectory)) {
    write($appIcon, $electronBuildDirectory.'/icon.png');
}

echo 'Menu bar icons from '.basename(MARK_SVG)."\n";

// Rendered generously large so the downsample to 22 and 44 stays clean.
$markRender = renderSvg(MARK_SVG, 512);
$markBounds = contentBounds($markRender);

$blueMark = silhouette($markRender, $markBounds, MARK_BLUE);
$blackMark = silhouette($markRender, $markBounds, [0, 0, 0]);

foreach (TRAY_SIZES as $size => $suffix) {
    // The icon actually used. The name must NOT end in "Template": macOS treats
    // any such image as a mask and tints it, which would throw the blue away.
    // Electron finds the @2x variant alongside the 1x path on its own.
    write(buildTrayIcon($blueMark, $size), $publicDirectory."/tray{$suffix}.png");

    // Template fallback, on brand rather than NativePHP's logo, for the path
    // NativePHP takes when no explicit icon is set.
    write(buildTrayIcon($blackMark, $size), $publicDirectory."/IconTemplate{$suffix}.png");
}

echo "Done. Restart native:run to pick these up.\n";
