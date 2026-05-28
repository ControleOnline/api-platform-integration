<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Service\Client\Food99Client;
use ControleOnline\Service\DefaultFoodService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

class Food99CatalogImageNormalizerService extends DefaultFoodService
{
    private ?Food99Client $food99Client = null;

    private function logger(): ?LoggerInterface
    {
        if (!$this->loggerService) {
            return null;
        }

        return $this->loggerService->getLogger('Food99');
    }

    private function isHttpImageUrl(?string $url): bool
    {
        if (!$url) {
            return false;
        }

        return (bool) preg_match('/^https?:\\/\\//i', trim($url));
    }

    #[Required]
    public function setFood99Client(Food99Client $food99Client): void
    {
        $this->food99Client = $food99Client;
    }

    /**
     * Converte bytes brutos de imagem para recurso GD.
     * Tenta GD nativo primeiro; para formatos não suportados (SVG, AVIF, etc.)
     * tenta Imagick se disponível, convertendo internamente para JPEG antes.
     */
    private function tryRawToGdImage(string $raw): ?\GdImage
    {
        $image = @imagecreatefromstring($raw);
        if ($image instanceof \GdImage) {
            return $image;
        }

        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($raw);
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
            $imagick = $imagick->flattenImages();
            $imagick->setImageFormat('jpeg');
            $jpeg = $imagick->getImageBlob();
            $imagick->clear();
            $result = @imagecreatefromstring($jpeg);

            return ($result instanceof \GdImage) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function normalizeImageForFood99Upload(string $sourceUrl, int $providerId, string $appItemId): ?string
    {
        try {
            if (!$this->isHttpImageUrl($sourceUrl) || !function_exists('imagecreatefromstring')) {
                return null;
            }

            $raw = $this->food99Client?->downloadContent($sourceUrl);
            if (!$raw) {
                return null;
            }

            $image = $this->tryRawToGdImage($raw);
            if (!$image) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= 0 || $height <= 0) {
                imagedestroy($image);
                return null;
            }

            $maxDimension = 3000;
            $minDimension = 150;
            $targetWidth = $width;
            $targetHeight = $height;

            if ($targetWidth > $maxDimension || $targetHeight > $maxDimension) {
                $scale = min($maxDimension / $targetWidth, $maxDimension / $targetHeight);
                $targetWidth = (int) max(1, floor($targetWidth * $scale));
                $targetHeight = (int) max(1, floor($targetHeight * $scale));
            }

            if ($targetWidth < $minDimension || $targetHeight < $minDimension) {
                $scale = max($minDimension / max(1, $targetWidth), $minDimension / max(1, $targetHeight));
                $targetWidth = (int) max($minDimension, ceil($targetWidth * $scale));
                $targetHeight = (int) max($minDimension, ceil($targetHeight * $scale));
            }

            $normalized = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($normalized, true);
            imagesavealpha($normalized, false);
            $white = imagecolorallocate($normalized, 255, 255, 255);
            imagefilledrectangle($normalized, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($normalized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($image);

            $tempBase = tempnam(sys_get_temp_dir(), 'food99_img_');
            if (!$tempBase) {
                imagedestroy($normalized);
                return null;
            }

            $targetPath = $tempBase . '.jpg';
            @unlink($tempBase);

            $quality = 90;
            $saved = false;

            while ($quality >= 70) {
                $saved = imagejpeg($normalized, $targetPath, $quality);
                if ($saved && file_exists($targetPath) && filesize($targetPath) <= 10 * 1024 * 1024) {
                    break;
                }
                $quality -= 5;
            }

            imagedestroy($normalized);

            if (!$saved || !file_exists($targetPath)) {
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                }

                return null;
            }

            if (filesize($targetPath) > 10 * 1024 * 1024) {
                @unlink($targetPath);
                return null;
            }

            return $targetPath;
        } catch (\Throwable $e) {
            $this->logger()?->warning('Food99 image normalization failed before upload', [
                'provider_id' => $providerId,
                'app_item_id' => $appItemId,
                'image_url' => $sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
