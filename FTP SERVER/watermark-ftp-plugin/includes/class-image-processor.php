<?php
class ImageProcessor {
    private $ftpHandler;

    public function __construct($ftpHandler) {
        $this->ftpHandler = $ftpHandler;
    }

    public function processImage($sourcePath, $destinationPath, $watermarkPath) {
        // Lê a imagem do servidor FTP
        $image = $this->ftpHandler->readFile($sourcePath);
        if (!$image) {
            return false; // Falha ao ler a imagem
        }

        // Cria uma nova imagem a partir do arquivo lido
        $imageResource = imagecreatefromstring($image);
        if (!$imageResource) {
            return false; // Falha ao criar a imagem
        }

        // Lê a marca d'água
        $watermark = imagecreatefrompng($watermarkPath);
        if (!$watermark) {
            return false; // Falha ao ler a marca d'água
        }

        // Obtém as dimensões da imagem e da marca d'água
        $imageWidth = imagesx($imageResource);
        $imageHeight = imagesy($imageResource);
        $watermarkWidth = imagesx($watermark);
        $watermarkHeight = imagesy($watermark);

        // Define a posição da marca d'água (canto inferior direito)
        $destX = $imageWidth - $watermarkWidth - 10; // 10 pixels de margem
        $destY = $imageHeight - $watermarkHeight - 10; // 10 pixels de margem

        // Adiciona a marca d'água à imagem
        imagecopy($imageResource, $watermark, $destX, $destY, 0, 0, $watermarkWidth, $watermarkHeight);

        // Salva a imagem processada em uma nova pasta
        ob_start();
        imagejpeg($imageResource);
        $imageData = ob_get_contents();
        ob_end_clean();

        $this->ftpHandler->writeFile($destinationPath, $imageData);

        // Libera a memória
        imagedestroy($imageResource);
        imagedestroy($watermark);

        return true; // Processamento concluído com sucesso
    }
}
?>