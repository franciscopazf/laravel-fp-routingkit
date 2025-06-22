<?php

namespace FpF\RoutingKit\Features\DataRepositoryFeature;

use FpF\RoutingKit\Contracts\FpFDataRepositoryInterface;
use FpF\RoutingKit\Contracts\FpFileTransformerInterface;
use Illuminate\Support\Collection;
use FpF\RoutingKit\Features\DataFileTransformersFeature\FpFileTransformerFactory;
use Symfony\Component\Process\Process;
use RuntimeException;

class FpFBaseFileDataRepository implements FpFDataRepositoryInterface
{
    public string $filePath;
    public string $fileSave;
    public bool $onlyStringSupport = false;

    private FpFileTransformerInterface $fpfFileTransformer;

    public function __construct(
        string $filePath,
        string $fileSave,
        bool $onlyStringSupport = false,
        ?FpFileTransformerInterface $fpfFileTransformer = null
    ) {
        $this->filePath = $filePath;
        $this->fileSave = $fileSave;
        $this->onlyStringSupport = $onlyStringSupport;
       
        $this->fpfFileTransformer = $fpfFileTransformer ?? FpFileTransformerFactory::getFileTransformer(
            $this->getContentsString(),
            $this->fileSave,
            $this->onlyStringSupport
        );
    }

    public static function make(
        string $filePath,
        string $fileSave,
        bool $onlyStringSupport = false,
        ?FpFileTransformerInterface $fpfFileTransformer = null
    ): self {
        return new self($filePath, $fileSave, $onlyStringSupport, $fpfFileTransformer);
    }

    public function setFpFileTransformer(FpFileTransformerInterface $fpfFileTransformer): self
    {
        $this->fpfFileTransformer = $fpfFileTransformer;
        return $this;
    }

    public static function create(string $filePath, string $fileSave, bool $onlyStringSupport = false): self
    {
        return new self($filePath, $fileSave, $onlyStringSupport);
    }

    public function rewrite(Collection $newDataIntree): self
    {
        $newContent = $this->fpfFileTransformer->transform($newDataIntree);
        
        // Solo formatea el contenido con Pint si hay más de un elemento en el arreglo
        if ($newDataIntree->count() > 0) {
            $formattedContent = $this->formatContentWithPint($newContent);
        } else {
            $formattedContent = $newContent; // Usa el contenido sin formatear
        }
        
        $this->putContents($formattedContent);

        return $this;
    }

    public function getContents(): array
    {
        if (!file_exists($this->filePath)) {
            throw new RuntimeException("File not found: {$this->filePath}");
        }

        $content = include $this->filePath;
        
        if (!is_array($content)) {
            throw new RuntimeException("The file {$this->filePath} did not return an array.");
        }
        return $content;
    }

    public function getContentsString(): string
    {
        if (!file_exists($this->filePath)) {
            return '';
        }
        return file_get_contents($this->filePath);
    }

    public function putContents(string $content): void
    {
        if (file_put_contents($this->filePath, $content) === false) {
            throw new RuntimeException("Failed to write content to file: {$this->filePath}");
        }
    }

    /**
     * Formatea un string de contenido PHP usando Laravel Pint.
     * Crea un archivo temporal, lo formatea con Pint y devuelve el contenido formateado.
     *
     * @param string $contentToFormat El contenido PHP como string.
     * @return string El contenido PHP formateado.
     * @throws RuntimeException Si Laravel Pint no se encuentra o falla al formatear.
     */
    protected function formatContentWithPint(string $contentToFormat): string
    {
        $pintPath = base_path('vendor/bin/pint');

        if (!file_exists($pintPath)) {
            error_log("Laravel Pint executable not found at: {$pintPath}. Skipping formatting.");
            return $contentToFormat; 
        }

        $tempFilePath = tempnam(sys_get_temp_dir(), 'pint_format_') . '.php';

        if (file_put_contents($tempFilePath, $contentToFormat) === false) {
            throw new RuntimeException("Failed to write to temporary file: {$tempFilePath}");
        }

        $command = ['php', $pintPath, $tempFilePath];
        $process = new Process($command);

        // No imprimimos el mensaje de "Formatting content with Pint..."
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($tempFilePath); 
            throw new RuntimeException(sprintf(
                'Laravel Pint failed to format temporary file %s: %s',
                $tempFilePath,
                $process->getErrorOutput()
            ));
        }

        $formattedContent = file_get_contents($tempFilePath);
        if ($formattedContent === false) {
            @unlink($tempFilePath); 
            throw new RuntimeException("Failed to read formatted content from temporary file: {$tempFilePath}");
        }

        @unlink($tempFilePath);

        return $formattedContent;
    }
}
