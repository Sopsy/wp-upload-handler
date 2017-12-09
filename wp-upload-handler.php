<?php
/**
 * Plugin Name: WP Upload handler
 * Plugin URI: https://github.com/Sopsy/wp-upload-handler/
 * Description: Fixes uploaded image rotation and compresses them with PNGCrush and JpegOptim. Replaces WP jpeg_quality.
 * Version: 2.1
 * Author: Aleksi "Sopsy" Kinnunen
 * License: AGPLv3
 */
if (!defined('ABSPATH')) {
    exit;
}

class F881_UploadHandler
{
    public static $jpeqQuality = 80;

    protected static $pngCrush = '/usr/bin/pngcrush';
    protected static $jpegoptim = '/usr/bin/jpegoptim';

    public static function handleUpload($uploaded_file)
    {
        $uploaded_file = static::rotateByExif($uploaded_file);
        $uploaded_file = static::optimizeImages($uploaded_file);

        return $uploaded_file;
    }

    public static function optimizeResized($file)
    {
        if (empty($file)) {
            return $file;
        }

        $mime = mime_content_type($file);
        if ($mime == 'image/png') {
            static::optimizePng($file);
        } elseif (in_array($mime, ['image/jpg', 'image/jpeg'])) {
            static::optimizeJpeg($file);
        }

        return $file;

    }

    public static function rotateByExif($uploaded_file)
    {
        // Rotate by EXIF-tag
        // Rotate only jpegs
        if (!in_array($uploaded_file['type'], ['image/jpg', 'image/jpeg'])) {
            return $uploaded_file;
        }

        $exif = exif_read_data($uploaded_file['file']);
        if (!isset($exif)) // EXIF read failed, do nothing
        {
            return $uploaded_file;
        }
        if (!isset($exif['Orientation']) || $exif['Orientation'] == 1) // No orientation info or not rotated, do nothing
        {
            return $uploaded_file;
        }

        $image = wp_get_image_editor($uploaded_file['file']);
        if (is_wp_error($image)) // Image editor returned an error, do nothing
        {
            return $uploaded_file;
        }

        switch ($exif['Orientation']) {
            case 2:
                $image->flip(false, true);
                break;
            case 3:
                $image->rotate(180);
                break;
            case 4:
                $image->flip(true, false);
                break;
            case 5:
                $image->rotate(90);
                $image->flip(true, false);
                break;
            case 6:
                $image->rotate(270);
                break;
            case 7:
                $image->rotate(270);
                $image->flip(true, false);
                break;
            case 8:
                $image->rotate(90);
                break;
            default:
                break;
        }
        $image->save($uploaded_file['file']);

        return $uploaded_file;
    }

    public static function optimizeImages($uploaded_file)
    {
        // Crush PNGs
        if ($uploaded_file['type'] == 'image/png') {
            static::optimizePng($uploaded_file['file']);

            return $uploaded_file;
        }

        // Optimize JPEGs
        if (in_array($uploaded_file['type'], ['image/jpg', 'image/jpeg'])) {
            static::optimizeJpeg($uploaded_file['file']);
        }

        return $uploaded_file;
    }

    public static function wpJpegQuality()
    {
        if (!is_executable(static::$jpegoptim)) {
            return static::$jpeqQuality;
        }

        // Set to 100 to prevent double compression (optimizeJpeg does compression)
        return 100;
    }

    protected static function optimizeJpeg($file)
    {

        if (!is_executable(static::$jpegoptim) || !is_file($file) || !getimagesize($file)) {
            return false;
        }

        $quality = (int)static::$jpeqQuality;
        if ($quality < 1 || $quality > 100) {
            $quality = 85;
        }

        // Remove all EXIF and do optimizations
        shell_exec(escapeshellarg(static::$jpegoptim) . ' -s --all-progressive -m' . escapeshellarg($quality) . ' ' . escapeshellarg($file));

        return true;
    }

    protected static function optimizePng($file)
    {
        if (!is_executable(static::$pngCrush) || !is_file($file) || !getimagesize($file)) {
            return false;
        }

        // Overwrite, reduce to 8-bit if lossless, remove all meta chunks except transparency and gamma
        shell_exec(escapeshellarg(static::$pngCrush) . ' -ow -reduce -rem allb ' . escapeshellarg($file));

        return true;
    }
}

add_filter('wp_handle_upload', ['F881_UploadHandler', 'handleUpload'], 10, 1);
add_filter('image_make_intermediate_size', ['F881_UploadHandler', 'optimizeResized']);
add_filter('jpeg_quality', ['F881_UploadHandler', 'wpJpegQuality']);
