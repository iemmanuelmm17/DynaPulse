<?php

declare(strict_types=1);

namespace DynaPulse\Http;

use RuntimeException;
use Throwable;

final class View
{
    public function __construct(private string $basePath)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $content = $this->capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        return $this->capture('layouts/' . $layout, $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    public function capture(string $template, array $data = []): string
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }

    public function exists(string $template): bool
    {
        return is_file($this->basePath . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.php');
    }
}
