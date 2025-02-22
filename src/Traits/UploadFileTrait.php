<?php

namespace BinshopsBlog\Traits;

use Illuminate\Http\UploadedFile;
use BinshopsBlog\Events\UploadedImage;
use BinshopsBlog\Models\BinshopsPost;
use File;
use BinshopsBlog\Models\BinshopsPostTranslation;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

trait UploadFileTrait
{
    static $num_of_attempts_to_find_filename = 100;

    protected $checked_blog_image_dir_is_writable = false;

    protected function increaseMemoryLimit()
    {
        if (config("binshopsblog.memory_limit")) {
            @ini_set('memory_limit', config("binshopsblog.memory_limit"));
        }
    }

    protected function getImageFilename(string $suggested_title, $image_size_details, UploadedFile $photo)
    {
        $base = $this->generate_base_filename($suggested_title);
        $wh = $this->getWhForFilename($image_size_details);
        $ext = '.' . $photo->getClientOriginalExtension();

        for ($i = 1; $i <= self::$num_of_attempts_to_find_filename; $i++) {
            $suffix = $i > 1 ? '-' . bin2hex(random_bytes(3)) : '';
            $attempt = str_slug($base . $suffix . $wh) . $ext;

            if (!File::exists($this->image_destination_path() . "/" . $attempt)) {
                return $attempt;
            }
        }

        throw new \RuntimeException("Unable to find a free filename after $i attempts - aborting now.");
    }

    protected function image_destination_path()
    {
        $path = public_path('/' . config("binshopsblog.blog_upload_dir"));
        $this->check_image_destination_path_is_writable($path);
        return $path;
    }

    protected function UploadAndResize(BinshopsPostTranslation $new_blog_post = null, $suggested_title, $image_size_details, $photo)
    {
        $image_filename = $this->getImageFilename($suggested_title, $image_size_details, $photo);
        $destinationPath = $this->image_destination_path();

        $manager = new ImageManager(new Driver());
        $resizedImage = $manager->read($photo->getRealPath());

        if (is_array($image_size_details)) {
            $w = $image_size_details['w'];
            $h = $image_size_details['h'];

            if (!empty($image_size_details['crop'])) {
                $resizedImage = $resizedImage->cover($w, $h);
            } else {
                $resizedImage = $resizedImage->scale(width: $w);
            }
        } elseif ($image_size_details === 'fullsize') {
            $w = $resizedImage->width();
            $h = $resizedImage->height();
        } else {
            throw new \Exception("Invalid image_size_details value");
        }

        $resizedImage->toJpg()->save($destinationPath . '/' . $image_filename, config("binshopsblog.image_quality", 80));

        event(new UploadedImage($image_filename, $resizedImage, $new_blog_post, __METHOD__));

        return [
            'filename' => $image_filename,
            'w' => $w,
            'h' => $h,
        ];
    }

    protected function getWhForFilename($image_size_details)
    {
        if (is_array($image_size_details)) {
            return '-' . $image_size_details['w'] . 'x' . $image_size_details['h'];
        } elseif (is_string($image_size_details)) {
            return "-" . str_slug(substr($image_size_details, 0, 30));
        }

        throw new \RuntimeException("Invalid image_size_details: must be an array with w and h, or a string");
    }

    protected function check_image_destination_path_is_writable($path)
    {
        if (!$this->checked_blog_image_dir_is_writable) {
            if (!is_writable($path)) {
                throw new \RuntimeException("Image destination path is not writable ($path)");
            }
            $this->checked_blog_image_dir_is_writable = true;
        }
    }

    protected function generate_base_filename(string $suggested_title)
    {
        $base = substr($suggested_title, 0, 100);
        return $base ?: 'image-' . bin2hex(random_bytes(3));
    }
}
