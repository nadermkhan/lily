<?php

namespace Lily\Exceptions;

use Lily\Http\Request;
use Lily\Http\Response;
use Throwable;

class ExceptionHandler
{
    private bool $debug;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    public function handle(Throwable $e, Request $request): Response
    {
        $statusCode = $this->isHttpException($e) ? $e->getCode() : 500;
        
        if ($statusCode < 100 || $statusCode > 599) {
            $statusCode = 500;
        }

        if ($this->debug) {
            $content = $this->renderDebugPage($e);
        } else {
            $content = $this->renderErrorPage($statusCode);
        }

        return new Response($content, $statusCode);
    }

    private function isHttpException(Throwable $e): bool
    {
        return $e->getCode() >= 400 && $e->getCode() <= 599;
    }

    private function renderDebugPage(Throwable $e): string
    {
        $frames = [];
        $frames[] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => '',
            'function' => '',
            'snippet' => $this->getSnippet($e->getFile(), $e->getLine())
        ];

        foreach ($e->getTrace() as $trace) {
            $file = $trace['file'] ?? null;
            $line = $trace['line'] ?? null;
            $frames[] = [
                'file' => $file,
                'line' => $line,
                'class' => $trace['class'] ?? '',
                'function' => $trace['function'] ?? '',
                'snippet' => $file && $line ? $this->getSnippet($file, $line) : []
            ];
        }

        $context = [
            'Request' => [
                'Method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'URI' => $_SERVER['REQUEST_URI'] ?? '',
                'Headers' => $this->getHeaders(),
                'Query' => $_GET ?? [],
                'Body' => $_POST ?? [],
            ],
            'Environment' => $this->redactSecrets($_ENV ?? []),
            'Server' => $this->redactSecrets($_SERVER ?? []),
        ];

        $payload = json_encode([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'frames' => $frames,
            'context' => $context
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return $this->getDebugHtml($payload);
    }

    private function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }

    private function redactSecrets(array $data): array
    {
        $sensitive = ['password', 'secret', 'token', 'key', 'auth', 'cookie', 'session'];
        array_walk_recursive($data, function (&$value, $key) use ($sensitive) {
            if (!is_string($key)) return;
            $k = strtolower($key);
            foreach ($sensitive as $s) {
                if (str_contains($k, $s)) {
                    $value = '[REDACTED]';
                    return;
                }
            }
        });
        return $data;
    }

    private function getSnippet(string $file, int $line, int $padding = 10): array
    {
        if (!file_exists($file)) return [];
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return [];
        $start = max(0, $line - $padding - 1);
        $end = min(count($lines), $line + $padding);
        $snippet = [];
        for ($i = $start; $i < $end; $i++) {
            $snippet[$i + 1] = $lines[$i];
        }
        return $snippet;
    }

    private function getDebugHtml(string $jsonPayload): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lily Exception</title>
    <style>
        :root {
            --bg: #f9fafb; --text: #111827; --card-bg: #ffffff;
            --border: #e5e7eb; --primary: #ef4444; --primary-light: #fef2f2;
            --muted: #6b7280; --code-bg: #1f2937; --code-text: #f3f4f6;
            --highlight: #374151; --line-num: #9ca3af;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111827; --text: #f9fafb; --card-bg: #1f2937;
                --border: #374151; --primary: #f87171; --primary-light: #7f1d1d;
                --muted: #9ca3af; --code-bg: #0f172a; --code-text: #f8fafc;
                --highlight: #1e293b; --line-num: #64748b;
            }
        }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; }
        header { background: var(--card-bg); padding: 2rem; border-bottom: 1px solid var(--border); }
        h1 { margin: 0 0 0.5rem 0; font-size: 1.5rem; color: var(--primary); word-break: break-all; }
        .message { font-size: 1.25rem; margin: 0; font-weight: 500; }
        
        .layout { display: flex; flex: 1; overflow: hidden; }
        .sidebar { width: 350px; background: var(--card-bg); border-right: 1px solid var(--border); overflow-y: auto; }
        .frame { padding: 1rem; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.2s; }
        .frame:hover { background: var(--bg); }
        .frame.active { background: var(--primary-light); border-left: 4px solid var(--primary); }
        .frame-func { font-weight: 600; font-size: 0.9rem; margin-bottom: 0.25rem; word-break: break-all; }
        .frame-file { font-size: 0.8rem; color: var(--muted); word-break: break-all; }
        
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .toolbar { padding: 1rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--card-bg); }
        .btn { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 0.5rem 1rem; border-radius: 0.375rem; cursor: pointer; text-decoration: none; font-size: 0.875rem; }
        .btn:hover { background: var(--border); }
        
        .code-container { flex: 1; overflow: auto; background: var(--code-bg); padding: 1rem 0; }
        .code-line { display: flex; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.875rem; white-space: pre; padding: 0.125rem 1rem; }
        .code-line.active { background: var(--highlight); }
        .line-num { color: var(--line-num); width: 3rem; text-align: right; margin-right: 1.5rem; user-select: none; }
        .line-content { color: var(--code-text); }
        
        .tabs { display: flex; border-bottom: 1px solid var(--border); background: var(--card-bg); }
        .tab { padding: 0.75rem 1.5rem; cursor: pointer; font-weight: 500; color: var(--muted); border-bottom: 2px solid transparent; }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-content { display: none; padding: 1.5rem; overflow-y: auto; max-height: 400px; background: var(--bg); }
        .tab-content.active { display: block; }
        pre.dump { background: var(--card-bg); padding: 1rem; border-radius: 0.5rem; border: 1px solid var(--border); overflow-x: auto; font-size: 0.875rem; margin: 0; }
    </style>
</head>
<body>
    <div id="app"></div>
    <script>
        const data = {$jsonPayload};
        let activeFrameIdx = 0;
        let activeTab = 'Request';

        function render() {
            const frame = data.frames[activeFrameIdx] || {};
            const snippet = frame.snippet || {};
            const lines = Object.keys(snippet).map(num => {
                const isActive = parseInt(num) === frame.line;
                const safeCode = snippet[num].replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                return \`<div class="code-line \${isActive ? 'active' : ''}"><div class="line-num">\${num}</div><div class="line-content">\${safeCode}</div></div>\`;
            }).join('');

            const framesHtml = data.frames.map((f, idx) => {
                const isInternal = !f.file;
                const name = f.class ? f.class + '::' + f.function : (f.function || 'main');
                const loc = isInternal ? '[internal function]' : f.file + ':' + f.line;
                return \`<div class="frame \${idx === activeFrameIdx ? 'active' : ''}" onclick="selectFrame(\${idx})">
                    <div class="frame-func">\${name}</div>
                    <div class="frame-file">\${loc}</div>
                </div>\`;
            }).join('');

            const tabsHtml = Object.keys(data.context).map(tab => 
                \`<div class="tab \${tab === activeTab ? 'active' : ''}" onclick="selectTab('\${tab}')">\${tab}</div>\`
            ).join('');

            const tabContentsHtml = Object.keys(data.context).map(tab => {
                const content = JSON.stringify(data.context[tab], null, 4);
                return \`<div class="tab-content \${tab === activeTab ? 'active' : ''}">
                    <pre class="dump">\${content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre>
                </div>\`;
            }).join('');

            let editorLinks = '';
            if (frame.file) {
                editorLinks = \`
                    <a href="vscode://file/\${frame.file}:\${frame.line}" class="btn">Open in VSCode</a>
                    <a href="phpstorm://open?file=\${frame.file}&line=\${frame.line}" class="btn">Open in PhpStorm</a>
                \`;
            }

            document.getElementById('app').innerHTML = \`
                <header>
                    <h1>\${data.exception}</h1>
                    <div class="message">\${data.message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
                </header>
                <div class="layout">
                    <div class="sidebar">\${framesHtml}</div>
                    <div class="main">
                        <div class="toolbar">
                            <div>\${frame.file ? frame.file + ':' + frame.line : ''}</div>
                            <div style="display:flex; gap:0.5rem;">\${editorLinks}</div>
                        </div>
                        <div class="code-container">\${lines}</div>
                        <div class="context-panel">
                            <div class="tabs">\${tabsHtml}</div>
                            \${tabContentsHtml}
                        </div>
                    </div>
                </div>
            \`;
        }

        window.selectFrame = function(idx) { activeFrameIdx = idx; render(); };
        window.selectTab = function(tab) { activeTab = tab; render(); };

        render();
    </script>
</body>
</html>
HTML;
    }

    private function renderErrorPage(int $statusCode): string
    {
        return "<h1>Error {$statusCode}</h1><p>Something went wrong.</p>";
    }
}
