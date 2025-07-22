<?php
namespace App;

use Imagick;

class ImageHandler
{
    private Imagick $img;

    public function __construct(string $path)
    {
        $this->img = new Imagick($path);
    }

    public function crop(int $w, int $h): void
    {
        $this->img->cropThumbnailImage($w, $h);
    }

    public function toGrayscale(): void
    {
        $this->img->setImageType(Imagick::IMGTYPE_GRAYSCALE);
    }

    public function save(string $outPath, string $format): void
    {
        $this->img->setImageFormat($format);
        $this->img->writeImage($outPath);
    }
}
