<?php

namespace Lily\View;

/**
 * Handles rendering of view files.
 */
class ViewRenderer
{
    /**
     * The base path for views.
     *
     * @var string
     */
    private string $viewPath;

    /**
     * Create a new ViewRenderer instance.
     *
     * @param string $viewPath The base path where view files are located.
     */
    public function __construct(string $viewPath)
    {
        $this->viewPath = rtrim($viewPath, '/');
    }

    /**
     * Render a view file with the given data.
     *
     * @param string $view The view filename or path relative to the view path.
     * @param array $data The data to extract and pass to the view.
     * @return string The rendered view content.
     * @throws \Exception If the view file is not found.
     */
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

    /**
     * Recursively escape HTML characters in the provided data.
     *
     * @param array $data The data to escape.
     * @return array The escaped data.
     */
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
