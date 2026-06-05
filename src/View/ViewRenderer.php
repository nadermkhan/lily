<?php

namespace Lily\View;

class ViewRenderer
{
    private string $viewPath;

    public function __construct(string $viewPath)
    {
        $this->viewPath = rtrim($viewPath, '/');
    }

    public function render(string $view, array $data = []): string
    {
        $file = $this->viewPath . '/' . ltrim($view, '/') . '.php';

        if (!file_exists($file)) {
            throw new \Exception("View [{$view}] not found.");
        }

        $escapedData = $this->escapeData($data);
        
        $output = (function() use ($file, $escapedData) {
            extract($escapedData, EXTR_SKIP);
            ob_start();
            require $file;
            return ob_get_clean();
        })();

        return $output;
    }

    protected function escapeData(array $data): array
    {
        $escaped = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $escaped[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            } elseif (is_array($value)) {
                $escaped[$key] = $this->escapeData($value);
            } else {
                $escaped[$key] = $value;
            }
        }
        return $escaped;
    }
}
