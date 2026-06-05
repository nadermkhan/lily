<?php
declare(strict_types=1);

/**
 * Router.php — Single-file production-ready PHP routing engine.
 *
 *   require __DIR__ . '/Router.php';
 *   Router::init();                              // load .env, headers, rate-limit
 *   Router::get('/', fn() => print 'hi')         // register routes
 *         ->name('home')
 *         ->rate('60/min')
 *         ->cacheable();
 *   Router::on(['example.com', 'www.example.com'])
 *         ->get('/about', fn() => Util::view('about'));
 *   Router::except('admin.example.com')
 *         ->get('/public', fn() => R::json(['ok' => true]));
 *   Router::dispatch();                           // resolve and invoke
 *
 * Including this file has no side effects. All bootstrap is performed by
 * Router::init() at the moment the front controller chooses.
 *
 * @version 2.1.0
 * @php     8.1+
 * @license MIT
 */

// =====================================================================
// SECTION 1 — ENVIRONMENT LOADER
// =====================================================================

final class Env
{
    private static bool $loaded = false;
    private static string $path = '';

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$path = $path ?? (defined('ROUTER_BASE_DIR')
            ? ROUTER_BASE_DIR . DIRECTORY_SEPARATOR . '.env'
            : dirname(__FILE__) . DIRECTORY_SEPARATOR . '.env');

        if (!file_exists(self::$path)) {
            self::generateDefault(self::$path);
        }

        $lines = @file(self::$path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = self::parseValue($value);

            if ($name === '' || array_key_exists($name, $_ENV) || getenv($name) !== false) {
                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            // FIX: do NOT coerce numeric strings like "0" / "1" into booleans —
            // they should round-trip as integers. Only the explicit textual
            // forms become booleans.
            if ($lower === 'true'  || $lower === 'yes') return true;
            if ($lower === 'false' || $lower === 'no')  return false;
            if ($lower === 'null'  || $lower === '')    return $default;
            if (is_numeric($value)) {
                return str_contains($value, '.') ? (float) $value : (int) $value;
            }
        }
        return $value;
    }

    /** @return string[] */
    public static function getArray(string $key, array $default = []): array
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        $trimmed = trim($raw);
        if ($trimmed !== '' && $trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
    }

    /** @return array<string,string> */
    public static function getMap(string $key, array $default = []): array
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        $trimmed = trim($raw);
        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $k => $v) {
                    $out[(string) $k] = (string) $v;
                }
                return $out;
            }
        }
        $out = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) continue;
            [$k, $v] = explode(':', $pair, 2);
            $k = trim($k); $v = trim($v);
            if ($k !== '' && $v !== '') $out[$k] = $v;
        }
        return $out ?: $default;
    }

    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';

        // Quoted form: "value" or 'value' — keep contents verbatim, no inline-comment stripping.
        if (
            (str_starts_with($raw, '"') && str_ends_with($raw, '"') && strlen($raw) >= 2) ||
            (str_starts_with($raw, "'") && str_ends_with($raw, "'") && strlen($raw) >= 2)
        ) {
            return substr($raw, 1, -1);
        }

        // Unquoted: strip inline comments only when '#' is preceded by whitespace,
        // so values like "pass#word" survive intact.
        if (preg_match('/\s#.*$/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $raw = rtrim(substr($raw, 0, $m[0][1]));
        }
        return $raw;
    }

    private static function generateDefault(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $secret = bin2hex(random_bytes(32));
        $tpl = <<<ENV
            # Router.php — auto-generated environment file.
            # Override per environment. Do not commit production secrets to source control.

            DEBUG_MODE=false
            APP_SECRET={$secret}

            # Response cache TTL (seconds) for GET routes flagged 'cacheable' => true.
            RESPONSE_CACHE_TTL=300

            # Per-IP global rate limit. Override per route via Router options.
            RATE_LIMIT_MAX=120
            RATE_LIMIT_WINDOW=60

            # CSRF token TTL (seconds). 0 disables expiry; tokens rotate on each session.
            CSRF_TOKEN_TTL=7200

            # Trusted proxy CIDRs. Forwarded-for headers are honoured ONLY when REMOTE_ADDR is in this list.
            # Format: comma-separated, e.g. "10.0.0.0/8,172.16.0.0/12"
            TRUSTED_PROXIES=

            # Multi-domain folder map for static assets when the web server cannot use per-host roots.
            # Format: JSON map ({"example.com":"sites/example"}) or "host:folder,host2:folder2".
            ROUTER_DOMAIN_FOLDERS=

            # Static asset bridge from the domain folder. Disable if the web server already serves them.
            ROUTER_STATIC_FILE_SERVING=true

            # Optional CORS allowlist (comma separated). Empty disables CORS entirely.
            ROUTER_CORS_ORIGINS=

            # Optional public cache-clear route. Disabled by default. Set ROUTER_CC_ROUTE=/_cc to enable;
            # the request must include ?t=<HMAC token> generated via Router::cacheClearToken().
            ROUTER_CC_ROUTE=

            # Default views directory used by Util::view(). Resolved relative to ROUTER_BASE_DIR.
            ROUTER_VIEWS_DIR=views

            # Comma-separated allowlist of Host headers (defends against host-header injection).
            # Wildcards supported: *.example.com. Leave blank to disable.
            ROUTER_TRUSTED_HOSTS=

            # Maximum JSON body size in bytes. Requests larger than this get 413.
            ROUTER_MAX_BODY_BYTES=2097152

            # Maximum upload size accepted by Util::saveFile() in bytes.
            ROUTER_MAX_UPLOAD_BYTES=5242880

            # SQLite database file path. Relative paths are resolved under
            # ROUTER_BASE_DIR. Leave blank to use the default
            # (.ncache/db/app.sqlite, which is already web-blocked).
            ROUTER_DB_PATH=
            ENV;

        @file_put_contents($path, $tpl);
        @chmod($path, 0600);
    }
}

// =====================================================================
// SECTION 2 — TRIE NODE
// =====================================================================

final class TrieNode
{
    /** @var array<string,TrieNode> Literal segment children. */
    public array $children = [];

    /** Dynamic param child (e.g. {id}). */
    public ?TrieNode $dynamicChild = null;
    public ?string   $paramName    = null;

    /** Optional regex constraint applied to the dynamic segment. */
    public ?string $paramConstraint = null;

    /** Wildcard child (catch-all, {path*}). Captures the remaining path. */
    public ?TrieNode $wildcardChild = null;
    public ?string   $wildcardName  = null;

    /** @var array<string,callable> HTTP method => handler callable. */
    public array $handlers = [];

    /** @var array<string,callable[]> method => middleware list (per-method). */
    public array $methodMiddleware = [];

    /** @var array<string,array{max:int,window:int,bucket:?string}|null> */
    public array $methodRate = [];

    /** @var array<string,bool> method => cacheable flag. */
    public array $methodCacheable = [];

    /** @var array<string,SeoMeta|null> method => SEO meta. */
    public array $methodSeo = [];

    /** @var array<string,string[]> method => allow-list of hosts (empty = any). */
    public array $methodAllowHosts = [];

    /** @var array<string,string[]> method => block-list of hosts. */
    public array $methodBlockHosts = [];

    public bool   $isLeaf       = false;
    public string $routePattern = '';

    /** Route name for reverse lookup (last named handler on this leaf). */
    public ?string $name = null;
}

// =====================================================================
// SECTION 3 — SEO VALUE OBJECT
// =====================================================================

final class SeoMeta
{
    public function __construct(
        public readonly string $title       = '',
        public readonly string $description = '',
        public readonly string $keywords    = '',
        public readonly string $ogTitle     = '',
        public readonly string $ogImage     = '',
        public readonly string $ogType      = 'website',
        public readonly string $twitterCard = 'summary_large_image',
        public readonly string $canonical   = '',
        public readonly float  $priority    = 0.5,
        public readonly string $lastmod     = '',
    ) {
    }
}

// =====================================================================
// SECTION 4 — KEYWORD ENGINE (Unicode-aware)
// =====================================================================

final class KeywordEngine
{
    private const STOP_WORDS = [
        'a','an','the','and','or','but','in','on','at','to','for','of','with',
        'by','from','is','it','be','as','are','was','were','been','has','have',
        'had','do','does','did','will','would','could','should','may','might',
        'shall','that','this','these','those','i','you','he','she','we','they',
        'not','no',
    ];

    /**
     * @return array{words:int,unique:int,density:array<string,float>,suggested_keywords:string,suggested_description:string,warning:bool}
     */
    public static function analyse(string $text, int $maxKeywords = 10): array
    {
        $clean  = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
        $tokens = preg_split('/[^\p{L}]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $total = count($tokens);
        if ($total === 0) {
            return [
                'words' => 0, 'unique' => 0, 'density' => [],
                'suggested_keywords' => '', 'suggested_description' => '', 'warning' => false,
            ];
        }

        $stop = array_flip(self::STOP_WORDS);
        $freq = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) <= 2 || isset($stop[$tok])) continue;
            $freq[$tok] = ($freq[$tok] ?? 0) + 1;
        }

        $density = [];
        foreach ($freq as $word => $count) {
            $density[$word] = round(($count / $total) * 100, 2);
        }
        arsort($density);

        $warning = !empty($density) && max($density) > 3.0;
        $safe    = array_filter($density, fn(float $d) => $d <= 2.0);
        $top     = array_slice(array_keys($safe), 0, $maxKeywords);
        $sugDesc = mb_substr(preg_replace('/\s+/', ' ', $clean) ?? '', 0, 155, 'UTF-8');

        return [
            'words'                 => $total,
            'unique'                => count($freq),
            'density'               => $density,
            'suggested_keywords'    => implode(', ', $top),
            'suggested_description' => trim($sugDesc),
            'warning'               => $warning,
        ];
    }
}

// =====================================================================
// SECTION 5 — CACHE ENGINE (file, HMAC-guarded, atomic, safe unserialize)
// =====================================================================

final class CacheEngine
{
    private static bool $dirReady = false;

    public static function bootstrap(): void
    {
        if (self::$dirReady) return;

        foreach ([
            NCACHE_DIR,
            RATE_CACHE_DIR,
            dirname(ERROR_LOG_FILE),
            NCACHE_DIR . DIRECTORY_SEPARATOR . 'responses',
        ] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }
        self::$dirReady = true;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        self::bootstrap();

        $file    = self::keyToPath($key);
        $expires = time() + max(1, $ttl);
        $payload = serialize($value);
        // Bind the HMAC over (key, expires, payload) to defeat file-swap
        // attacks where an adversary with write access to the cache directory
        // moves a valid blob into a different cache slot. Without binding the
        // key, the HMAC would still verify and return the wrong value.
        $hmac    = hash_hmac(
            'sha256',
            'v2|' . $key . '|' . $expires . '|' . $payload,
            APP_SECRET,
            true,
        );
        $data    = pack('N', $expires) . pack('N', strlen($hmac)) . $hmac . $payload;

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
        $fp  = @fopen($tmp, 'wb');
        if ($fp === false) return false;

        if (!flock($fp, LOCK_EX)) {
            fclose($fp); @unlink($tmp);
            return false;
        }
        fwrite($fp, $data);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, 0640);
        return true;
    }

    public static function get(string $key): mixed
    {
        $file = self::keyToPath($key);
        if (!is_file($file)) return null;

        $fp = @fopen($file, 'rb');
        if ($fp === false) return null;

        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($data === false || strlen($data) < 8) return null;

        $expires = unpack('N', substr($data, 0, 4))[1] ?? 0;
        $hmacLen = unpack('N', substr($data, 4, 4))[1] ?? 0;

        if (time() > $expires) {
            @unlink($file);
            return null;
        }
        if ($hmacLen < 1 || strlen($data) < 8 + $hmacLen) {
            return null;
        }

        $storedHmac = substr($data, 8, $hmacLen);
        $payload    = substr($data, 8 + $hmacLen);
        // Re-derive HMAC bound to the key + expires + payload (see set()).
        $expected   = hash_hmac(
            'sha256',
            'v2|' . $key . '|' . $expires . '|' . $payload,
            APP_SECRET,
            true,
        );

        if (!hash_equals($expected, $storedHmac)) {
            @unlink($file);
            return null;
        }

        // Defence-in-depth: refuse to materialise objects from cache.
        $value = @unserialize($payload, ['allowed_classes' => false]);
        return ($value === false && $payload !== serialize(false)) ? null : $value;
    }

    public static function delete(string $key): void
    {
        @unlink(self::keyToPath($key));
    }

    /** @return array{files:int,dirs:int,errors:int} */
    public static function flushResponses(): array
    {
        self::bootstrap();
        $stats = ['files' => 0, 'dirs' => 0, 'errors' => 0];
        self::clearDirectory(NCACHE_DIR . DIRECTORY_SEPARATOR . 'responses', $stats, false);
        return $stats;
    }

    /** @return array{files:int,dirs:int,errors:int} */
    public static function wipe(bool $includeLogs = false): array
    {
        self::bootstrap();
        $stats = ['files' => 0, 'dirs' => 0, 'errors' => 0];

        $root = realpath(NCACHE_DIR);
        if ($root === false || !is_dir($root)) {
            self::$dirReady = false;
            self::bootstrap();
            return $stats;
        }

        $logDir = realpath(dirname(ERROR_LOG_FILE));

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME) as $item) {
            if (!$includeLogs && $logDir !== false && realpath($item) === $logDir) continue;
            self::deletePath($item, $stats);
        }

        self::$dirReady = false;
        self::bootstrap();
        return $stats;
    }

    public static function logError(string $message, array $context = []): void
    {
        self::logEvent('error', $message, $context);
    }

    public static function logEvent(string $level, string $message, array $context = []): void
    {
        self::bootstrap();
        $level = strtolower($level);
        if (!in_array($level, ['debug', 'info', 'warn', 'error'], true)) {
            $level = 'info';
        }
        $entry = json_encode([
            'ts'      => date('c'),
            'level'   => $level,
            'message' => $message,
            'context' => self::redactContext($context),
        ], JSON_UNESCAPED_SLASHES) . "\n";

        // Lightweight size-cap rotation: when the log exceeds the configured
        // cap, rename it to .1 and start fresh. Prevents disk-fill from a
        // pathological log loop. Operators should still configure logrotate.
        $cap = defined('ROUTER_LOG_MAX_BYTES') ? (int) ROUTER_LOG_MAX_BYTES : 5 * 1024 * 1024;
        if ($cap > 0 && @is_file(ERROR_LOG_FILE) && (int) @filesize(ERROR_LOG_FILE) > $cap) {
            @rename(ERROR_LOG_FILE, ERROR_LOG_FILE . '.1');
        }

        $existed = @is_file(ERROR_LOG_FILE);
        @file_put_contents(ERROR_LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
        // Tighten log permissions on first write so a freshly created log
        // file isn't world-readable (umask may default to 0644).
        if (!$existed) @chmod(ERROR_LOG_FILE, 0640);
    }

    /**
     * Record a panic — a condition that *must* be visible to the operator
     * but cannot fail the request (silently swallowed exceptions, route
     * uniqueness violations, schema validation failures, GC errors, etc).
     *
     * Panics go to a dedicated `Panic.txt` (separate from `error.log`) so
     * they remain noticeable even when the request log is noisy. The format
     * is human-readable (one record per multi-line block) so an operator
     * can `tail -f Panic.txt` and immediately see what went wrong.
     */
    public static function panic(string $message, array $context = []): void
    {
        self::bootstrap();
        if (!defined('PANIC_LOG_FILE')) return; // bootstrap not complete

        $caller = '?';
        $self = __FILE__;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            // We want the first frame whose source file is *not* this single-file
            // router — that's the user's call site.
            $file = $frame['file'] ?? null;
            if ($file === null) continue;
            if ($file === $self) continue;
            $line = $frame['line'] ?? 0;
            $caller = "{$file}:{$line}";
            break;
        }

        $ctxJson = json_encode(self::redactContext($context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';

        $block = sprintf(
            "[%s] PANIC %s\n  caller: %s\n  context: %s\n\n",
            date('c'),
            $message,
            $caller,
            $ctxJson,
        );

        $cap = defined('ROUTER_LOG_MAX_BYTES') ? (int) ROUTER_LOG_MAX_BYTES : 5 * 1024 * 1024;
        if ($cap > 0 && @is_file(PANIC_LOG_FILE) && (int) @filesize(PANIC_LOG_FILE) > $cap) {
            @rename(PANIC_LOG_FILE, PANIC_LOG_FILE . '.1');
        }
        $existed = @is_file(PANIC_LOG_FILE);
        @file_put_contents(PANIC_LOG_FILE, $block, FILE_APPEND | LOCK_EX);
        if (!$existed) @chmod(PANIC_LOG_FILE, 0640);
    }

    /** Read the most recent N panic blocks from Panic.txt (newest first). */
    public static function panicTail(int $blocks = 20): array
    {
        if (!defined('PANIC_LOG_FILE') || !@is_file(PANIC_LOG_FILE)) return [];
        $raw = (string) @file_get_contents(PANIC_LOG_FILE);
        if ($raw === '') return [];
        // Records are separated by a blank line.
        $records = preg_split('/\n\n+/', trim($raw)) ?: [];
        $records = array_reverse($records);
        return array_slice($records, 0, max(1, $blocks));
    }

    /**
     * Redact obvious secrets from log context (passwords, tokens, authorization).
     * Production-grade logging never writes secrets in plaintext.
     */
    private static function redactContext(array $context): array
    {
        $sensitive = ['password', 'pass', 'secret', 'token', 'authorization', 'cookie',
                      'api_key', 'apikey', 'access_token', 'refresh_token', 'session', 'csrf'];
        array_walk_recursive($context, static function (&$v, $k) use ($sensitive): void {
            if (!is_string($k)) return;
            $kl = strtolower($k);
            foreach ($sensitive as $needle) {
                if (str_contains($kl, $needle)) { $v = '[REDACTED]'; return; }
            }
        });
        return $context;
    }

    public static function garbageCollect(int $lottery = 100): void
    {
        if ($lottery < 1 || random_int(1, $lottery) !== 1) return;
        self::bootstrap();

        $root = NCACHE_DIR . DIRECTORY_SEPARATOR . 'responses';
        if (!is_dir($root)) return;

        $now = time();
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) continue;
                if ($file->getMTime() < $now - 86400) {
                    @unlink($file->getPathname());
                }
            }
        } catch (Throwable $e) {
            self::logError('GC failure', ['error' => $e->getMessage()]);
        }
    }

    private static function clearDirectory(string $dir, array &$stats, bool $removeRoot): void
    {
        if (!is_dir($dir)) return;

        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME) as $item) {
            self::deletePath($item, $stats);
        }
        if ($removeRoot) {
            @rmdir($dir) ? $stats['dirs']++ : $stats['errors']++;
        }
    }

    private static function deletePath(string $path, array &$stats): void
    {
        // Refuse to follow symlinks. A malicious or accidental link inside the
        // cache directory must not let us delete arbitrary files on the host.
        if (is_link($path)) {
            @unlink($path) ? $stats['files']++ : $stats['errors']++;
            return;
        }
        if (is_dir($path)) {
            self::clearDirectory($path, $stats, true);
            return;
        }
        @unlink($path) ? $stats['files']++ : $stats['errors']++;
    }

    private static function keyToPath(string $key): string
    {
        self::bootstrap();
        $hash   = hash('sha256', $key);
        $shard  = substr($hash, 0, 2);
        $bucket = NCACHE_DIR . DIRECTORY_SEPARATOR . 'responses' . DIRECTORY_SEPARATOR . $shard;
        if (!is_dir($bucket)) @mkdir($bucket, 0750, true);
        return $bucket . DIRECTORY_SEPARATOR . substr($hash, 2) . '.bin';
    }
}

// =====================================================================
// SECTION 6 — SECURITY LAYER
// =====================================================================

final class SecurityLayer
{
    private static string $cspNonce       = '';
    private static array  $cspExtraScript = [];
    private static array  $cspExtraStyle  = [];

    /** @var array{limit:int,remaining:int,reset:int}|null */
    private static ?array $lastRateInfo = null;

    public static function emitSecurityHeaders(): void
    {
        if (headers_sent()) return;

        if (self::$cspNonce === '') {
            self::$cspNonce = base64_encode(random_bytes(16));
        }
        $nonce = self::$cspNonce;
        $extraScript = $extraStyle = '';
        if (!empty(self::$cspExtraScript)) $extraScript = ' ' . implode(' ', self::$cspExtraScript);
        if (!empty(self::$cspExtraStyle))  $extraStyle  = ' ' . implode(' ', self::$cspExtraStyle);

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-site');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}'{$extraScript}; "
            . "style-src 'self' 'nonce-{$nonce}'{$extraStyle}; "
            . "img-src 'self' data: https:; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
        header_remove('X-Powered-By');
        header_remove('Server');
    }

    public static function cspNonce(): string
    {
        if (self::$cspNonce === '') {
            self::$cspNonce = base64_encode(random_bytes(16));
        }
        return self::$cspNonce;
    }

    /** @param string[] $sources Additional script-src sources (host-source list). */
    public static function allowScriptSources(array $sources): void
    {
        foreach ($sources as $s) self::$cspExtraScript[] = $s;
    }

    /** @param string[] $sources Additional style-src sources. */
    public static function allowStyleSources(array $sources): void
    {
        foreach ($sources as $s) self::$cspExtraStyle[] = $s;
    }

    /**
     * Rolling-window IP rate limit. Returns the current bucket info on success
     * and exits the process with HTTP 429 if the limit has been exceeded.
     *
     * @return array{limit:int,remaining:int,reset:int,exceeded:bool}
     */
    public static function rateLimit(?int $max = null, ?int $window = null, ?string $bucket = null): array
    {
        CacheEngine::bootstrap();

        $max    ??= RATE_LIMIT_MAX;
        $window ??= RATE_LIMIT_WINDOW;
        if ($max <= 0 || $window <= 0) {
            return self::$lastRateInfo = ['limit' => $max, 'remaining' => $max, 'reset' => 0, 'exceeded' => false];
        }

        $ip   = self::clientIp();
        $key  = $bucket !== null ? "{$ip}|{$bucket}" : $ip;
        $file = RATE_CACHE_DIR . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $now  = time();

        $fp = @fopen($file, 'c+b');
        if ($fp === false) {
            CacheEngine::logError('Rate limiter cannot open file', ['file' => $file]);
            return self::$lastRateInfo = ['limit' => $max, 'remaining' => $max, 'reset' => 0, 'exceeded' => false];
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return self::$lastRateInfo = ['limit' => $max, 'remaining' => $max, 'reset' => 0, 'exceeded' => false];
        }

        $raw  = stream_get_contents($fp) ?: '';
        $data = $raw === '' ? [] : (json_decode($raw, true) ?: []);
        if (!is_array($data) || empty($data) || ($now - ($data['start'] ?? 0)) > $window) {
            $data = ['count' => 0, 'start' => $now];
        }
        $data['count']++;

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $reset     = (int) (($data['start'] ?? $now) + $window);
        $remaining = max(0, $max - (int) $data['count']);
        $exceeded  = $data['count'] > $max;

        self::$lastRateInfo = [
            'limit'     => $max,
            'remaining' => $remaining,
            'reset'     => $reset,
            'exceeded'  => $exceeded,
        ];

        if ($exceeded) {
            self::sendRateLimited($window, $reset, $max);
        }

        return self::$lastRateInfo;
    }

    /** Emit X-RateLimit-* headers from the most recent rate-limit check. */
    public static function emitRateLimitHeaders(): void
    {
        if (self::$lastRateInfo === null || headers_sent()) return;
        header('X-RateLimit-Limit: '     . self::$lastRateInfo['limit']);
        header('X-RateLimit-Remaining: ' . self::$lastRateInfo['remaining']);
        if (self::$lastRateInfo['reset'] > 0) {
            header('X-RateLimit-Reset: ' . self::$lastRateInfo['reset']);
        }
    }

    private static function sendRateLimited(int $window, int $reset, int $max): void
    {
        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $reset - time()));
            header('X-RateLimit-Limit: '     . $max);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: '     . $reset);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo self::buildErrorPage(429, 'Too Many Requests',
            'You have exceeded the request rate limit. Please wait and try again.');
        exit;
    }

    public static function csrfToken(): string
    {
        self::ensureSession();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        // Double-submit: also drop a non-HttpOnly cookie so SPAs can echo it back as X-CSRF-Token.
        if (!isset($_COOKIE['XSRF-TOKEN']) || $_COOKIE['XSRF-TOKEN'] !== $_SESSION['_csrf_token']) {
            self::setSecureCookie('XSRF-TOKEN', $_SESSION['_csrf_token'], httpOnly: false);
        }
        return $_SESSION['_csrf_token'];
    }

    public static function csrfValidate(?string $submitted = null): bool
    {
        self::ensureSession();
        $expected = (string) ($_SESSION['_csrf_token'] ?? '');
        if ($expected === '') return false;

        // Accept the three field names commonly emitted by templates in the
        // wild (Laravel: _token, plain-PHP: _csrf, Symfony: csrf_token) so
        // forms shipped by sibling components don't silently 403 on submit.
        $submitted ??= $_POST['_token']
            ?? $_POST['_csrf']
            ?? $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? '';

        if (!is_string($submitted) || $submitted === '') return false;

        $cookieMatch = isset($_COOKIE['XSRF-TOKEN'])
            && hash_equals((string) $_COOKIE['XSRF-TOKEN'], $expected);
        $bodyMatch = hash_equals($expected, $submitted);

        return $cookieMatch && $bodyMatch;
    }

    public static function csrfRotate(): void
    {
        self::ensureSession();
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        self::setSecureCookie('XSRF-TOKEN', $_SESSION['_csrf_token'], httpOnly: false);
    }

    private static function setSecureCookie(string $name, string $value, bool $httpOnly): void
    {
        if (headers_sent()) return;
        setcookie($name, $value, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;

        // Forwarded-proto headers are only honoured when REMOTE_ADDR is in the
        // configured TRUSTED_PROXIES list. Otherwise an attacker can spoof
        // X-Forwarded-Proto to make the framework believe the request is HTTPS.
        $trusted = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
        if (!empty($trusted)) {
            $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if ($remote !== '' && self::ipInRanges($remote, $trusted)) {
                if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
                if (!empty($_SERVER['HTTP_X_ARR_SSL'])) return true;
            }
        }
        return false;
    }

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure'   => self::isHttps(),
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
    }

    /**
     * Sanitise URI path parameters captured by the trie.
     * Strips null bytes and normalises UTF-8. NOT a path-traversal guard;
     * callers that resolve filesystem paths from params must validate explicitly.
     *
     * mbstring/iconv are optional in many PHP builds. Calling
     * mb_convert_encoding() unconditionally meant every dynamic / wildcard
     * route 500'd with "Call to undefined function mb_convert_encoding()" on
     * any host without ext-mbstring. Probe for the available extension and
     * fall back to a portable strip when neither is loaded.
     */
    public static function sanitiseParam(string $value): string
    {
        $value = str_replace("\0", '', $value);

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (is_string($converted)) return $converted;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($converted)) return $converted;
        }

        if (preg_match('//u', $value) === 1) return $value;

        $clean = preg_replace('/[\x80-\xFF]+/', '', $value);
        return is_string($clean) ? $clean : '';
    }

    /**
     * Collect request input. JSON bodies are parsed and validated.
     * @return array{get:array,post:array,json:array,files:array,raw:string}
     */
    public static function collectInput(): array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0] ?? ''));
        $json = [];
        $raw  = '';

        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $maxBody = defined('ROUTER_MAX_BODY_BYTES') ? (int) ROUTER_MAX_BODY_BYTES : 2 * 1024 * 1024;
            // Reject early when Content-Length already exceeds the cap.
            if ($maxBody > 0 && $contentLength > $maxBody) {
                http_response_code(413);
                header('Content-Type: application/json');
                echo json_encode(['error' => "Payload too large (max {$maxBody} bytes)"]);
                exit;
            }
            // Defence-in-depth: stream php://input with a hard cap so a
            // dishonest Content-Length / chunked transfer-encoding can't
            // smuggle a body larger than the configured limit.
            $fp = @fopen('php://input', 'rb');
            if ($fp !== false) {
                $cap = $maxBody > 0 ? $maxBody : PHP_INT_MAX;
                $raw = (string) stream_get_contents($fp, $cap + 1);
                fclose($fp);
                if ($maxBody > 0 && strlen($raw) > $maxBody) {
                    http_response_code(413);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => "Payload too large (max {$maxBody} bytes)"]);
                    exit;
                }
            }
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'error'  => 'Malformed JSON payload',
                        'detail' => json_last_error_msg(),
                    ]);
                    exit;
                }
                if (is_array($decoded)) $json = $decoded;
            }
        }

        return [
            'get'   => $_GET,
            'post'  => $_POST,
            'json'  => $json,
            'files' => $_FILES,
            'raw'   => $raw,
        ];
    }

/**
     * Overengineered IP address resolution.
     * Safely traverses extreme proxy chains, parses RFC 7239 Forwarded headers,
     * strips ports and IPv6 brackets, and prioritizes actual public client IPs.
     */
    public static function clientIp(): string
    {
        $remoteAddr = self::cleanIp($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') ?? '0.0.0.0';

        $trusted = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
        
        // Security Boundary: If REMOTE_ADDR is not a trusted proxy, headers cannot be trusted.
        if (empty($trusted) || !self::ipInRanges($remoteAddr, $trusted)) {
            return $remoteAddr;
        }

        // Exhaustive Vendor & Standard Header List (Ordered by reliability)
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_TRUE_CLIENT_IP',       // Akamai / Cloudflare Enterprise
            'HTTP_FASTLY_CLIENT_IP',     // Fastly
            'HTTP_INCAP_CLIENT_IP',      // Incapsula
            'HTTP_X_SUCURI_CLIENTIP',    // Sucuri
            'HTTP_X_FORWARDED_FOR',      // HAProxy, Squid, Nginx, Standard
            'HTTP_X_REAL_IP',            // Nginx Standard
            'HTTP_FORWARDED_FOR',        // RFC 7239 subset
            'HTTP_FORWARDED',            // RFC 7239 compliant
            'HTTP_X_CLIENT_IP',          // General proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Rackspace
            'HTTP_CLIENT_IP',            // Legacy
        ];

        $candidates = [];

        foreach ($headers as $header) {
            $value = trim((string) ($_SERVER[$header] ?? ''));
            if ($value === '') continue;

            // RFC 7239 'Forwarded' header (e.g. Forwarded: for="[2001:db8::1]:8080";proto=http)
            if ($header === 'HTTP_FORWARDED') {
                if (preg_match_all('/for="?\[?([a-f0-9\.:]+)\]?"?/i', $value, $matches)) {
                    foreach ($matches[1] as $ipMatch) $candidates[] = $ipMatch;
                }
                continue;
            }

            // Explode comma-separated chains (e.g. X-Forwarded-For: client, proxy1, proxy2)
            foreach (explode(',', $value) as $segment) {
                $candidates[] = $segment;
            }
        }

        // Validate and clean all gathered IPs
        $validIps = [];
        foreach ($candidates as $cand) {
            $cleaned = self::cleanIp($cand);
            if ($cleaned !== null) {
                $validIps[] = $cleaned;
            }
        }

        if (empty($validIps)) {
            return $remoteAddr;
        }

        // Selection: Iterate left-to-right (client -> closest proxy).
        // Pick the first purely PUBLIC IP to bypass internal NAT routing.
        foreach ($validIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // Fallback: If all proxies/clients are on private networks (e.g. local dev), 
        // return the left-most client IP in the chain.
        return $validIps[0];
    }

    /**
     * Sanitizes an IP string by removing port numbers and IPv6 brackets.
     */
    private static function cleanIp(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') return null;

        // Strip IPv6 brackets
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        // Strip port if present
        $colons = substr_count($ip, ':');
        if ($colons === 1) {
            // IPv4 with port (e.g., 192.168.1.1:8080)
            $ip = substr($ip, 0, (int) strpos($ip, ':'));
        } elseif ($colons > 1 && str_contains($ip, ']:')) {
            // Edge case: unstripped IPv6 with port (e.g. [::1]:80)
            $ip = substr($ip, 0, (int) strrpos($ip, ']:'));
            $ip = ltrim($ip, '[');
        }

        // Final strict validation
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /** @param string[] $ranges */
    public static function ipInRanges(string $ip, array $ranges): bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) return false;
        $addrLen = strlen($packedIp);

        foreach ($ranges as $range) {
            if (!str_contains($range, '/')) {
                $packed = @inet_pton($range);
                if ($packed !== false && $packedIp === $packed) return true;
                continue;
            }
            [$subnet, $prefixLen] = explode('/', $range, 2);
            $prefixLen = (int) $prefixLen;
            $packedSubnet = @inet_pton($subnet);
            if ($packedSubnet === false || strlen($packedSubnet) !== $addrLen) continue;

            $mask = '';
            for ($i = 0; $i < $addrLen; $i++) {
                $bits = max(0, min(8, $prefixLen - ($i * 8)));
                $mask .= match ($bits) {
                    8 => "\xff",
                    0 => "\x00",
                    default => chr((0xff << (8 - $bits)) & 0xff),
                };
            }
            if (($packedIp & $mask) === ($packedSubnet & $mask)) return true;
        }
        return false;
    }

    public static function buildErrorPage(int $code, string $title, string $message): string
    {
        $st = htmlspecialchars($title,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sm = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\">"
             . "<title>{$code} {$st}</title></head>"
             . "<body><h1>{$code} {$st}</h1><p>{$sm}</p></body></html>";
    }
}

// =====================================================================
// SECTION 6.5 — CSP FLUENT BUILDER (rebuildable Content-Security-Policy)
// =====================================================================

/**
 * Csp — fluent builder for `Content-Security-Policy` (or
 * `Content-Security-Policy-Report-Only`) headers. Replaces the header emitted
 * by `SecurityLayer::emitSecurityHeaders()` when `apply()` is called.
 *
 *   R::csp()
 *     ->scripts(["'self'", 'cdn.jsdelivr.net'])
 *     ->styles( ["'self'", "'unsafe-inline'"])
 *     ->images( ["'self'", 'data:', 'https://images.example.com'])
 *     ->connect(["'self'", 'wss://realtime.example.com'])
 *     ->frameAncestors(["'none'"])
 *     ->upgradeInsecure()
 *     ->reportTo('csp-endpoint')
 *     ->apply();
 *
 *   // Staging: complain but do not enforce.
 *   R::csp()->reportOnly()->apply();
 *
 * `withNonce()` injects the active per-request CSP nonce into both `script-src`
 * and `style-src`; `strictDynamic()` adds `'strict-dynamic'` to `script-src`.
 */
final class Csp
{
    /** @var array<string,array<int,string>> */
    private array $directives = [];
    /** @var array<int,string> */
    private array $flags = [];
    private bool $reportOnly = false;
    private string $reportUri = '';
    private string $reportTo  = '';

    public static function make(): self { return new self(); }

    public function defaults(string|array $sources): self                       { return $this->push('default-src',     $sources); }
    public function scripts(string|array $sources): self                        { return $this->push('script-src',      $sources); }
    public function styles(string|array $sources): self                         { return $this->push('style-src',       $sources); }
    public function images(string|array $sources): self                         { return $this->push('img-src',         $sources); }
    public function fonts(string|array $sources): self                          { return $this->push('font-src',        $sources); }
    public function connect(string|array $sources): self                        { return $this->push('connect-src',     $sources); }
    public function media(string|array $sources): self                          { return $this->push('media-src',       $sources); }
    public function objects(string|array $sources): self                        { return $this->push('object-src',      $sources); }
    public function frames(string|array $sources): self                         { return $this->push('frame-src',       $sources); }
    public function workers(string|array $sources): self                        { return $this->push('worker-src',      $sources); }
    public function manifests(string|array $sources): self                      { return $this->push('manifest-src',    $sources); }
    public function frameAncestors(string|array $sources): self                 { return $this->push('frame-ancestors', $sources); }
    public function formAction(string|array $sources): self                     { return $this->push('form-action',     $sources); }
    public function baseUri(string|array $sources): self                        { return $this->push('base-uri',        $sources); }

    /** Add raw directive — escape hatch for any non-standard directive. */
    public function directive(string $name, string|array $sources): self        { return $this->push($name, $sources); }

    /** Add `script-src 'unsafe-inline'`. Discouraged — only for legacy systems. */
    public function unsafeInline(): self  { return $this->push('script-src', "'unsafe-inline'")->push('style-src', "'unsafe-inline'"); }
    public function unsafeEval(): self    { return $this->push('script-src', "'unsafe-eval'"); }
    public function strictDynamic(): self { return $this->push('script-src', "'strict-dynamic'"); }

    /** Append the per-request nonce to script-src and style-src. */
    public function withNonce(?string $nonce = null): self
    {
        $n = $nonce ?? SecurityLayer::cspNonce();
        return $this->push('script-src', "'nonce-{$n}'")
                    ->push('style-src',  "'nonce-{$n}'");
    }

    /** Add Trusted Types policy directive (drops sinks unless typed). */
    public function trustedTypes(string ...$policies): self
    {
        $this->directives['require-trusted-types-for'] = ["'script'"];
        $this->directives['trusted-types']             = $policies;
        return $this;
    }

    public function upgradeInsecure(): self     { $this->flags[] = 'upgrade-insecure-requests';  return $this; }
    public function blockAllMixedContent(): self{ $this->flags[] = 'block-all-mixed-content';    return $this; }
    public function sandbox(string ...$tokens): self
    {
        $this->directives['sandbox'] = $tokens;
        return $this;
    }

    public function reportUri(string $uri): self { $this->reportUri = $uri; return $this; }
    public function reportTo(string $group): self { $this->reportTo  = $group; return $this; }

    /** Switch to Content-Security-Policy-Report-Only mode (audit-only). */
    public function reportOnly(bool $on = true): self { $this->reportOnly = $on; return $this; }

    /** Build the header **value** (without the `Content-Security-Policy: ` prefix). */
    public function build(): string
    {
        $parts = [];
        foreach ($this->directives as $name => $sources) {
            $sources = array_values(array_unique(array_filter(array_map('strval', $sources), 'strlen')));
            if (empty($sources) && $name !== 'sandbox') continue;
            $parts[] = $name . (empty($sources) ? '' : ' ' . implode(' ', $sources));
        }
        foreach (array_unique($this->flags) as $flag) {
            $parts[] = $flag;
        }
        if ($this->reportUri !== '') $parts[] = 'report-uri ' . $this->reportUri;
        if ($this->reportTo  !== '') $parts[] = 'report-to '  . $this->reportTo;
        return implode('; ', $parts);
    }

    /** Emit the header, replacing any previous CSP header for this response. */
    public function apply(): void
    {
        if (headers_sent()) return;
        $name = $this->reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        // Remove any prior CSP header (both modes) so apply() is idempotent.
        header_remove('Content-Security-Policy');
        header_remove('Content-Security-Policy-Report-Only');
        header($name . ': ' . $this->build());
    }

    /** Return the underlying directive map (debug / tests). */
    public function toArray(): array
    {
        return [
            'directives' => $this->directives,
            'flags'      => array_values(array_unique($this->flags)),
            'reportOnly' => $this->reportOnly,
            'reportUri'  => $this->reportUri,
            'reportTo'   => $this->reportTo,
        ];
    }

    private function push(string $directive, string|array $sources): self
    {
        $sources = is_array($sources) ? $sources : [$sources];
        if (!isset($this->directives[$directive])) $this->directives[$directive] = [];
        foreach ($sources as $s) {
            $s = trim((string) $s);
            if ($s !== '') $this->directives[$directive][] = $s;
        }
        return $this;
    }
}

// =====================================================================
// SECTION 7 — DOMAIN RESOLVER (multi-domain static fallback + matching)
// =====================================================================

final class DomainResolver
{
    /** @var array<string,string> host => folder */
    private static array $runtimeMap = [];

    /** @param array<string,string> $map */
    public static function set(array $map): void
    {
        self::$runtimeMap = self::normaliseMap($map);
    }

    /** @param string|string[] $hosts */
    public static function add(string|array $hosts, string $folder): void
    {
        $folder = self::normaliseFolder($folder);
        foreach ((array) $hosts as $host) {
            $h = self::normaliseHost((string) $host);
            if ($h !== '' && $folder !== '') self::$runtimeMap[$h] = $folder;
        }
    }

    /** @return array<string,string> */
    public static function map(): array
    {
        $envMap = defined('ROUTER_DOMAIN_FOLDERS') && is_array(ROUTER_DOMAIN_FOLDERS)
            ? ROUTER_DOMAIN_FOLDERS : [];
        return array_replace(self::normaliseMap($envMap), self::$runtimeMap);
    }

    public static function currentHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return self::normaliseHost((string) $host);
    }

    public static function currentFolder(): ?string
    {
        return self::matchHost(self::currentHost(), self::map());
    }

    public static function currentFolderPath(): ?string
    {
        $folder = self::currentFolder();
        return ($folder === null || $folder === '') ? null : self::folderToPath($folder);
    }

    /**
     * Test whether the current host matches any pattern in the supplied list.
     * Patterns may be exact (`example.com`), wildcard (`*.example.com`),
     * empty (matches everything), or `*` (matches everything).
     *
     * @param string[] $patterns
     */
    public static function hostMatches(array $patterns, ?string $host = null): bool
    {
        $host ??= self::currentHost();
        if (empty($patterns)) return true;
        foreach ($patterns as $pattern) {
            $p = self::normaliseHost((string) $pattern);
            if ($p === '' || $p === '*') return true;
            if ($p === $host) return true;
            if (str_starts_with($p, '*.')) {
                $suffix = substr($p, 1); // ".example.com"
                if (str_ends_with($host, $suffix)) return true;
                // Allow bare apex: "*.example.com" matches "example.com" too.
                if ($host === ltrim($suffix, '.')) return true;
            }
        }
        return false;
    }

    /**
     * Normalise a host or list of hosts (lowercase, strip port, trim trailing dot).
     * @param string|string[] $hosts
     * @return string[]
     */
    public static function normaliseHosts(string|array $hosts): array
    {
        $out = [];
        foreach ((array) $hosts as $h) {
            $n = self::normaliseHost((string) $h);
            if ($n !== '') $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    public static function serveStaticIfAvailable(string $uri): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!ROUTER_STATIC_FILE_SERVING || !in_array($method, ['GET', 'HEAD'], true)) return;

        $basePath = self::currentFolderPath();
        if ($basePath === null) return;

        $baseReal = realpath($basePath);
        if ($baseReal === false || !is_dir($baseReal)) return;

        $path     = parse_url($uri, PHP_URL_PATH) ?: '/';
        $relative = rawurldecode(ltrim($path, '/'));
        if ($relative === '' || str_contains($relative, "\0")) return;

        $relative  = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $candidate = realpath($baseReal . DIRECTORY_SEPARATOR . $relative);
        $prefix    = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($candidate === false || !is_file($candidate) || !is_readable($candidate)) return;
        if (!str_starts_with($candidate, $prefix)) return;

        $blocked = ['php','phtml','phar','cgi','pl','py','rb','env','ini','log','sql',
                    'sh','bash','bat','cmd','ps1','ncache','lock','htaccess','htpasswd'];
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        if (in_array($ext, $blocked, true)) return;

        if (!headers_sent()) {
            header('Content-Type: ' . self::mimeType($candidate));
            header('Content-Length: ' . filesize($candidate));
            header('Cache-Control: ' . self::cacheControlFor($candidate));
            $mtime = filemtime($candidate);
            if ($mtime !== false) header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            header('X-Router-Static: domain-folder');
        }
        if ($method !== 'HEAD') readfile($candidate);
        exit;
    }

    /** Fingerprinted asset names (e.g. app.a3f9c2.js) get long immutable cache. */
    private static function cacheControlFor(string $path): string
    {
        $base = pathinfo($path, PATHINFO_BASENAME);
        // matches name.<6+hex>.ext or name-<6+hex>.ext
        if (preg_match('/[._-][0-9a-f]{6,}\.[a-z0-9]+$/i', $base)) {
            return 'public, max-age=31536000, immutable';
        }
        return 'public, max-age=3600, must-revalidate';
    }

    /** @param array<string,string> $map */
    private static function normaliseMap(array $map): array
    {
        $out = [];
        foreach ($map as $host => $folder) {
            if (!is_string($host) || !is_string($folder)) continue;
            $h = self::normaliseHost($host);
            $f = self::normaliseFolder($folder);
            if ($h !== '' && $f !== '') $out[$h] = $f;
        }
        return $out;
    }

    public static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return rtrim($host, '.');
    }

    private static function normaliseFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder));
        return rtrim($folder, '/');
    }

    /** @param array<string,string> $map */
    private static function matchHost(string $host, array $map): ?string
    {
        if (isset($map[$host])) return $map[$host];

        // Wildcard matching: *.example.com matches a.example.com and a.b.example.com.
        foreach ($map as $pattern => $folder) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (str_ends_with($host, $suffix)) return $folder;
            }
        }
        return null;
    }

    private static function folderToPath(string $folder): string
    {
        if (self::isAbsolutePath($folder)) return $folder;
        return ROUTER_BASE_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($folder, '/'));
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') return false;
        if ($path[0] === '/' || $path[0] === '\\') return true;
        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    public static function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'html', 'htm'   => 'text/html; charset=UTF-8',
            'css'           => 'text/css; charset=UTF-8',
            'js', 'mjs'     => 'application/javascript; charset=UTF-8',
            'json'          => 'application/json; charset=UTF-8',
            'xml'           => 'application/xml; charset=UTF-8',
            'svg'           => 'image/svg+xml',
            'png'           => 'image/png',
            'jpg', 'jpeg'   => 'image/jpeg',
            'webp'          => 'image/webp',
            'avif'          => 'image/avif',
            'gif'           => 'image/gif',
            'ico'           => 'image/x-icon',
            'woff'          => 'font/woff',
            'woff2'         => 'font/woff2',
            'ttf'           => 'font/ttf',
            'otf'           => 'font/otf',
            'pdf'           => 'application/pdf',
            'txt', 'md'     => 'text/plain; charset=UTF-8',
            'mp4'           => 'video/mp4',
            'webm'          => 'video/webm',
            'wasm'          => 'application/wasm',
            default         => 'application/octet-stream',
        };
    }
}

// =====================================================================
// SECTION 8 — SERVER CONFIG GENERATOR (CLI use only; not auto-invoked)
// =====================================================================

final class ServerConfigGenerator
{
    /** Idempotent. Generates only files that are missing. */
    public static function ensureConfigs(): void
    {
        $entry = ROUTER_ENTRY_FILE;
        self::ensureHtaccess($entry);
        self::ensureWebConfig($entry);
        self::ensureNginxSnippet($entry);
    }

    private static function ensureHtaccess(string $entryFile): void
    {
        $path = ROUTER_BASE_DIR . DIRECTORY_SEPARATOR . '.htaccess';
        if (file_exists($path)) return;

        $tpl = <<<HTACCESS
            # Auto-generated by Router::generateServerConfigs(). Front controller: {$entryFile}
            Options -Indexes -MultiViews
            DirectoryIndex {$entryFile}

            <FilesMatch "(^\.|\.)(ncache|log|env|bak|sql|sh|bash|ini|lock|phar|phtml)$">
                Require all denied
            </FilesMatch>

            <IfModule mod_rewrite.c>
                RewriteEngine On
                RewriteRule ^\.ncache(/.*)?$ - [F,L]
                RewriteRule (^|/)\.(?!well-known/) - [F,L]
                RewriteCond %{REQUEST_FILENAME} -f [OR]
                RewriteCond %{REQUEST_FILENAME} -d
                RewriteRule ^ - [L]
                RewriteRule ^ {$entryFile} [L,QSA]
            </IfModule>

            <IfModule mod_headers.c>
                Header always set X-Content-Type-Options "nosniff"
                Header always set X-Frame-Options "DENY"
                Header always set Referrer-Policy "strict-origin-when-cross-origin"
                Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
                Header always unset X-Powered-By
                Header always unset Server
            </IfModule>

            <IfModule mod_deflate.c>
                AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json image/svg+xml
            </IfModule>

            <IfModule mod_expires.c>
                ExpiresActive On
                ExpiresByType image/jpeg              "access plus 1 year"
                ExpiresByType image/png               "access plus 1 year"
                ExpiresByType image/webp              "access plus 1 year"
                ExpiresByType image/svg+xml           "access plus 1 year"
                ExpiresByType text/css                "access plus 1 month"
                ExpiresByType application/javascript  "access plus 1 month"
            </IfModule>
            HTACCESS;

        @file_put_contents($path, $tpl);
    }

    private static function ensureWebConfig(string $entryFile): void
    {
        $path = ROUTER_BASE_DIR . DIRECTORY_SEPARATOR . 'web.config';
        if (file_exists($path)) return;

        $tpl = <<<WEBCONFIG
            <?xml version="1.0" encoding="UTF-8"?>
            <configuration>
              <system.webServer>
                <rewrite>
                  <rules>
                    <rule name="Block ncache" stopProcessing="true">
                      <match url="^\.ncache" />
                      <action type="CustomResponse" statusCode="403" />
                    </rule>
                    <rule name="Block sensitive" stopProcessing="true">
                      <match url="(^\.|\.)(ncache|log|env|bak|sql|sh|bash|ini|lock|phar|phtml)$" />
                      <action type="CustomResponse" statusCode="403" />
                    </rule>
                    <rule name="Front controller" stopProcessing="true">
                      <match url="^(.*)\$" />
                      <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                      </conditions>
                      <action type="Rewrite" url="{$entryFile}" appendQueryString="true" />
                    </rule>
                  </rules>
                </rewrite>
                <httpProtocol>
                  <customHeaders>
                    <add name="X-Content-Type-Options" value="nosniff" />
                    <add name="X-Frame-Options" value="DENY" />
                    <add name="Referrer-Policy" value="strict-origin-when-cross-origin" />
                    <add name="Strict-Transport-Security" value="max-age=31536000; includeSubDomains; preload" />
                  </customHeaders>
                </httpProtocol>
              </system.webServer>
            </configuration>
            WEBCONFIG;

        @file_put_contents($path, $tpl);
    }

    private static function ensureNginxSnippet(string $entryFile): void
    {
        $path = ROUTER_BASE_DIR . DIRECTORY_SEPARATOR . 'nginx.conf.example';
        if (file_exists($path)) return;

        $tpl = <<<NGINX
            # Drop-in nginx server-block snippet. Tune to your stack.
            location / {
                try_files \$uri \$uri/ /{$entryFile}\$is_args\$args;
            }
            location ~ /\.ncache { deny all; }
            location ~ \.(env|ini|log|sql|bash|sh|bak|lock|phar|phtml)\$ { deny all; }
            location ~ \.php\$ {
                include snippets/fastcgi-php.conf;
                fastcgi_pass unix:/run/php/php8.1-fpm.sock;
            }
            add_header X-Content-Type-Options "nosniff" always;
            add_header X-Frame-Options "DENY" always;
            add_header Referrer-Policy "strict-origin-when-cross-origin" always;
            add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
            NGINX;

        @file_put_contents($path, $tpl);
    }
}

// =====================================================================
// SECTION 9 — PROFILER (debug-only timing + traversal log)
// =====================================================================

final class Profiler
{
    private static float  $startTime    = 0.0;
    private static int    $startMemory  = 0;
    /** @var array<int,array{label:string,ms:float,memKb:float}> */
    private static array  $checkpoints  = [];
    /** @var string[] */
    private static array  $traversalLog = [];

    public static function start(): void
    {
        self::$startTime    = microtime(true);
        self::$startMemory  = memory_get_usage();
        self::$checkpoints  = [];
        self::$traversalLog = [];
    }

    public static function checkpoint(string $label): void
    {
        self::$checkpoints[] = [
            'label' => $label,
            'ms'    => (microtime(true) - self::$startTime) * 1000,
            'memKb' => (memory_get_usage() - self::$startMemory) / 1024,
        ];
    }

    public static function logTraversal(string $event): void
    {
        self::$traversalLog[] = $event;
    }

    public static function elapsedMs(): float    { return (microtime(true) - self::$startTime) * 1000; }
    public static function memoryKb(): float     { return memory_get_usage() / 1024; }
    public static function peakMemoryKb(): float { return memory_get_peak_usage() / 1024; }
    /** @return array<int,array{label:string,ms:float,memKb:float}> */
    public static function getCheckpoints(): array  { return self::$checkpoints; }
    /** @return string[] */
    public static function getTraversalLog(): array { return self::$traversalLog; }
}

// =====================================================================
// SECTION 10 — RATE LIMIT SPEC PARSER
// =====================================================================

final class RateSpec
{
    /**
     * Parse a human-friendly rate specification into ['max'=>int,'window'=>int].
     *
     * Accepts:
     *   "60/min"       => 60 requests / 60s
     *   "60/m"         => 60 requests / 60s
     *   "100/hour"     => 100 requests / 3600s
     *   "1000/h"       => 1000 requests / 3600s
     *   "10/day"       => 10 requests / 86400s
     *   "5/10s"        => 5 requests / 10s
     *   "5 per 10s"    => 5 requests / 10s
     *   "60/sec"       => 60 requests / 1s
     *   int $max + int $window => verbatim
     *
     * @return array{max:int,window:int}
     */
    public static function parse(int|string $maxOrSpec, ?int $window = null): array
    {
        if (is_int($maxOrSpec)) {
            return [
                'max'    => max(0, $maxOrSpec),
                'window' => max(0, (int) ($window ?? RATE_LIMIT_WINDOW)),
            ];
        }

        $spec = strtolower(trim((string) $maxOrSpec));
        $spec = preg_replace('/\s+per\s+/', '/', $spec) ?? $spec;
        $spec = str_replace(' ', '', $spec);

        if (!preg_match('#^(\d+)/(\d+)?([a-z]+)?$#', $spec, $m)) {
            throw new InvalidArgumentException("Invalid rate spec: {$maxOrSpec}");
        }

        $max     = (int) $m[1];
        $count   = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
        $unit    = $m[3] ?? '';
        $seconds = self::unitToSeconds($unit);

        return ['max' => max(0, $max), 'window' => max(0, $count * $seconds)];
    }

    private static function unitToSeconds(string $unit): int
    {
        return match ($unit) {
            '', 's', 'sec', 'secs', 'second', 'seconds' => 1,
            'm', 'min', 'mins', 'minute', 'minutes'     => 60,
            'h', 'hr', 'hrs', 'hour', 'hours'           => 3600,
            'd', 'day', 'days'                          => 86400,
            'w', 'wk', 'wks', 'week', 'weeks'           => 604800,
            default => throw new InvalidArgumentException("Unknown rate unit: {$unit}"),
        };
    }
}

// =====================================================================
// SECTION 11 — ROUTE FLUENT BUILDER
// =====================================================================

final class Route
{
    /** @var array<int,array{node:TrieNode,method:string,pattern:string}> */
    private array $bindings;

    /** @internal */
    public function __construct(array $bindings)
    {
        $this->bindings = $bindings;
    }

    public function name(string $name): self
    {
        if (!empty($this->bindings)) {
            $first = $this->bindings[0];
            $first['node']->name = $name;
            Router::registerName($name, $first['pattern']);
        }
        return $this;
    }

    /** Per-route rate limit. Accepts ('60/min'), (60, 60), or (60). */
    public function rate(int|string $maxOrSpec, ?int $window = null, ?string $bucket = null): self
    {
        $rate = RateSpec::parse($maxOrSpec, $window);
        foreach ($this->bindings as $b) {
            $b['node']->methodRate[$b['method']] = $rate + ['bucket' => $bucket];
        }
        return $this;
    }

    public function cacheable(bool $on = true): self
    {
        foreach ($this->bindings as $b) {
            $b['node']->methodCacheable[$b['method']] = $on;
        }
        return $this;
    }

    public function seo(SeoMeta $meta): self
    {
        foreach ($this->bindings as $b) {
            $b['node']->methodSeo[$b['method']] = $meta;
        }
        return $this;
    }

    /** Append additional middleware to this route's chain. */
    public function middleware(callable ...$mws): self
    {
        foreach ($this->bindings as $b) {
            $existing = $b['node']->methodMiddleware[$b['method']] ?? [];
            $b['node']->methodMiddleware[$b['method']] = array_merge($existing, $mws);
        }
        return $this;
    }

    /** Bind this route to an explicit allow-list of host(s). Wildcards supported. */
    public function domains(string|array $hosts): self
    {
        $list = DomainResolver::normaliseHosts($hosts);
        foreach ($this->bindings as $b) {
            $b['node']->methodAllowHosts[$b['method']] = array_values(array_unique(array_merge(
                $b['node']->methodAllowHosts[$b['method']] ?? [],
                $list,
            )));
        }
        return $this;
    }

    /** Block this route on specific host(s); takes precedence over domains(). */
    public function except(string|array $hosts): self
    {
        $list = DomainResolver::normaliseHosts($hosts);
        foreach ($this->bindings as $b) {
            $b['node']->methodBlockHosts[$b['method']] = array_values(array_unique(array_merge(
                $b['node']->methodBlockHosts[$b['method']] ?? [],
                $list,
            )));
        }
        return $this;
    }

    /** @internal Return the underlying bindings (for Router::map merging). */
    public function __bindings__(): array { return $this->bindings; }
}

// =====================================================================
// SECTION 12 — ROUTE SCOPE (Router::on / Router::except chainable scope)
// =====================================================================

final class RouteScope
{
    /**
     * @param string[] $allow
     * @param string[] $block
     */
    public function __construct(
        public readonly array $allow = [],
        public readonly array $block = [],
    ) {
    }

    public function on(string|array $hosts): self
    {
        return new self(
            allow: array_values(array_unique(array_merge($this->allow, DomainResolver::normaliseHosts($hosts)))),
            block: $this->block,
        );
    }

    public function except(string|array $hosts): self
    {
        return new self(
            allow: $this->allow,
            block: array_values(array_unique(array_merge($this->block, DomainResolver::normaliseHosts($hosts)))),
        );
    }

    public function get(string $p, callable $h, array $mw = [], array $opts = []): Route
    {
        return $this->register('GET', $p, $h, $mw, $opts);
    }
    public function post(string $p, callable $h, array $mw = [], array $opts = []): Route
    {
        return $this->register('POST', $p, $h, $mw, $opts);
    }
    public function put(string $p, callable $h, array $mw = [], array $opts = []): Route
    {
        return $this->register('PUT', $p, $h, $mw, $opts);
    }
    public function delete(string $p, callable $h, array $mw = [], array $opts = []): Route
    {
        return $this->register('DELETE', $p, $h, $mw, $opts);
    }
    public function patch(string $p, callable $h, array $mw = [], array $opts = []): Route
    {
        return $this->register('PATCH', $p, $h, $mw, $opts);
    }

    /** @param string[] $methods */
    public function map(array $methods, string $p, callable $h, array $mw = [], array $opts = []): Route
    {
        $bindings = [];
        foreach ($methods as $method) {
            $r = $this->register(strtoupper($method), $p, $h, $mw, $opts);
            foreach ($r->__bindings__() as $b) $bindings[] = $b;
        }
        return new Route($bindings);
    }

    public function any(string $p, callable $h, array $mw = [], array $opts = []): Route
    {
        return $this->map(['GET','POST','PUT','DELETE','PATCH'], $p, $h, $mw, $opts);
    }

    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        Router::pushScope($this);
        try {
            Router::group($prefix, $callback, $middleware);
        } finally {
            Router::popScope();
        }
    }

    private function register(string $method, string $pattern, callable $h, array $mw, array $opts): Route
    {
        $opts['_scope'] = $this;
        return Router::registerRoute($method, $pattern, $h, $mw, $opts);
    }
}

// =====================================================================
// SECTION 13 — ROUTER (radix trie, dispatch, public API)
// =====================================================================

final class Router
{
    private static ?TrieNode $root = null;

    /** @var string[] Prefix stack maintained by group(). */
    private static array $groupPrefixStack = [];
    /** @var array<int,callable[]> Middleware stack maintained by group(). */
    private static array $groupMiddlewareStack = [];
    /** @var callable[] Global middleware applied to every matched route. */
    private static array $globalMiddleware = [];

    /** @var RouteScope[] Active domain scope stack (RouteScope::group / nested on/except). */
    private static array $scopeStack = [];

    private static mixed $handler404 = null;
    private static mixed $handler403 = null;
    private static mixed $handler405 = null;
    private static mixed $handler500 = null;

    /** @var string[] Registered patterns (for sitemap and debug). */
    private static array $registeredPatterns = [];

    /** @var array<string,string> Named route registry: name => pattern. */
    private static array $namedRoutes = [];

    /**
     * Tracks the origin (file:line) of every (METHOD pattern) registration
     * so the duplicate-route guard can tell the operator exactly where the
     * collision is.
     *
     * @var array<string,string>  "GET /users" => "/path/to/routes.php:42"
     */
    private static array $registrationOrigins = [];

    /** @var array<int,array<string,string>> Pending SEO tags emitted by emitSeoHead(). */
    private static array $pendingSeoTags = [];

    /** Most recently registered Route, used by static fluent helpers. */
    private static ?Route $lastRoute = null;

    /** @var ?array{origins:string[],methods:string[],headers:string[],credentials:bool,max_age:int} */
    private static ?array $cors = null;

    private static bool $initialised = false;

    // ---------------------------------------------------------------
    // Initialisation
    // ---------------------------------------------------------------

    public static function init(): void
    {
        if (self::$initialised) return;

        self::bootstrapConstants();
        Env::load();

        if (DEBUG_MODE) {
            Profiler::start();
            // Surface every notice/warning while developing so problems are
            // caught early. Errors still go to the centralised exception
            // handler in handleException().
            error_reporting(E_ALL);
            @ini_set('display_errors', '1');
        } else {
            // OWASP A05 — never leak stack traces / file paths / SQL fragments
            // to clients in production. Errors are still recorded via
            // CacheEngine::logEvent() inside handleException().
            error_reporting(E_ALL);
            @ini_set('display_errors', '0');
            @ini_set('display_startup_errors', '0');
        }

        if (!DEBUG_MODE && APP_SECRET === 'fallback_secret_do_not_use_in_prod') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Misconfigured: APP_SECRET must be set in .env. Cannot serve requests.\n";
            exit;
        }

        CacheEngine::bootstrap();

        register_shutdown_function([self::class, 'shutdownHandler']);
        register_shutdown_function([CacheEngine::class, 'garbageCollect']);
        set_exception_handler([self::class, 'handleException']);

        // OWASP A05 — Host-header allowlist defends against host-header
        // injection / cache poisoning when a list is configured.
        self::enforceTrustedHosts();

        SecurityLayer::emitSecurityHeaders();
        SecurityLayer::rateLimit();

        $envOrigins = Env::getArray('ROUTER_CORS_ORIGINS', []);
        if (!empty($envOrigins) && self::$cors === null) {
            self::cors(['origins' => $envOrigins]);
        }

        self::$root = new TrieNode();
        self::$initialised = true;

        // README claims first-boot auto-generation of .htaccess / web.config /
        // nginx.conf.example. The generator is idempotent (each ensureXxx
        // returns early if the file already exists) and writes via
        // @file_put_contents so a read-only document root silently no-ops.
        // Gated on DEBUG_MODE so production hosts never churn the filesystem
        // on every cold start.
        if (DEBUG_MODE) ServerConfigGenerator::ensureConfigs();

        if (DEBUG_MODE) Profiler::checkpoint('Trie initialised');
    }

    private static function bootstrapConstants(): void
    {
        if (!defined('ROUTER_BASE_DIR'))  define('ROUTER_BASE_DIR',  dirname(__FILE__));
        Env::load();
        if (!defined('DEBUG_MODE'))       define('DEBUG_MODE',       (bool) Env::get('DEBUG_MODE', false));
        if (!defined('NCACHE_DIR'))       define('NCACHE_DIR',       ROUTER_BASE_DIR . DIRECTORY_SEPARATOR . '.ncache');
        if (!defined('RATE_CACHE_DIR'))   define('RATE_CACHE_DIR',   NCACHE_DIR . DIRECTORY_SEPARATOR . 'rate');
        if (!defined('ERROR_LOG_FILE'))   define('ERROR_LOG_FILE',   NCACHE_DIR . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'error.log');
        if (!defined('PANIC_LOG_FILE'))   define('PANIC_LOG_FILE',   NCACHE_DIR . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'Panic.txt');
        if (!defined('RESPONSE_CACHE_TTL')) define('RESPONSE_CACHE_TTL', (int) Env::get('RESPONSE_CACHE_TTL', 300));
        if (!defined('RATE_LIMIT_MAX'))     define('RATE_LIMIT_MAX',     (int) Env::get('RATE_LIMIT_MAX', 120));
        if (!defined('RATE_LIMIT_WINDOW'))  define('RATE_LIMIT_WINDOW',  (int) Env::get('RATE_LIMIT_WINDOW', 60));
        if (!defined('CSRF_TOKEN_TTL'))     define('CSRF_TOKEN_TTL',     (int) Env::get('CSRF_TOKEN_TTL', 7200));
        if (!defined('APP_SECRET'))         define('APP_SECRET',         (string) Env::get('APP_SECRET', 'fallback_secret_do_not_use_in_prod'));
        if (!defined('TRUSTED_PROXIES'))    define('TRUSTED_PROXIES',    Env::getArray('TRUSTED_PROXIES', []));
        if (!defined('ROUTER_ENTRY_FILE'))  define('ROUTER_ENTRY_FILE',  'index.php');
        if (!defined('ROUTER_DOMAIN_FOLDERS')) define('ROUTER_DOMAIN_FOLDERS', Env::getMap('ROUTER_DOMAIN_FOLDERS', []));
        if (!defined('ROUTER_STATIC_FILE_SERVING')) define('ROUTER_STATIC_FILE_SERVING', (bool) Env::get('ROUTER_STATIC_FILE_SERVING', true));
        if (!defined('ROUTER_CC_ROUTE'))    define('ROUTER_CC_ROUTE',    (string) Env::get('ROUTER_CC_ROUTE', ''));
        if (!defined('ROUTER_VIEWS_DIR'))   define('ROUTER_VIEWS_DIR',   (string) Env::get('ROUTER_VIEWS_DIR', 'views'));
        if (!defined('ROUTER_TRUSTED_HOSTS')) define('ROUTER_TRUSTED_HOSTS', Env::getArray('ROUTER_TRUSTED_HOSTS', []));
        if (!defined('ROUTER_MAX_BODY_BYTES')) define('ROUTER_MAX_BODY_BYTES', (int) Env::get('ROUTER_MAX_BODY_BYTES', 2 * 1024 * 1024));
        if (!defined('ROUTER_MAX_UPLOAD_BYTES')) define('ROUTER_MAX_UPLOAD_BYTES', (int) Env::get('ROUTER_MAX_UPLOAD_BYTES', 5 * 1024 * 1024));
        if (!defined('ROUTER_DB_PATH'))         define('ROUTER_DB_PATH',         (string) Env::get('ROUTER_DB_PATH', ''));
        if (!defined('ROUTER_LOG_MAX_BYTES'))   define('ROUTER_LOG_MAX_BYTES',   (int) Env::get('ROUTER_LOG_MAX_BYTES', 5 * 1024 * 1024));
    }

    public static function shutdownHandler(): void
    {
        $error = error_get_last();
        if ($error === null) return;

        if (in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE], true)) {
            self::handleException(new ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            ));
        }
    }

    /**
     * If ROUTER_TRUSTED_HOSTS is configured, refuse requests whose Host header
     * does not match. Wildcards (`*.example.com`) supported.
     */
    private static function enforceTrustedHosts(): void
    {
        $trusted = ROUTER_TRUSTED_HOSTS;
        if (!is_array($trusted) || empty($trusted)) return;

        $host = DomainResolver::currentHost();
        if (DomainResolver::hostMatches(array_map('strval', $trusted), $host)) return;

        if (!headers_sent()) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        CacheEngine::logEvent('warn', 'Untrusted Host header', ['host' => $host]);
        echo "Bad Request\n";
        exit;
    }

    // ---------------------------------------------------------------
    // Route registration
    // ---------------------------------------------------------------

    public static function get(string $pattern, callable $handler, array $middleware = [], array $options = []): Route
    {
        return self::registerRoute('GET', $pattern, $handler, $middleware, $options);
    }
    public static function post(string $pattern, callable $handler, array $middleware = [], array $options = []): Route
    {
        return self::registerRoute('POST', $pattern, $handler, $middleware, $options);
    }
    public static function put(string $pattern, callable $handler, array $middleware = [], array $options = []): Route
    {
        return self::registerRoute('PUT', $pattern, $handler, $middleware, $options);
    }
    public static function delete(string $pattern, callable $handler, array $middleware = [], array $options = []): Route
    {
        return self::registerRoute('DELETE', $pattern, $handler, $middleware, $options);
    }
    public static function patch(string $pattern, callable $handler, array $middleware = [], array $options = []): Route
    {
        return self::registerRoute('PATCH', $pattern, $handler, $middleware, $options);
    }

    /** @param string[] $methods */
    public static function map(array $methods, string $pattern, callable $handler, array $middleware = [], array $options = []): Route
    {
        $bindings = [];
        foreach ($methods as $method) {
            $route = self::registerRoute(strtoupper($method), $pattern, $handler, $middleware, $options);
            foreach ($route->__bindings__() as $b) $bindings[] = $b;
        }
        $merged = new Route($bindings);
        self::$lastRoute = $merged;
        return $merged;
    }

    /** Alias for map(['GET','POST','PUT','DELETE','PATCH']). */
    public static function any(string $pattern, callable $handler, array $middleware = [], array $options = []): Route
    {
        return self::map(['GET','POST','PUT','DELETE','PATCH'], $pattern, $handler, $middleware, $options);
    }

    /** Begin a host-restricted registration scope. Wildcards (`*.example.com`) supported. */
    public static function on(string|array $hosts): RouteScope
    {
        return new RouteScope(allow: DomainResolver::normaliseHosts($hosts));
    }

    /** Begin a host-blocklist registration scope. */
    public static function except(string|array $hosts): RouteScope
    {
        return new RouteScope(block: DomainResolver::normaliseHosts($hosts));
    }

    /**
     * Register a route group. Prefix and middleware are pushed before $callback
     * runs and popped afterwards; nesting is unbounded. The optional 4th arg
     * accepts ['domains' => [...], 'except' => [...]] to scope the whole group.
     *
     * @param callable[] $middleware
     */
    public static function group(string $prefix, callable $callback, array $middleware = [], array $scope = []): void
    {
        self::$groupPrefixStack[]     = $prefix;
        self::$groupMiddlewareStack[] = $middleware;

        $scopePushed = false;
        if (!empty($scope['domains']) || !empty($scope['except'])) {
            self::pushScope(new RouteScope(
                allow: !empty($scope['domains']) ? DomainResolver::normaliseHosts($scope['domains']) : [],
                block: !empty($scope['except'])  ? DomainResolver::normaliseHosts($scope['except'])  : [],
            ));
            $scopePushed = true;
        }
        try {
            $callback();
        } finally {
            if ($scopePushed) self::popScope();
            array_pop(self::$groupPrefixStack);
            array_pop(self::$groupMiddlewareStack);
        }
    }

    /** Register middleware applied to every matched route, in order of registration. */
    public static function use(callable $middleware): void
    {
        self::$globalMiddleware[] = $middleware;
    }

    /** Attach SeoMeta to an already-registered route pattern (back-compat). */
    public static function seo(string $pattern, SeoMeta $meta): void
    {
        $pattern  = self::resolvePattern($pattern);
        $segments = self::splitPattern($pattern);
        $node     = self::findNode($segments);
        if ($node !== null) {
            foreach ($node->handlers as $method => $_h) {
                $node->methodSeo[$method] = $meta;
            }
        }
    }

    public static function setErrorHandler(int $code, callable $handler): void
    {
        match ($code) {
            404 => self::$handler404 = $handler,
            403 => self::$handler403 = $handler,
            405 => self::$handler405 = $handler,
            500 => self::$handler500 = $handler,
            default => null,
        };
    }

    /** @param array<string,string> $map */
    public static function domains(array $map): void { DomainResolver::set($map); }

    /** @param string|string[] $hosts */
    public static function domain(string|array $hosts, string $folder): void { DomainResolver::add($hosts, $folder); }

    public static function currentDomainFolder(): ?string { return DomainResolver::currentFolder(); }

    /** Configure CORS. Pass an empty array to disable. */
    public static function cors(array $config): void
    {
        if (empty($config)) {
            self::$cors = null;
            return;
        }
        self::$cors = $config + [
            'origins'     => [],
            'methods'     => ['GET','POST','PUT','DELETE','PATCH','OPTIONS','HEAD'],
            'headers'     => ['Content-Type','Authorization','X-Requested-With','X-CSRF-Token','X-XSRF-Token'],
            'credentials' => false,
            'max_age'     => 600,
        ];
    }

    // ---- Static fluent helpers (apply to last registered route) ----

    /** Set or fetch a route name on the LAST registered route (back-compat). */
    public static function name(string $name): void
    {
        self::$lastRoute?->name($name);
    }

    /** Apply a rate limit to the LAST registered route. Sugar for ->rate(). */
    public static function limit(int|string $maxOrSpec, ?int $window = null, ?string $bucket = null): void
    {
        self::$lastRoute?->rate($maxOrSpec, $window, $bucket);
    }

    /** Mark the LAST registered route cacheable. Sugar for ->cacheable(). */
    public static function cacheable(bool $on = true): void
    {
        self::$lastRoute?->cacheable($on);
    }

    /** @internal */
    public static function registerName(string $name, string $pattern): void
    {
        self::$namedRoutes[$name] = $pattern;
    }

    /** @internal */
    public static function pushScope(RouteScope $scope): void { self::$scopeStack[] = $scope; }
    /** @internal */
    public static function popScope(): void { array_pop(self::$scopeStack); }

    /**
     * Reverse-resolve a named route into a URL path with parameter substitution.
     *
     * @param array<string,scalar> $params
     */
    public static function url(string $name, array $params = []): string
    {
        if (!isset(self::$namedRoutes[$name])) {
            throw new InvalidArgumentException("Unknown route name: {$name}");
        }
        $pattern  = self::$namedRoutes[$name];
        $segments = self::splitPattern($pattern);
        $out      = [];

        foreach ($segments as $seg) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?|\*)?(?::[^}]+)?\}$/', $seg, $m)) {
                $param = $m[1];
                $modifier = $m[2] ?? '';
                if (!isset($params[$param])) {
                    if ($modifier === '?' || $modifier === '*') continue;
                    throw new InvalidArgumentException("Missing parameter [{$param}] for route [{$name}]");
                }
                $out[] = rawurlencode((string) $params[$param]);
            } else {
                $out[] = $seg;
            }
        }
        return '/' . implode('/', $out);
    }

    /** @return string[] Registered route patterns. */
    public static function registeredPatterns(): array { return self::$registeredPatterns; }

    public static function cacheClearToken(): string
    {
        $window = (int) floor(time() / 300); // 5-minute window
        return hash_hmac('sha256', "_cc:{$window}", APP_SECRET);
    }

    // ---------------------------------------------------------------
    // Dispatch
    // ---------------------------------------------------------------

    public static function dispatch(): void
    {
        if (!self::$initialised) self::init();

        self::registerBuiltinRoutes();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri    = self::parseUri();

        if ($method === 'OPTIONS' && self::$cors !== null) {
            self::handleCorsPreflight();
            return;
        }

        $isCacheClearRoute = (ROUTER_CC_ROUTE !== '' && $uri === self::normaliseCacheClearRoute());
        if (!$isCacheClearRoute) {
            DomainResolver::serveStaticIfAvailable($uri);
        }

        $input = SecurityLayer::collectInput();

        if (DEBUG_MODE) {
            Profiler::checkpoint('Pre-traversal');
            Profiler::logTraversal("Dispatching [{$method}] {$uri}");
        }

        $cacheable = ($method === 'GET' || $method === 'HEAD') && !$isCacheClearRoute;
        $skipCache = self::shouldSkipResponseCache();

        if ($cacheable && !$skipCache) {
            $cached = CacheEngine::get(self::buildCacheKey($uri));
            if (is_array($cached) && isset($cached['body'], $cached['headers'])) {
                self::serveCachedResponse($cached, $method);
                if (DEBUG_MODE) self::renderDebugBar($uri, $method, null, []);
                return;
            }
        }

        $segments = self::segmentise($uri);
        if (count($segments) > 64) {
            self::renderError(414, 'URI Too Long', 'Request path exceeds the maximum supported segment count.', $uri);
            return;
        }

        $traverseMethod = $method === 'HEAD' ? 'GET' : $method;
        $host = DomainResolver::currentHost();
        ['node' => $node, 'params' => $rawParams, 'tried' => $tried] =
            self::traverse($segments, $traverseMethod, $host);

        if (DEBUG_MODE) Profiler::checkpoint('Post-traversal');

        if ($node === null && !empty($tried)) {
            self::emitCorsHeaders();
            $allow = implode(', ', $tried) . (in_array('GET', $tried, true) ? ', HEAD' : '');
            header('Allow: ' . $allow);
            self::renderError(405, 'Method Not Allowed',
                "Method [{$method}] not allowed. Accepted: {$allow}", $uri);
            return;
        }

        if ($node === null) {
            self::renderError(404, 'Not Found', "No route found for [{$method}] {$uri}", $uri);
            return;
        }

        $params = [];
        foreach ($rawParams as $k => $v) {
            $params[SecurityLayer::sanitiseParam($k)] = SecurityLayer::sanitiseParam((string) $v);
        }

        // Per-route rate limit (per-method).
        $rate = $node->methodRate[$traverseMethod] ?? null;
        if ($rate !== null) {
            $bucket = $rate['bucket'] ?? ('route:' . $traverseMethod . ':' . $node->routePattern);
            SecurityLayer::rateLimit(
                $rate['max']    ?? null,
                $rate['window'] ?? null,
                bucket: $bucket,
            );
        }
        SecurityLayer::emitRateLimitHeaders();

        $seo = $node->methodSeo[$traverseMethod] ?? null;
        if ($seo !== null) self::injectSeoMeta($seo, $uri);

        self::emitCorsHeaders();

        $perRouteMw = $node->methodMiddleware[$traverseMethod] ?? [];
        $chain = array_merge(self::$globalMiddleware, $perRouteMw);
        foreach ($chain as $mw) {
            $result = $mw($params, $input);
            if ($result === false) {
                self::renderError(403, 'Forbidden', 'Access denied by middleware.', $uri);
                return;
            }
        }

        $perRouteCacheable = $node->methodCacheable[$traverseMethod] ?? false;

        try {
            if ($perRouteCacheable && $cacheable && !$skipCache) {
                ob_start();
                ($node->handlers[$traverseMethod])($params, $input);
                $body = ob_get_clean() ?: '';

                $safeHeaders = array_values(array_filter(
                    headers_list(),
                    fn(string $h) => stripos($h, 'Set-Cookie:') !== 0
                ));

                CacheEngine::set(
                    self::buildCacheKey($uri),
                    ['body' => $body, 'headers' => $safeHeaders],
                    RESPONSE_CACHE_TTL
                );
                if ($method !== 'HEAD') echo $body;
            } elseif ($method === 'HEAD') {
                ob_start();
                ($node->handlers[$traverseMethod])($params, $input);
                ob_end_clean();
            } else {
                ($node->handlers[$traverseMethod])($params, $input);
            }
        } catch (Throwable $e) {
            self::handleException($e);
        }

        if (DEBUG_MODE) {
            Profiler::checkpoint('Handler complete');
            self::renderDebugBar($uri, $method, $node, $params);
        }
    }

    private static function shouldSkipResponseCache(): bool
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return true;
        $cookie = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
        if ($cookie !== '' && preg_match('/(^|;\s*)(PHPSESSID|session|sid|XSRF-TOKEN)=/i', $cookie)) {
            return true;
        }
        return false;
    }

    private static function serveCachedResponse(array $cached, string $method): void
    {
        foreach ($cached['headers'] as $h) header($h);
        header('X-Router-Cache: HIT');
        if ($method !== 'HEAD') echo $cached['body'];
    }

    private static function handleCorsPreflight(): void
    {
        $origin = self::$cors !== null ? self::corsOrigin() : null;
        if ($origin === null) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "CORS origin not allowed.\n";
            return;
        }

        header("Access-Control-Allow-Origin: {$origin}");
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: ' . implode(', ', self::$cors['methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', self::$cors['headers']));
        header('Access-Control-Max-Age: ' . self::$cors['max_age']);
        if (!empty(self::$cors['credentials'])) {
            header('Access-Control-Allow-Credentials: true');
        }
        http_response_code(204);
    }

    private static function emitCorsHeaders(): void
    {
        if (self::$cors === null) return;
        $origin = self::corsOrigin();
        if ($origin === null) return;
        header("Access-Control-Allow-Origin: {$origin}");
        header('Vary: Origin');
        if (!empty(self::$cors['credentials'])) {
            header('Access-Control-Allow-Credentials: true');
        }
    }

    private static function corsOrigin(): ?string
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') return null;

        $allowed = self::$cors['origins'] ?? [];
        if (in_array('*', $allowed, true)) {
            return !empty(self::$cors['credentials']) ? $origin : '*';
        }
        foreach ($allowed as $o) {
            if (strcasecmp($o, $origin) === 0) return $origin;
        }
        return null;
    }

    // ---------------------------------------------------------------
    // Trie insertion
    // ---------------------------------------------------------------

    /**
     * Internal route registration. Prefer Router::get/post/etc. or RouteScope.
     * @internal
     */
    public static function registerRoute(string $method, string $pattern, callable $handler, array $middleware, array $options): Route
    {
        if (!self::$initialised) self::init();

        $pattern  = self::resolvePattern($pattern);
        $segments = self::splitPattern($pattern);

        // Optional last segment: register the shorter form too.
        $bindings = [];
        $lastIdx  = count($segments) - 1;
        if ($lastIdx >= 0) {
            $parsed = self::parseSegment($segments[$lastIdx]);
            if ($parsed['type'] === 'optional') {
                $shorterSegs = array_slice($segments, 0, $lastIdx);
                $shorterPat  = '/' . implode('/', $shorterSegs);
                if ($shorterPat === '') $shorterPat = '/';
                $bindings[] = self::insertRoute($method, $shorterPat, $shorterSegs, $handler, $middleware, $options);
            }
        }
        $bindings[] = self::insertRoute($method, $pattern, $segments, $handler, $middleware, $options);

        $route = new Route($bindings);
        self::$lastRoute = $route;
        return $route;
    }

    /**
     * Insert a fully-resolved (group-prefix-applied) pattern + segments into the trie.
     * @param string[] $segments
     * @return array{node:TrieNode,method:string,pattern:string}
     */
    private static function insertRoute(string $method, string $pattern, array $segments, callable $handler, array $middleware, array $options): array
    {
        if (DEBUG_MODE) {
            Profiler::logTraversal("Inserting [{$method}] {$pattern}");
        }

        $node = self::$root;
        foreach ($segments as $i => $seg) {
            $isLast = ($i === count($segments) - 1);
            $node   = self::insertSegment($node, $seg, $isLast);
        }

        // OWASP A05 + bug guard — refuse to register the same (method, pattern)
        // twice. Silent overwrite is a foot-gun: a second `Router::get('/x',...)`
        // somewhere in the codebase would *replace* the first handler without
        // a peep, so a feature would just stop working in production. We panic
        // (record to Panic.txt) and throw on the second registration.
        if (isset($node->handlers[$method])) {
            $first = self::$registrationOrigins["{$method} {$pattern}"] ?? null;
            $second = self::resolveCallerFrame();
            CacheEngine::panic("Duplicate route registration: [{$method}] {$pattern}", [
                'method'        => $method,
                'pattern'       => $pattern,
                'first_origin'  => $first,
                'second_origin' => $second,
            ]);
            $msg = "Duplicate route [{$method}] {$pattern}";
            if ($first)  $msg .= "; first registered at {$first}";
            if ($second) $msg .= "; second registered at {$second}";
            throw new RuntimeException($msg);
        }

        $node->isLeaf            = true;
        $node->routePattern      = $pattern;
        $node->handlers[$method] = $handler;
        // Track origin (file:line) for diagnostic output on duplicate registration.
        self::$registrationOrigins["{$method} {$pattern}"] = self::resolveCallerFrame();

        // Per-method storage so different methods on the same path can carry
        // different middleware / rate / cache / SEO / domain rules.
        $groupMw = !empty(self::$groupMiddlewareStack)
            ? array_merge(...self::$groupMiddlewareStack)
            : [];
        $node->methodMiddleware[$method] = array_merge($groupMw, $middleware);

        $node->methodCacheable[$method] = (bool) ($options['cacheable'] ?? false);

        if (isset($options['seo']) && $options['seo'] instanceof SeoMeta) {
            $node->methodSeo[$method] = $options['seo'];
        }

        if (isset($options['name']) && is_string($options['name'])) {
            $node->name = $options['name'];
            self::$namedRoutes[$options['name']] = $pattern;
        }

        // Per-route rate limit. Accepts the legacy ['max'=>..,'window'=>..] form
        // as well as the new shorthand ('60/min', '5/10s', etc.).
        if (isset($options['rate'])) {
            $rate = $options['rate'];
            if (is_string($rate)) {
                $node->methodRate[$method] = RateSpec::parse($rate) + ['bucket' => null];
            } elseif (is_int($rate)) {
                $node->methodRate[$method] = RateSpec::parse($rate, $options['window'] ?? null) + ['bucket' => null];
            } elseif (is_array($rate)) {
                $node->methodRate[$method] = [
                    'max'    => (int) ($rate['max']    ?? RATE_LIMIT_MAX),
                    'window' => (int) ($rate['window'] ?? RATE_LIMIT_WINDOW),
                    'bucket' => $rate['bucket'] ?? null,
                ];
            }
        }

        // Resolve effective allow/block host filter for this method.
        $allow = !empty($options['domains']) ? DomainResolver::normaliseHosts($options['domains']) : [];
        $block = !empty($options['except'])  ? DomainResolver::normaliseHosts($options['except'])  : [];

        $scope = $options['_scope'] ?? null;
        foreach (self::$scopeStack as $stackScope) {
            if (!empty($stackScope->allow)) $allow = array_values(array_unique(array_merge($allow, $stackScope->allow)));
            if (!empty($stackScope->block)) $block = array_values(array_unique(array_merge($block, $stackScope->block)));
        }
        if ($scope instanceof RouteScope) {
            if (!empty($scope->allow)) $allow = array_values(array_unique(array_merge($allow, $scope->allow)));
            if (!empty($scope->block)) $block = array_values(array_unique(array_merge($block, $scope->block)));
        }
        if (!empty($allow)) $node->methodAllowHosts[$method] = $allow;
        if (!empty($block)) $node->methodBlockHosts[$method] = $block;

        if (!in_array($pattern, self::$registeredPatterns, true)) {
            self::$registeredPatterns[] = $pattern;
        }

        return ['node' => $node, 'method' => $method, 'pattern' => $pattern];
    }

    /**
     * Walk the call stack until we find a frame whose `file` is *outside*
     * this single-file router, then return that "file:line" pair. Used to
     * attach a registration origin to every route so the duplicate-route
     * guard can tell the operator exactly which two call sites collided.
     */
    private static function resolveCallerFrame(): string
    {
        $self = __FILE__;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            $file = $frame['file'] ?? null;
            // Closures registered via Router::get(fn(...) => …) can yield
            // `file === null` for the call frame itself; skip those.
            if ($file === null) continue;
            if ($file === $self) continue;
            $line = $frame['line'] ?? 0;
            return "{$file}:{$line}";
        }
        return '?';
    }

    private static function insertSegment(TrieNode $node, string $seg, bool $isLast): TrieNode
    {
        $parsed = self::parseSegment($seg);

        if ($parsed['type'] === 'literal') {
            if (!isset($node->children[$parsed['value']])) {
                $node->children[$parsed['value']] = new TrieNode();
            }
            return $node->children[$parsed['value']];
        }

        if ($parsed['type'] === 'wildcard') {
            if (!$isLast) {
                throw new InvalidArgumentException("Wildcard segment {{$parsed['name']}*} must be last.");
            }
            if ($node->wildcardChild === null) {
                $child = new TrieNode();
                $child->wildcardName = $parsed['name'];
                $node->wildcardChild = $child;
            }
            return $node->wildcardChild;
        }

        if ($parsed['type'] === 'optional') {
            if (!$isLast) {
                throw new InvalidArgumentException("Optional segment {{$parsed['name']}?} must be last.");
            }
            $parsed['type'] = 'dynamic';
        }

        if ($parsed['type'] === 'dynamic') {
            if ($node->dynamicChild === null) {
                $child = new TrieNode();
                $child->paramName       = $parsed['name'];
                $child->paramConstraint = $parsed['constraint'];
                $node->dynamicChild     = $child;
            } elseif (
                $node->dynamicChild->paramName !== $parsed['name']
                && DEBUG_MODE
            ) {
                Profiler::logTraversal(
                    "WARN: param name conflict at trie level. existing={$node->dynamicChild->paramName} new={$parsed['name']}"
                );
            }
            return $node->dynamicChild;
        }

        throw new LogicException("Unhandled segment parse: {$seg}");
    }

    /** @return array{type:string,name?:string,value?:string,constraint?:?string} */
    private static function parseSegment(string $seg): array
    {
        if (!str_starts_with($seg, '{') || !str_ends_with($seg, '}')) {
            return ['type' => 'literal', 'value' => $seg];
        }

        $body = substr($seg, 1, -1);
        if ($body === '') {
            throw new InvalidArgumentException('Empty parameter braces.');
        }

        $type = 'dynamic';
        $last = $body[strlen($body) - 1];
        if ($last === '?')      { $type = 'optional'; $body = substr($body, 0, -1); }
        elseif ($last === '*')  { $type = 'wildcard'; $body = substr($body, 0, -1); }

        $constraint = null;
        $colon = strpos($body, ':');
        if ($colon !== false) {
            $name        = substr($body, 0, $colon);
            $constraint  = self::expandConstraintAlias(substr($body, $colon + 1));
        } else {
            $name = $body;
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid parameter name: {$name}");
        }
        return ['type' => $type, 'name' => $name, 'constraint' => $constraint];
    }

    private static function expandConstraintAlias(string $expr): string
    {
        return match ($expr) {
            'int'   => '[0-9]+',
            'alpha' => '[A-Za-z]+',
            'alnum' => '[A-Za-z0-9]+',
            'slug'  => '[A-Za-z0-9_-]+',
            'uuid'  => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            default => $expr,
        };
    }

    /** @return string[] */
    private static function splitPattern(string $pattern): array
    {
        return array_values(array_filter(explode('/', $pattern), fn($s) => $s !== ''));
    }

    // ---------------------------------------------------------------
    // Trie traversal (with backtracking + host filtering)
    // ---------------------------------------------------------------

    /**
     * Match $segments against the trie under $method on $host. Walks the trie
     * with backtracking so a literal sibling does not shadow a dynamic match
     * that the literal child cannot satisfy.
     *
     * @return array{node:?TrieNode,params:array<string,string>,tried:string[]}
     */
    private static function traverse(array $segments, string $method, string $host): array
    {
        $tried   = [];
        $matched = self::traverseRecursive(self::$root, $segments, 0, $method, $host, [], $tried);
        if ($matched !== null) {
            return ['node' => $matched['node'], 'params' => $matched['params'], 'tried' => []];
        }
        return ['node' => null, 'params' => [], 'tried' => array_values(array_unique($tried))];
    }

    /**
     * @param array<string,string> $params
     * @param string[] $tried
     * @return array{node:TrieNode,params:array<string,string>}|null
     */
    private static function traverseRecursive(
        TrieNode $node,
        array $segments,
        int $i,
        string $method,
        string $host,
        array $params,
        array &$tried,
    ): ?array {
        $count = count($segments);

        // Reached end of input — try to make this leaf match.
        if ($i === $count) {
            $hit = self::tryAccept($node, $method, $host, $params, $tried);
            if ($hit !== null) return $hit;

            // Permit zero-segment wildcard: /files/{path*} matches /files itself.
            if ($node->wildcardChild !== null) {
                $p = $params;
                $p[$node->wildcardChild->wildcardName ?? '_'] = '';
                $hit = self::tryAccept($node->wildcardChild, $method, $host, $p, $tried);
                if ($hit !== null) return $hit;
            }
            return null;
        }

        $seg = $segments[$i];

        // 1) Literal child wins first if reachable.
        if (isset($node->children[$seg])) {
            $hit = self::traverseRecursive($node->children[$seg], $segments, $i + 1, $method, $host, $params, $tried);
            if ($hit !== null) return $hit;
        }

        // 2) Dynamic child (with optional regex constraint).
        if ($node->dynamicChild !== null) {
            $constraint = $node->dynamicChild->paramConstraint;
            if ($constraint === null || preg_match('/^(?:' . $constraint . ')$/u', $seg) === 1) {
                $p = $params;
                $p[$node->dynamicChild->paramName ?? '_'] = $seg;
                $hit = self::traverseRecursive($node->dynamicChild, $segments, $i + 1, $method, $host, $p, $tried);
                if ($hit !== null) return $hit;
            }
        }

        // 3) Wildcard catch-all consumes remainder.
        if ($node->wildcardChild !== null) {
            $tail = implode('/', array_slice($segments, $i));
            $p = $params;
            $p[$node->wildcardChild->wildcardName ?? '_'] = $tail;
            $hit = self::tryAccept($node->wildcardChild, $method, $host, $p, $tried);
            if ($hit !== null) return $hit;
        }

        return null;
    }

    /**
     * @param array<string,string> $params
     * @param string[] $tried
     * @return array{node:TrieNode,params:array<string,string>}|null
     */
    private static function tryAccept(TrieNode $node, string $method, string $host, array $params, array &$tried): ?array
    {
        if (!$node->isLeaf || empty($node->handlers)) return null;

        if (!isset($node->handlers[$method])) {
            foreach (array_keys($node->handlers) as $m) $tried[] = $m;
            return null;
        }

        // Host filter (per-method).
        $allow = $node->methodAllowHosts[$method] ?? [];
        $block = $node->methodBlockHosts[$method] ?? [];
        if (!empty($block) && DomainResolver::hostMatches($block, $host)) return null;
        if (!empty($allow) && !DomainResolver::hostMatches($allow, $host)) return null;

        return ['node' => $node, 'params' => $params];
    }

    /** @return ?TrieNode */
    private static function findNode(array $segments): ?TrieNode
    {
        $node = self::$root;
        foreach ($segments as $seg) {
            if (isset($node->children[$seg])) {
                $node = $node->children[$seg];
            } elseif ($node->dynamicChild !== null) {
                $node = $node->dynamicChild;
            } elseif ($node->wildcardChild !== null) {
                $node = $node->wildcardChild;
            } else {
                return null;
            }
        }
        return $node->isLeaf ? $node : null;
    }

    // ---------------------------------------------------------------
    // Built-in routes
    // ---------------------------------------------------------------

    private static function registerBuiltinRoutes(): void
    {
        $register = function (string $method, string $pattern, callable $handler, array $opts = []) {
            if (in_array($pattern, self::$registeredPatterns, true)) {
                if (DEBUG_MODE) Profiler::logTraversal("Skipping built-in {$method} {$pattern} (user-defined)");
                return;
            }
            self::registerRoute($method, $pattern, $handler, [], $opts);
        };

        $register('GET', '/sitemap.xml', function (array $p, array $i): void {
            header('Content-Type: application/xml; charset=UTF-8');
            echo self::generateSitemap();
        }, ['cacheable' => true]);

        $register('GET', '/robots.txt', function (array $p, array $i): void {
            $host = self::baseUrl();
            header('Content-Type: text/plain');
            echo "User-agent: *\nAllow: /\nDisallow: /.ncache/\nSitemap: {$host}/sitemap.xml\n";
        });

        $register('GET', '/health', function (array $p, array $i): void {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'ts' => time(), 'php' => PHP_VERSION]);
        });

        $cc = self::normaliseCacheClearRoute();
        if ($cc !== '') {
            $register('GET', $cc, function (array $p, array $i): void {
                self::handleCacheClear($i);
            });
            $register('POST', $cc, function (array $p, array $i): void {
                self::handleCacheClear($i);
            });
        }
    }

    private static function normaliseCacheClearRoute(): string
    {
        $route = trim((string) ROUTER_CC_ROUTE);
        if ($route === '' || str_contains($route, '?') || str_contains($route, '#')) {
            return '';
        }
        $route = '/' . ltrim($route, '/');
        $route = preg_replace('#/{2,}#', '/', $route) ?? $route;
        return $route === '/' ? '' : rtrim($route, '/');
    }

    private static function handleCacheClear(array $input): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $token = (string) (
            $input['get']['t']
            ?? $input['post']['t']
            ?? $_SERVER['HTTP_X_CC_TOKEN']
            ?? ''
        );
        if ($token === '') {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'token required']);
            return;
        }

        $window = (int) floor(time() / 300);
        $current  = hash_hmac('sha256', "_cc:{$window}", APP_SECRET);
        $previous = hash_hmac('sha256', "_cc:" . ($window - 1), APP_SECRET);
        $ok     = hash_equals($current, $token);
        $okPrev = hash_equals($previous, $token);
        if (!($ok || $okPrev)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'invalid token']);
            return;
        }

        $stats = CacheEngine::wipe(false);
        echo json_encode([
            'ok'    => true,
            'route' => self::normaliseCacheClearRoute(),
            'cache' => $stats,
            'ts'    => time(),
        ], JSON_UNESCAPED_SLASHES);
    }

    // ---------------------------------------------------------------
    // Sitemap generation
    // ---------------------------------------------------------------

    private static function generateSitemap(): string
    {
        $base  = self::baseUrl();
        $urls  = [];
        $today = date('Y-m-d');

        self::collectSitemapUrls(self::$root, '', $urls, $base, $today);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'        . htmlspecialchars($u['loc'],        ENT_XML1, 'UTF-8') . "</loc>\n";
            $xml .= '    <lastmod>'    . htmlspecialchars($u['lastmod'],    ENT_XML1, 'UTF-8') . "</lastmod>\n";
            $xml .= '    <changefreq>' . htmlspecialchars($u['changefreq'], ENT_XML1, 'UTF-8') . "</changefreq>\n";
            $xml .= '    <priority>'   . htmlspecialchars($u['priority'],   ENT_XML1, 'UTF-8') . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        return $xml;
    }

    private static function collectSitemapUrls(TrieNode $node, string $path, array &$urls, string $base, string $today): void
    {
        if ($node->isLeaf && isset($node->handlers['GET'])) {
            $hasDynamic = str_contains($node->routePattern, '{');
            $seo        = $node->methodSeo['GET'] ?? null;
            $canonical  = $seo?->canonical ?? '';
            $excluded   = ['/sitemap.xml', '/robots.txt', '/health'];
            $cc = self::normaliseCacheClearRoute();
            if ($cc !== '') $excluded[] = $cc;

            if (!$hasDynamic && !in_array($path ?: '/', $excluded, true)) {
                $loc = $canonical !== '' ? $canonical : $base . ($path ?: '/');
                $urls[] = [
                    'loc'        => $loc,
                    'lastmod'    => $seo?->lastmod ?: $today,
                    'changefreq' => self::priorityToFreq($seo?->priority ?? 0.5),
                    'priority'   => number_format($seo?->priority ?? 0.5, 1),
                ];
            }
        }
        foreach ($node->children as $segment => $child) {
            self::collectSitemapUrls($child, $path . '/' . $segment, $urls, $base, $today);
        }
    }

    private static function priorityToFreq(float $priority): string
    {
        return match (true) {
            $priority >= 0.9 => 'daily',
            $priority >= 0.7 => 'weekly',
            $priority >= 0.4 => 'monthly',
            default          => 'yearly',
        };
    }

    // ---------------------------------------------------------------
    // SEO meta injection
    // ---------------------------------------------------------------

    private static function injectSeoMeta(SeoMeta $meta, string $uri): void
    {
        $canonical = $meta->canonical !== '' ? $meta->canonical : self::baseUrl() . $uri;
        self::$pendingSeoTags = [
            ['type' => 'title',     'value' => $meta->title],
            ['type' => 'meta',      'name'  => 'description',     'content' => $meta->description],
            ['type' => 'meta',      'name'  => 'keywords',        'content' => $meta->keywords],
            ['type' => 'link',      'rel'   => 'canonical',       'href'    => $canonical],
            ['type' => 'meta-prop', 'prop'  => 'og:title',        'content' => $meta->ogTitle ?: $meta->title],
            ['type' => 'meta-prop', 'prop'  => 'og:description',  'content' => $meta->description],
            ['type' => 'meta-prop', 'prop'  => 'og:image',        'content' => $meta->ogImage],
            ['type' => 'meta-prop', 'prop'  => 'og:type',         'content' => $meta->ogType],
            ['type' => 'meta-prop', 'prop'  => 'og:url',          'content' => $canonical],
            ['type' => 'meta',      'name'  => 'twitter:card',    'content' => $meta->twitterCard],
        ];
    }

    public static function emitSeoHead(): void
    {
        foreach (self::$pendingSeoTags as $tag) {
            $type = $tag['type'];
            $val  = fn(string $k) => htmlspecialchars((string) ($tag[$k] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            switch ($type) {
                case 'title':
                    if (($tag['value'] ?? '') !== '') echo '<title>' . htmlspecialchars($tag['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</title>\n";
                    break;
                case 'meta':
                    if (($tag['content'] ?? '') !== '') echo "<meta name=\"{$val('name')}\" content=\"{$val('content')}\">\n";
                    break;
                case 'meta-prop':
                    if (($tag['content'] ?? '') !== '') echo "<meta property=\"{$val('prop')}\" content=\"{$val('content')}\">\n";
                    break;
                case 'link':
                    if (($tag['href'] ?? '') !== '') echo "<link rel=\"{$val('rel')}\" href=\"{$val('href')}\">\n";
                    break;
            }
        }
        self::$pendingSeoTags = [];
    }

    // ---------------------------------------------------------------
    // Public helpers
    // ---------------------------------------------------------------

    public static function csrf(): string                                  { return SecurityLayer::csrfToken(); }
    public static function cspNonce(): string                              { return SecurityLayer::cspNonce(); }
    public static function generateServerConfigs(): void                   { ServerConfigGenerator::ensureConfigs(); }
    public static function flush(): void                                    { CacheEngine::flushResponses(); }
    public static function wipeCache(bool $includeLogs = false): array     { return CacheEngine::wipe($includeLogs); }
    public static function analyseKeywords(string $content, int $max = 10): array { return KeywordEngine::analyse($content, $max); }

    public static function csrfMiddleware(): callable
    {
        return function (array $params, array $input): bool {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return true;

            // Mirror the field-name fan-out in SecurityLayer::csrfValidate().
            $submitted = $input['post']['_token']
                ?? $input['post']['_csrf']
                ?? $input['post']['csrf_token']
                ?? $input['json']['_token']
                ?? $input['json']['_csrf']
                ?? $input['json']['csrf_token']
                ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                ?? $_SERVER['HTTP_X_XSRF_TOKEN']
                ?? null;

            return SecurityLayer::csrfValidate(is_string($submitted) ? $submitted : null);
        };
    }

    // ---------------------------------------------------------------
    // Error handling
    // ---------------------------------------------------------------

    public static function handleException(Throwable $e): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (DEBUG_MODE) {
            self::renderDebugException($e);
            return;
        }

        CacheEngine::logError($e->getMessage(), [
            'class' => $e::class,
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        self::renderError(500, 'Internal Server Error',
            'An unexpected error occurred. Please try again later.');
    }

    private static function renderError(int $code, string $title, string $message, string $uri = ''): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $custom = match ($code) {
            404 => self::$handler404,
            403 => self::$handler403,
            405 => self::$handler405,
            500 => self::$handler500,
            default => null,
        };
        if ($custom !== null) {
            echo $custom($code, $message);
            return;
        }
        if (DEBUG_MODE && $code === 404) {
            self::renderDebugNotFound($uri);
            return;
        }
        echo self::buildErrorHtml($code, $title, $message);
    }

private static function buildErrorHtml(int $code, string $title, string $message): string
    {
        $st = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sm = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nonce = SecurityLayer::cspNonce(); // Fetch the cryptographic nonce for this request
        
        // Clay.com Brand Palette Mapping (Canvas is always #fffaf0)
        // Array: [Card Background, Text Color, Button Background, Button Text, Pill Background]
        [$bg, $txt, $btnBg, $btnTxt, $pillBg] = match ($code) {
            404 => ['#ff4d8b', '#ffffff', '#ffffff', '#0a0a0a', 'rgba(255,255,255,0.2)'], // Brand Pink
            403 => ['#1a3a3a', '#ffffff', '#ffffff', '#0a0a0a', 'rgba(255,255,255,0.15)'], // Brand Teal
            429 => ['#e8b94a', '#0a0a0a', '#0a0a0a', '#ffffff', 'rgba(10,10,10,0.1)'], // Brand Ochre
            500 => ['#ef4444', '#ffffff', '#ffffff', '#0a0a0a', 'rgba(255,255,255,0.2)'], // Error Semantic
            default => ['#b8a4ed', '#0a0a0a', '#0a0a0a', '#ffffff', 'rgba(10,10,10,0.1)'], // Brand Lavender
        };

        return <<<HTML
            <!doctype html><html lang="en"><head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>{$code} {$st}</title>
            <style nonce="{$nonce}">
              *{box-sizing:border-box;margin:0;padding:0}
              body {
                  min-height: 100vh;
                  display: grid;
                  place-items: center;
                  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                  background-color: #fffaf0; /* canvas */
                  color: #0a0a0a; /* ink */
                  padding: 24px;
                  -webkit-font-smoothing: antialiased;
              }
              .card {
                  background-color: {$bg};
                  color: {$txt};
                  border-radius: 24px; /* rounded-xl */
                  padding: 48px 32px;
                  max-width: 480px;
                  width: 100%;
                  text-align: center;
              }
              .shape {
                  width: 64px;
                  height: 64px;
                  margin: 0 auto 32px auto;
                  background: {$pillBg};
                  /* Playful abstract blob mimicking claymation shapes */
                  border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
              }
              .code {
                  font-size: 12px; /* caption-uppercase */
                  font-weight: 600;
                  letter-spacing: 1.5px;
                  text-transform: uppercase;
                  margin-bottom: 24px;
                  display: inline-block;
                  padding: 6px 12px;
                  border-radius: 9999px; /* pill */
                  background: {$pillBg};
              }
              h1 {
                  font-size: 40px; /* display-md */
                  font-weight: 500;
                  line-height: 1.1;
                  letter-spacing: -1px;
                  margin-bottom: 16px;
              }
              p {
                  font-size: 16px; /* body-md */
                  font-weight: 400;
                  line-height: 1.55;
                  opacity: 0.9;
                  margin-bottom: 32px;
              }
              .btn {
                  display: inline-flex;
                  align-items: center;
                  justify-content: center;
                  height: 44px;
                  padding: 0 20px;
                  border-radius: 12px; /* rounded-md */
                  font-size: 14px; /* button */
                  font-weight: 600;
                  text-decoration: none;
                  background-color: {$btnBg};
                  color: {$btnTxt};
                  transition: transform 0.1s ease;
              }
              .btn:active { transform: scale(0.96); }
              @media(min-width: 768px) { 
                  h1 { font-size: 56px; letter-spacing: -2px; } /* display-lg */
                  .card { padding: 64px 48px; } 
              }
            </style>
            </head><body><main class="card">
              <div class="shape"></div>
              <div class="code">Error {$code}</div>
              <h1>{$st}</h1>
              <p>{$sm}</p>
              <a href="/" class="btn">Return to application</a>
            </main></body></html>
            HTML;
    }
private static function renderDebugNotFound(string $uri): void
    {
        $safeUri = htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        
        // Format the routes into styled list items
        $rows = '';
        foreach (self::$registeredPatterns as $p) {
            $safePattern = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            $rows .= "<li><span class='route-code'>{$safePattern}</span></li>";
        }
        
        // Fetch the cryptographic nonce to bypass the strict CSP blocks
        $nonce = SecurityLayer::cspNonce();

        echo <<<HTML
            <!doctype html><html lang="en"><head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>404 Not Found (Debug)</title>
            <style nonce="{$nonce}">
              * { box-sizing: border-box; margin: 0; padding: 0; }
              body {
                  font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                  background-color: #fffaf0; /* colors.canvas */
                  color: #0a0a0a; /* colors.ink */
                  padding: 96px 24px; /* spacing.section */
                  line-height: 1.55;
                  -webkit-font-smoothing: antialiased;
              }
              .container { max-width: 800px; margin: 0 auto; }
              
              .exception-name { 
                  font-size: 56px; /* typography.display-lg */
                  font-weight: 500; 
                  letter-spacing: -2px; 
                  line-height: 1.05;
                  margin-bottom: 24px;
              }
              
              .request-box {
                  background-color: #ff4d8b; /* colors.brand-pink */
                  color: #ffffff;
                  padding: 24px 32px;
                  border-radius: 24px; /* rounded.xl */
                  margin-bottom: 48px; /* spacing.xxl */
                  display: flex;
                  align-items: center;
                  gap: 16px;
                  flex-wrap: wrap;
              }
              .request-label {
                  font-size: 12px; /* typography.caption-uppercase */
                  font-weight: 600;
                  letter-spacing: 1.5px;
                  text-transform: uppercase;
                  background-color: rgba(255, 255, 255, 0.2);
                  padding: 6px 12px;
                  border-radius: 9999px; /* rounded.pill */
              }
              .request-uri {
                  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                  font-size: 18px;
                  font-weight: 500;
                  word-break: break-all;
              }
              
              .routes-card {
                  background-color: #f5f0e0; /* colors.surface-card */
                  border-radius: 16px; /* rounded.lg */
                  border: 1px solid #e5e5e5; /* colors.hairline */
                  overflow: hidden;
              }
              .routes-header {
                  background-color: #ebe6d6; /* colors.surface-strong */
                  padding: 16px 24px;
                  font-size: 18px; /* typography.title-md */
                  font-weight: 600;
                  color: #0a0a0a;
                  border-bottom: 1px solid #e5e5e5;
              }
              
              ul {
                  list-style: none;
                  padding: 12px 0;
                  margin: 0;
              }
              li {
                  padding: 12px 24px;
                  border-bottom: 1px solid #e5e5e5;
                  display: flex;
                  align-items: center;
              }
              li:last-child { border-bottom: none; }
              
              .route-code {
                  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                  color: #1a3a3a; /* colors.brand-teal */
                  background-color: #ebe6d6; /* colors.surface-strong */
                  padding: 4px 8px;
                  border-radius: 6px; /* rounded.xs */
                  font-size: 14px;
                  font-weight: 500;
              }
            </style>
            </head><body>
            <div class="container">
                <div class="exception-name">404 — Route Not Found</div>
                
                <div class="request-box">
                    <div class="request-label">Request URI</div>
                    <div class="request-uri">{$safeUri}</div>
                </div>
                
                <div class="routes-card">
                    <div class="routes-header">Available Registered Routes</div>
                    <ul>{$rows}</ul>
                </div>
            </div>
            </body></html>
            HTML;
    }
private static function renderDebugException(Throwable $e): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        
        $cls   = htmlspecialchars($e::class, ENT_QUOTES, 'UTF-8');
        $msg   = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file  = $e->getFile();
        $line  = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        
        // Fetch the cryptographic nonce to bypass the strict CSP blocks
        $nonce = SecurityLayer::cspNonce();

        // Extract the surrounding code context
        $lines = @file($file);
        $codeSnippet = '';
        if ($lines !== false) {
            $start = max(0, $line - 7);
            $end = min(count($lines), $line + 6);
            for ($i = $start; $i < $end; $i++) {
                $isErrorLine = ($i + 1) === $line;
                $lineNum = str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT);
                $safeLine = htmlspecialchars($lines[$i], ENT_QUOTES, 'UTF-8');
                
                if ($isErrorLine) {
                    $codeSnippet .= "<div class='line highlight'><span class='num'>{$lineNum}</span><span class='code'>{$safeLine}</span></div>";
                } else {
                    $codeSnippet .= "<div class='line'><span class='num'>{$lineNum}</span><span class='code'>{$safeLine}</span></div>";
                }
            }
        }
        
        $safeFile = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
            <!doctype html><html lang="en"><head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Error: {$cls}</title>
            <style nonce="{$nonce}">
              * { box-sizing: border-box; margin: 0; padding: 0; }
              body { 
                  /* Native system fonts as a safe fallback for the requested Inter typeface */
                  font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
                  background-color: #fffaf0; /* colors.canvas */
                  color: #0a0a0a; /* colors.ink */
                  padding: 96px 24px; /* spacing.section */
                  line-height: 1.55; 
                  -webkit-font-smoothing: antialiased;
              }
              .container { max-width: 1000px; margin: 0 auto; }
              
              /* Clay Typography Hierarchy */
              .exception-name { 
                  font-size: 56px; /* typography.display-lg */
                  font-weight: 500; /* Plain Black warmth */
                  letter-spacing: -2px; 
                  line-height: 1.05;
                  margin-bottom: 24px;
              }
              .message { 
                  font-size: 24px; /* typography.title-lg */
                  font-weight: 600; 
                  letter-spacing: -0.3px;
                  line-height: 1.3;
                  margin-bottom: 48px; 
                  word-wrap: break-word; 
              }
              .location { 
                  display: inline-block; 
                  background-color: #ffb084; /* colors.brand-peach feature card */
                  color: #0a0a0a;
                  padding: 6px 16px; 
                  border-radius: 9999px; /* rounded.pill */
                  font-size: 13px; /* typography.caption */
                  font-weight: 600;
                  margin-bottom: 32px;
              }
              .location strong { font-weight: 700; margin-left: 4px; }
              
              /* Code Editor Area */
              .editor { 
                  background-color: #f5f0e0; /* colors.surface-card */
                  border-radius: 16px; /* rounded.lg */
                  margin-bottom: 32px; /* spacing.xl */
                  overflow: hidden; 
                  border: 1px solid #e5e5e5; /* colors.hairline */
              }
              .editor-header { 
                  background-color: #ebe6d6; /* colors.surface-strong */
                  padding: 12px 24px; 
                  font-size: 12px; /* typography.caption-uppercase */
                  font-weight: 600;
                  letter-spacing: 1.5px;
                  text-transform: uppercase;
                  color: #6a6a6a; /* colors.muted */
                  border-bottom: 1px solid #e5e5e5; 
              }
              
              .code-block { 
                  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; 
                  font-size: 14px; 
                  padding: 24px 0; 
                  overflow-x: auto; 
              }
              .line { display: flex; padding: 2px 24px; white-space: pre; }
              .line.highlight { 
                  background-color: rgba(255, 77, 139, 0.08); /* colors.brand-pink low alpha */
                  border-left: 4px solid #ff4d8b; 
                  padding-left: 20px; 
              }
              .num { color: #9a9a9a; /* colors.muted-soft */ padding-right: 24px; user-select: none; text-align: right; min-width: 48px; }
              .code { color: #3a3a3a; /* colors.body */ }
              
              .highlight .num { color: #ff4d8b; font-weight: 600; }
              .highlight .code { color: #0a0a0a; font-weight: 600; }
              
              /* Stack Trace */
              .trace-box { 
                  background-color: #faf5e8; /* colors.surface-soft */
                  border-radius: 16px; /* rounded.lg */
                  padding: 32px; /* spacing.xl */
                  border: 1px solid #e5e5e5; /* colors.hairline */
                  overflow-x: auto; 
              }
              .trace-title { 
                  font-size: 18px; /* typography.title-md */
                  font-weight: 600; 
                  margin-bottom: 16px; 
                  color: #0a0a0a; 
              }
              .trace-code { 
                  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; 
                  font-size: 13px; 
                  color: #3a3a3a; 
                  line-height: 1.6; 
                  white-space: pre-wrap; 
              }
            </style>
            </head><body>
            <div class="container">
                <div class="location">
                    {$safeFile} <strong>:{$line}</strong>
                </div>
                <div class="exception-name">{$cls}</div>
                <div class="message">{$msg}</div>

                <div class="editor">
                    <div class="editor-header">
                        Application Code
                    </div>
                    <div class="code-block">
                        {$codeSnippet}
                    </div>
                </div>

                <div class="trace-box">
                    <div class="trace-title">Stack Trace</div>
                    <div class="trace-code">{$trace}</div>
                </div>
            </div>
            </body></html>
            HTML;
    }

private static function renderDebugBar(string $uri, string $method, ?TrieNode $node, array $params): void
    {
        $elapsed = number_format(Profiler::elapsedMs(), 2);
        $mem     = number_format(Profiler::peakMemoryKb(), 1);
        $route   = $node?->routePattern ?? '(unmatched)';
        $matched = htmlspecialchars($route, ENT_QUOTES, 'UTF-8');
        $safeUri = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
        $safeMethod = htmlspecialchars($method, ENT_QUOTES, 'UTF-8');
        $p       = htmlspecialchars(json_encode($params, JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES, 'UTF-8');
        
        // Fetch the cryptographic nonce to bypass the strict CSP blocks
        $nonce = SecurityLayer::cspNonce();

        echo <<<HTML
            <style nonce="{$nonce}">
              #clay-debug-bar {
                  position: fixed;
                  left: 24px;
                  right: 24px;
                  bottom: 24px;
                  background-color: #f5f0e0; /* colors.surface-card */
                  color: #0a0a0a; /* colors.ink */
                  font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                  font-size: 13px; /* typography.caption */
                  padding: 12px 24px;
                  z-index: 2147483647;
                  display: flex;
                  align-items: center;
                  gap: 16px;
                  flex-wrap: wrap;
                  border-radius: 16px; /* rounded.lg */
                  border: 1px solid #e5e5e5; /* colors.hairline */
                  box-shadow: 0 10px 30px -10px rgba(0,0,0,0.15); /* Subtle depth without heavy shadow */
                  -webkit-font-smoothing: antialiased;
              }
              .cdb-badge {
                  background-color: #0a0a0a; /* colors.primary */
                  color: #ffffff; /* colors.on-primary */
                  padding: 6px 12px;
                  border-radius: 9999px; /* rounded.pill */
                  font-weight: 600;
                  font-size: 12px; /* typography.caption-uppercase */
                  letter-spacing: 1.5px;
                  text-transform: uppercase;
                  line-height: 1;
              }
              .cdb-uri { 
                  font-weight: 600; 
                  font-size: 14px; 
              }
              .cdb-sep { 
                  width: 1px; 
                  height: 16px; 
                  background-color: #e5e5e5; /* colors.hairline */
              }
              .cdb-label { 
                  color: #6a6a6a; /* colors.muted */ 
                  font-weight: 500; 
              }
              .cdb-code {
                  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                  color: #1a3a3a; /* colors.brand-teal */
                  background-color: #ebe6d6; /* colors.surface-strong */
                  padding: 4px 8px;
                  border-radius: 6px; /* rounded.xs */
                  font-weight: 500;
              }
              .cdb-spacer { flex-grow: 1; }
              .cdb-metric {
                  background-color: #ebe6d6; /* colors.surface-strong */
                  color: #6a6a6a; /* colors.muted */
                  padding: 6px 12px;
                  border-radius: 9999px; /* rounded.pill */
                  font-weight: 500;
                  display: flex;
                  align-items: center;
                  gap: 6px;
                  line-height: 1;
              }
              .cdb-metric-val { 
                  color: #0a0a0a; /* colors.ink */
                  font-weight: 600; 
              }
            </style>
            <div id="clay-debug-bar">
                <div class="cdb-badge">{$safeMethod}</div>
                <div class="cdb-uri">{$safeUri}</div>
                <div class="cdb-sep"></div>
                <div class="cdb-label">route <span class="cdb-code">{$matched}</span></div>
                <div class="cdb-sep"></div>
                <div class="cdb-label">params <span class="cdb-code">{$p}</span></div>
                <div class="cdb-spacer"></div>
                <div class="cdb-metric">time <span class="cdb-metric-val">{$elapsed}ms</span></div>
                <div class="cdb-metric">peak <span class="cdb-metric-val">{$mem}kb</span></div>
            </div>
            HTML;
    }

    // ---------------------------------------------------------------
    // URI / cache key helpers
    // ---------------------------------------------------------------

    private static function buildCacheKey(string $uri): string
    {
        $accept = self::canonicalAccept($_SERVER['HTTP_ACCEPT'] ?? '*/*');
        // Use the *normalised* host (lowercased, port-stripped) so a hostile
        // Host header can't pollute the cache with attacker-chosen keys.
        // When ROUTER_TRUSTED_HOSTS is set, untrusted hosts are already
        // rejected before dispatch — but normalising here is cheap defence.
        $host   = DomainResolver::currentHost();
        $enc    = self::canonicalAcceptEncoding((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        $base   = "response_GET_{$host}|{$uri}|{$accept}|{$enc}";

        if (empty($_GET)) return $base;
        $sorted = $_GET;
        ksort($sorted);
        return $base . '?' . http_build_query($sorted);
    }

    /**
     * Reduce a noisy Accept-Encoding header to a small finite set so
     * compression-tier variation doesn't blow up the cache cardinality.
     */
    private static function canonicalAcceptEncoding(string $enc): string
    {
        $enc = strtolower($enc);
        if (str_contains($enc, 'br'))      return 'br';
        if (str_contains($enc, 'gzip'))    return 'gzip';
        if (str_contains($enc, 'deflate')) return 'deflate';
        return '';
    }

    private static function canonicalAccept(string $accept): string
    {
        $accept = strtolower($accept);
        if (str_contains($accept, 'application/json')) return 'json';
        if (str_contains($accept, 'application/xml') || str_contains($accept, 'text/xml')) return 'xml';
        if (str_contains($accept, 'text/html'))         return 'html';
        if (str_contains($accept, 'text/plain'))        return 'text';
        return 'any';
    }

    public static function parseUri(): string
    {
        $raw  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($raw, PHP_URL_PATH) ?: '/';

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $base   = dirname($script);
        $entry  = '/' . ROUTER_ENTRY_FILE;

        if ($script !== '' && str_starts_with($path, $script)) {
            $path = substr($path, strlen($script));
        } elseif ($path === $entry) {
            $path = '/';
        } elseif (str_starts_with($path, $entry . '/')) {
            $path = substr($path, strlen($entry));
        } elseif ($base !== '' && $base !== '/' && $base !== '\\' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = str_replace("\0", '', $path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if ($path !== '/') $path = rtrim($path, '/');
        if ($path === '')  $path = '/';

        return $path;
    }

    /** @return string[] */
    private static function segmentise(string $path): array
    {
        return array_values(array_map(
            fn(string $s) => rawurldecode($s),
            array_filter(explode('/', $path), fn(string $s) => $s !== '')
        ));
    }

    private static function resolvePattern(string $pattern): string
    {
        $prefix = implode('', self::$groupPrefixStack);
        $full   = $prefix . '/' . ltrim($pattern, '/');
        $full   = '/' . ltrim($full, '/');
        return preg_replace('#/{2,}#', '/', $full) ?? $full;
    }

    public static function baseUrl(): string
    {
        // Reuse the trusted-proxy-aware HTTPS check. Forwarded-proto headers
        // are *not* honoured unless the remote is a trusted proxy.
        $scheme = SecurityLayer::isHttps() ? 'https' : 'http';

        // Prefer the normalised current host. When ROUTER_TRUSTED_HOSTS is
        // configured, enforceTrustedHosts() has already rejected unknown hosts
        // before dispatch — so HTTP_HOST is safe to echo here. When it is not
        // configured, fall back to the first allowlisted host (when any) or
        // SERVER_NAME, which is operator-controlled.
        $host = (string) DomainResolver::currentHost();
        if ($host === '' || $host === 'localhost') {
            $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        $trusted = defined('ROUTER_TRUSTED_HOSTS') ? ROUTER_TRUSTED_HOSTS : [];
        if (!empty($trusted)
            && !DomainResolver::hostMatches(array_map('strval', $trusted), $host)
        ) {
            // Host header doesn't match the allowlist — substitute the first
            // configured host so generated URLs stay safe even if a malformed
            // Host header somehow slips through.
            $host = (string) $trusted[0];
        }
        return $scheme . '://' . $host;
    }
}

// =====================================================================
// SECTION 14 — SQLite DRIVER (built-in, lazy PDO, tuned PRAGMAs)
// =====================================================================

/**
 * Db — single-instance SQLite driver built on PDO. Opens lazily on first use
 * and applies a production-grade PRAGMA profile (WAL, synchronous=NORMAL,
 * foreign_keys=ON, busy_timeout, mmap, cache, journal_size_limit). All read
 * and write helpers use prepared statements; raw string interpolation of
 * user-supplied data is impossible by construction.
 *
 *   Db::exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, email TEXT UNIQUE)");
 *   $id    = Db::insert('users', ['email' => 'a@b.co']);
 *   $rows  = Db::select('SELECT * FROM users WHERE email = ?', ['a@b.co']);
 *   $email = Db::value('SELECT email FROM users WHERE id = ?', [$id]);
 *   Db::update('users', ['email' => 'x@y.co'], 'id = ?', [$id]);
 *   Db::delete('users', 'id = ?', [$id]);
 *
 *   Db::transaction(function () {
 *       Db::insert('users', ['email' => 'one@x.co']);
 *       Db::insert('users', ['email' => 'two@x.co']);
 *   });
 *
 *   foreach (Db::iterate('SELECT id, email FROM users') as $row) { … }
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $path = '';

    /** Cap for prepared-statement LRU cache. */
    private const STMT_CACHE_MAX = 96;
    /** @var array<string,PDOStatement> SQL → bound, ready-to-execute statement. */
    private static array $stmtCache = [];
    /** @var array<string,bool> Schema namespaces already auto-ANALYZEd in this process. */
    private static array $analyzedNamespaces = [];

    /**
     * Open (or re-open) the database. Optional. Called automatically by every
     * helper. Pass ':memory:' for an in-process database (tests).
     */
    public static function init(?string $path = null): PDO
    {
        if (self::$pdo !== null && $path === null) return self::$pdo;
        if (self::$pdo !== null && $path !== null && $path === self::$path) return self::$pdo;

        $resolved = self::resolvePath($path);

        $pdo = new PDO('sqlite:' . $resolved, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ]);

        self::applyPragmas($pdo, $resolved);
        self::$pdo                = $pdo;
        self::$path               = $resolved;
        self::$stmtCache          = [];
        self::$analyzedNamespaces = [];
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) self::init();
        /** @var PDO */
        return self::$pdo;
    }

    public static function path(): string
    {
        if (self::$pdo === null) self::init();
        return self::$path;
    }

    /**
     * Execute a raw statement (DDL etc.). Returns affected rows. DDL
     * statements (CREATE / DROP / ALTER) clear the prepared-statement cache
     * because cached plans would point at the old schema.
     */
    public static function exec(string $sql): int
    {
        $r = self::pdo()->exec($sql);
        // Cheap heuristic — if the statement *might* be DDL, drop cached
        // PDOStatement objects so the next query re-prepares against the
        // fresh schema. SQLite handles SQLITE_SCHEMA internally too, but
        // clearing here keeps memory tidy and makes schema flips deterministic.
        $head = strtoupper(ltrim($sql));
        if (str_starts_with($head, 'CREATE') || str_starts_with($head, 'DROP') || str_starts_with($head, 'ALTER')) {
            self::$stmtCache = [];
        }
        return $r === false ? 0 : (int) $r;
    }

    /**
     * Prepare and execute a parameterised statement. Returns the bound
     * `PDOStatement`. Repeated calls with the same SQL string reuse a cached
     * `PDOStatement` (LRU, capped at `STMT_CACHE_MAX`) so hot queries skip
     * `prepare()` entirely.
     *
     * @param array<string|int,scalar|null> $params
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $pdo = self::pdo();
        if (isset(self::$stmtCache[$sql])) {
            $stmt = self::$stmtCache[$sql];
            // LRU: move to most-recently-used.
            unset(self::$stmtCache[$sql]);
            self::$stmtCache[$sql] = $stmt;
            $stmt->closeCursor();
        } else {
            $stmt = $pdo->prepare($sql);
            self::$stmtCache[$sql] = $stmt;
            if (count(self::$stmtCache) > self::STMT_CACHE_MAX) {
                array_shift(self::$stmtCache);
            }
        }
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Validate a SQL identifier (table/column name) and return it
     * double-quoted for direct interpolation into SQL fragments.
     *
     * @throws InvalidArgumentException if `$ident` does not match `[A-Za-z_][A-Za-z0-9_]*`.
     */
    public static function ident(string $ident): string
    {
        self::assertIdent($ident);
        return '"' . $ident . '"';
    }

    /** @return array<int,array<string,mixed>> */
    public static function select(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = self::query($sql, $params);
        $row  = $stmt->fetch();
        // Release the cursor so the cached PDOStatement doesn't keep an
        // open read transaction (SQLite WAL would otherwise block writes).
        $stmt->closeCursor();
        return is_array($row) ? $row : null;
    }

    /** Return the first column of the first row, or null. */
    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::query($sql, $params);
        $row  = $stmt->fetch(PDO::FETCH_NUM);
        $stmt->closeCursor();
        return is_array($row) ? ($row[0] ?? null) : null;
    }

    /**
     * Lazy iterator yielding one associative row at a time. Memory-efficient
     * for large result sets.
     *
     * @return Generator<int,array<string,mixed>>
     */
    public static function iterate(string $sql, array $params = []): Generator
    {
        $stmt = self::query($sql, $params);
        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * Insert a row. Column names are validated against `[A-Za-z_][A-Za-z0-9_]*`
     * to keep the SQL safe; values bind as parameters.
     *
     * @param array<string,scalar|null> $data
     * @return string Last insert rowid (string for cross-platform big-int safety).
     */
    public static function insert(string $table, array $data): string
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Insert data must not be empty");
        }
        self::assertIdent($table);
        $cols = []; $marks = []; $vals = [];
        foreach ($data as $col => $val) {
            self::assertIdent((string) $col);
            $cols[]  = '"' . $col . '"';
            $marks[] = '?';
            $vals[]  = $val;
        }
        $sql = 'INSERT INTO "' . $table . '" (' . implode(',', $cols)
             . ') VALUES (' . implode(',', $marks) . ')';
        self::query($sql, $vals);
        return self::pdo()->lastInsertId();
    }

    /**
     * Update rows. `$where` is a raw SQL fragment (without `WHERE`); pass any
     * user-supplied values via `$whereParams`.
     *
     * @param array<string,scalar|null> $data
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Update data must not be empty");
        }
        if (trim($where) === '') {
            throw new InvalidArgumentException("Refusing UPDATE without WHERE — pass '1=1' explicitly to update all rows.");
        }
        self::assertIdent($table);
        $sets = []; $vals = [];
        foreach ($data as $col => $val) {
            self::assertIdent((string) $col);
            $sets[] = '"' . $col . '" = ?';
            $vals[] = $val;
        }
        $sql = 'UPDATE "' . $table . '" SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        $stmt = self::query($sql, array_merge($vals, $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $whereParams = []): int
    {
        if (trim($where) === '') {
            throw new InvalidArgumentException("Refusing DELETE without WHERE — pass '1=1' explicitly to delete all rows.");
        }
        self::assertIdent($table);
        $sql  = 'DELETE FROM "' . $table . '" WHERE ' . $where;
        $stmt = self::query($sql, $whereParams);
        return $stmt->rowCount();
    }

    /**
     * Run $callback inside a transaction. Commits on normal return, rolls back
     * on any thrown exception (and re-throws). Nesting supported via SAVEPOINTs.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $sp = 'sp_' . bin2hex(random_bytes(4));
            $pdo->exec("SAVEPOINT {$sp}");
            try {
                $r = $callback();
                $pdo->exec("RELEASE SAVEPOINT {$sp}");
                return $r;
            } catch (Throwable $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
                throw $e;
            }
        }

        $pdo->beginTransaction();
        try {
            $r = $callback();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return string[] List of user table names. */
    public static function tables(): array
    {
        $rows = self::select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        return array_column($rows, 'name');
    }

    /** @return array<int,array<string,mixed>> Column metadata via PRAGMA table_info. */
    public static function columns(string $table): array
    {
        self::assertIdent($table);
        return self::select('PRAGMA table_info("' . $table . '")');
    }

    // ============================================================
    //  Relationships — useful, terse, single query each.
    //
    //  All identifiers go through `assertIdent()`; the only data
    //  ever interpolated into SQL is the table/column name itself.
    //  User values are bound as prepared-statement parameters.
    // ============================================================

    /**
     * hasMany — return all rows in `$childTable` whose `$fk` column equals
     * `$parent[$localKey]`. `$parent` may be either a row array or the bare
     * key value.
     *
     *   $posts = Db::hasMany($user, 'posts', 'user_id');
     *
     * @return array<int,array<string,mixed>>
     */
    public static function hasMany(array|int|string $parent, string $childTable, string $fk, string $localKey = 'id'): array
    {
        self::assertIdent($childTable);
        self::assertIdent($fk);
        $value = self::extractKey($parent, $localKey);
        if ($value === null) return [];
        return self::select(
            'SELECT * FROM "' . $childTable . '" WHERE "' . $fk . '" = ?',
            [$value],
        );
    }

    /** hasOne — same as `hasMany` but returns the first matching row or null. */
    public static function hasOne(array|int|string $parent, string $childTable, string $fk, string $localKey = 'id'): ?array
    {
        self::assertIdent($childTable);
        self::assertIdent($fk);
        $value = self::extractKey($parent, $localKey);
        if ($value === null) return null;
        return self::selectOne(
            'SELECT * FROM "' . $childTable . '" WHERE "' . $fk . '" = ? LIMIT 1',
            [$value],
        );
    }

    /**
     * belongsTo — return the parent row referenced by `$child[$fk]` from
     * `$parentTable`. `$child` may be a row array or the bare FK value.
     */
    public static function belongsTo(array|int|string $child, string $parentTable, string $fk, string $ownerKey = 'id'): ?array
    {
        self::assertIdent($parentTable);
        self::assertIdent($ownerKey);
        // `$child` may itself be the FK value (int|string) or a row containing $fk.
        $value = is_array($child) ? ($child[$fk] ?? null) : $child;
        if ($value === null) return null;
        return self::selectOne(
            'SELECT * FROM "' . $parentTable . '" WHERE "' . $ownerKey . '" = ? LIMIT 1',
            [$value],
        );
    }

    /**
     * belongsToMany — pivot lookup. Returns rows from `$relatedTable` that
     * are linked to `$parent` via `$pivot`.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function belongsToMany(
        array|int|string $parent,
        string $relatedTable,
        string $pivot,
        string $fkLocal,
        string $fkRelated,
        string $localKey = 'id',
        string $relatedKey = 'id',
    ): array {
        self::assertIdent($relatedTable);
        self::assertIdent($pivot);
        self::assertIdent($fkLocal);
        self::assertIdent($fkRelated);
        self::assertIdent($relatedKey);
        $value = self::extractKey($parent, $localKey);
        if ($value === null) return [];
        $sql = 'SELECT "' . $relatedTable . '".* FROM "' . $relatedTable . '" '
             . 'INNER JOIN "' . $pivot . '" ON "' . $pivot . '"."' . $fkRelated . '" = "' . $relatedTable . '"."' . $relatedKey . '" '
             . 'WHERE "' . $pivot . '"."' . $fkLocal . '" = ?';
        return self::select($sql, [$value]);
    }

    private static function extractKey(array|int|string $parent, string $localKey): mixed
    {
        if (is_array($parent)) {
            return $parent[$localKey] ?? null;
        }
        return $parent;
    }

    /**
     * Run an idempotent migration list. Each migration is a SQL string; the
     * runner records its hash in `_router_migrations` so the same migration
     * is never applied twice. Migrations run inside a single transaction; if
     * any step fails, none are committed.
     *
     * @param string[] $migrations
     * @return string[] Names of migrations that were applied this call.
     */
    public static function migrate(array $migrations, string $namespace = 'default'): array
    {
        self::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS _router_migrations (
                ns TEXT NOT NULL,
                idx INTEGER NOT NULL,
                hash TEXT NOT NULL,
                applied_at INTEGER NOT NULL,
                PRIMARY KEY (ns, idx)
            )'
        );

        $applied = [];
        self::transaction(static function () use ($migrations, $namespace, &$applied) {
            foreach (array_values($migrations) as $i => $sql) {
                $hash    = hash('sha256', $sql);
                $existing = self::value(
                    'SELECT hash FROM _router_migrations WHERE ns = ? AND idx = ?',
                    [$namespace, $i],
                );
                if ($existing !== null) {
                    if ($existing !== $hash) {
                        throw new RuntimeException(
                            "Migration #{$i} for namespace [{$namespace}] has changed since it was applied. "
                            . "Add a new migration instead of editing past ones."
                        );
                    }
                    continue;
                }
                self::pdo()->exec($sql);
                self::query(
                    'INSERT INTO _router_migrations (ns, idx, hash, applied_at) VALUES (?, ?, ?, ?)',
                    [$namespace, $i, $hash, time()],
                );
                $applied[] = "{$namespace}#{$i}";
            }
        });

        // Auto-ANALYZE once per namespace per process: populates sqlite_stat1
        // so the planner picks the best index on the new schema.
        if (!empty($applied) && !isset(self::$analyzedNamespaces[$namespace])) {
            try {
                self::pdo()->exec('ANALYZE');
            } catch (Throwable $e) {
                CacheEngine::panic('ANALYZE failed after migrate', [
                    'namespace' => $namespace,
                    'error'     => $e->getMessage(),
                ]);
            }
            self::$analyzedNamespaces[$namespace] = true;
        }
        return $applied;
    }

    // -------- Adaptive optimization --------

    /**
     * Run SQLite's incremental adaptive optimizer. Safe to call frequently —
     * SQLite skips work that isn't needed. Recommended right before close
     * (which we do automatically) and periodically on long-running daemons.
     */
    public static function optimize(): void
    {
        if (self::$pdo === null) return;
        try {
            self::$pdo->exec('PRAGMA analysis_limit = 1000');
            self::$pdo->exec('PRAGMA optimize');
        } catch (Throwable $e) {
            // Optimize is best-effort, but record so silent failure isn't invisible.
            CacheEngine::panic('PRAGMA optimize failed', ['error' => $e->getMessage()]);
        }
    }

    /** Re-build statistics for the planner. Pass a table name to scope it. */
    public static function analyze(?string $table = null): void
    {
        if ($table === null) {
            self::pdo()->exec('ANALYZE');
            return;
        }
        self::assertIdent($table);
        self::pdo()->exec('ANALYZE "' . $table . '"');
    }

    /** Reclaim free pages and defragment. Cannot run inside a transaction. */
    public static function vacuum(): void
    {
        if (self::pdo()->inTransaction()) {
            throw new RuntimeException('Db::vacuum() cannot run inside a transaction.');
        }
        self::pdo()->exec('VACUUM');
    }

    /**
     * Return the SQLite query plan for `$sql`. Useful to verify that an
     * index is being used (rows containing `SCAN` indicate a full table scan).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function explain(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare('EXPLAIN QUERY PLAN ' . $sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Snapshot of useful PRAGMA values (page_count, page_size, cache_size,
     * journal_mode, foreign_keys, mmap_size, wal_autocheckpoint, user_version).
     *
     * @return array<string,mixed>
     */
    public static function stats(): array
    {
        $pragmas = [
            'page_count', 'page_size', 'cache_size', 'journal_mode', 'foreign_keys',
            'mmap_size', 'wal_autocheckpoint', 'user_version', 'synchronous',
            'busy_timeout', 'temp_store',
        ];
        $out = ['path' => self::path()];
        foreach ($pragmas as $p) {
            try {
                $out[$p] = self::pdo()->query('PRAGMA ' . $p)->fetchColumn();
            } catch (Throwable) {
                $out[$p] = null;
            }
        }
        if (isset($out['page_count'], $out['page_size']) && is_numeric($out['page_count']) && is_numeric($out['page_size'])) {
            $out['size_bytes'] = (int) $out['page_count'] * (int) $out['page_size'];
        }
        $out['stmt_cache'] = count(self::$stmtCache);
        return $out;
    }

    // -------- Convenience builder + bulk mutators --------

    /**
     * Begin a fluent query for `$table`. Returns a fresh `QueryBuilder`.
     * See `QueryBuilder` for the full set of chainable methods.
     */
    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }

    /**
     * Bulk insert. All rows must share the same column set. Returns the
     * number of rows inserted. Runs inside a single transaction for speed
     * (SQLite is dramatically faster than per-row inserts here).
     *
     * @param array<int,array<string,scalar|null>> $rows
     */
    public static function insertMany(string $table, array $rows): int
    {
        if (empty($rows)) return 0;
        self::assertIdent($table);
        $cols = array_keys($rows[0]);
        foreach ($cols as $c) self::assertIdent((string) $c);

        $colList = implode(',', array_map(fn($c) => '"' . $c . '"', $cols));
        $marks   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql     = 'INSERT INTO "' . $table . '" (' . $colList . ') VALUES ' . $marks;

        $count = 0;
        self::transaction(static function () use ($rows, $cols, $sql, &$count) {
            foreach ($rows as $row) {
                $vals = [];
                foreach ($cols as $c) $vals[] = $row[$c] ?? null;
                self::query($sql, $vals);
                $count++;
            }
        });
        return $count;
    }

    /**
     * INSERT … ON CONFLICT(uniqueBy) DO UPDATE. `$uniqueBy` lists the columns
     * (or unique index expression columns) that uniquely identify a row.
     * `$update` defaults to overwriting every non-conflict column.
     *
     * @param array<string,scalar|null> $data
     * @param string[] $uniqueBy
     * @param string[]|null $update Columns to overwrite (null = every non-key column).
     */
    public static function upsert(string $table, array $data, array $uniqueBy, ?array $update = null): int
    {
        if (empty($data))      throw new InvalidArgumentException('Upsert data must not be empty');
        if (empty($uniqueBy))  throw new InvalidArgumentException('Upsert requires at least one unique key column');
        self::assertIdent($table);

        $cols = []; $marks = []; $vals = [];
        foreach ($data as $col => $val) {
            self::assertIdent((string) $col);
            $cols[]  = '"' . $col . '"';
            $marks[] = '?';
            $vals[]  = $val;
        }
        foreach ($uniqueBy as $u) self::assertIdent($u);
        $update ??= array_diff(array_keys($data), $uniqueBy);
        $sets = [];
        foreach ($update as $col) {
            self::assertIdent($col);
            $sets[] = '"' . $col . '" = excluded."' . $col . '"';
        }

        $sql = 'INSERT INTO "' . $table . '" (' . implode(',', $cols)
             . ') VALUES (' . implode(',', $marks) . ')'
             . ' ON CONFLICT (' . implode(',', array_map(fn($c) => '"' . $c . '"', $uniqueBy)) . ')'
             . (empty($sets) ? ' DO NOTHING' : ' DO UPDATE SET ' . implode(', ', $sets));
        $stmt = self::query($sql, $vals);
        return $stmt->rowCount();
    }

    /** Close the connection (mostly for tests / long-running CLI). */
    public static function close(): void
    {
        if (self::$pdo !== null) {
            // Run the adaptive optimizer one last time so the next process
            // benefits from up-to-date sqlite_stat1.
            self::optimize();
        }
        self::$stmtCache          = [];
        self::$analyzedNamespaces = [];
        self::$pdo                = null;
        self::$path               = '';
    }

    private static function applyPragmas(PDO $pdo, string $resolved): void
    {
        // Order matters: journal_mode must be set before busy_timeout for clarity.
        // WAL is incompatible with :memory: databases — fall back to MEMORY.
        $isMemory = ($resolved === ':memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec($isMemory ? 'PRAGMA journal_mode = MEMORY' : 'PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA busy_timeout = 5000');           // 5s
        $pdo->exec('PRAGMA cache_size = -65536');           // 64 MiB
        $pdo->exec('PRAGMA mmap_size = 268435456');         // 256 MiB
        $pdo->exec('PRAGMA journal_size_limit = 67108864'); // 64 MiB cap
    }

    private static function resolvePath(?string $path): string
    {
        if ($path === ':memory:') return ':memory:';

        $configured = $path ?? (defined('ROUTER_DB_PATH') ? ROUTER_DB_PATH : '');
        if ($configured === '') {
            // Default: hidden under .ncache so the .htaccess/web.config templates already block it.
            $configured = NCACHE_DIR . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'app.sqlite';
        }

        // If relative, anchor under ROUTER_BASE_DIR.
        if (!self::isAbsolutePath($configured)) {
            $configured = ROUTER_BASE_DIR . DIRECTORY_SEPARATOR
                        . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($configured, '/\\'));
        }

        $dir = dirname($configured);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create database directory: {$dir}");
        }
        return $configured;
    }

    private static function isAbsolutePath(string $p): bool
    {
        if ($p === '') return false;
        if ($p[0] === '/' || $p[0] === '\\') return true;
        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $p);
    }

    private static function assertIdent(string $ident): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
            throw new InvalidArgumentException("Invalid SQL identifier: {$ident}");
        }
    }
}

// =====================================================================
// SECTION 14.4 — SCHEMA BUILDER (table definitions, ALTER, indexes)
// =====================================================================

/**
 * Blueprint — fluent table definition for `Schema::create()` / `Schema::table()`.
 *
 *   Schema::create('users', function (Blueprint $t) {
 *       $t->id();
 *       $t->string('email')->unique();
 *       $t->string('name')->nullable();
 *       $t->int('age')->default(0);
 *       $t->timestamps();
 *   });
 *
 * SQLite-specific compromises:
 *   - All columns get strict, well-defined affinities (TEXT/INTEGER/REAL/BLOB/NUMERIC).
 *   - `unsigned()` is honoured by emitting a CHECK constraint (SQLite has no
 *     unsigned types).
 *   - SQLite ALTER TABLE only supports ADD COLUMN / RENAME / DROP COLUMN
 *     (3.35+); the schema builder follows those constraints. Use `Schema::create`
 *     + data migration for richer changes.
 */
final class Blueprint
{
    public string $table;
    public bool $isAlter;
    /** @var array<int,array<string,mixed>> Column definitions in declaration order. */
    public array $columns = [];
    /** @var array<int,array{name:?string,cols:string[],unique:bool}> */
    public array $indexes = [];
    /** @var array<int,array{cols:string[],ref_table:string,ref_cols:string[],on_delete:string,on_update:string}> */
    public array $foreignKeys = [];
    /** @var array<int,string[]> Composite primary keys (only when no autoincrement). */
    public array $compositePrimaries = [];
    /** @var string[] Columns to drop (Schema::table only). */
    public array $drops = [];
    /** @var array<int,array{from:string,to:string}> */
    public array $renames = [];

    public function __construct(string $table, bool $isAlter = false)
    {
        Db::ident($table);
        $this->table   = $table;
        $this->isAlter = $isAlter;
    }

    // -------- Column factories --------

    /** Auto-incrementing INTEGER PRIMARY KEY. SQLite alias for `INTEGER PRIMARY KEY`. */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn($name, 'INTEGER', ['primary' => true, 'autoincrement' => true, 'nullable' => false]);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, 'TEXT', ['length' => $length]);
    }
    public function text(string $name): ColumnDefinition       { return $this->addColumn($name, 'TEXT'); }
    public function int(string $name): ColumnDefinition        { return $this->addColumn($name, 'INTEGER'); }
    public function bigint(string $name): ColumnDefinition     { return $this->addColumn($name, 'BIGINT'); }
    public function bool(string $name): ColumnDefinition       { return $this->addColumn($name, 'INTEGER', ['bool' => true]); }
    public function float(string $name): ColumnDefinition      { return $this->addColumn($name, 'REAL'); }
    public function double(string $name): ColumnDefinition     { return $this->addColumn($name, 'REAL'); }

    /** NUMERIC affinity — exact precision via TEXT-fallback in SQLite. */
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($name, "NUMERIC({$precision},{$scale})");
    }

    public function date(string $name): ColumnDefinition       { return $this->addColumn($name, 'TEXT', ['kind' => 'date']); }
    public function dateTime(string $name): ColumnDefinition   { return $this->addColumn($name, 'TEXT', ['kind' => 'datetime']); }
    public function timestamp(string $name): ColumnDefinition  { return $this->addColumn($name, 'INTEGER', ['kind' => 'timestamp']); }
    public function json(string $name): ColumnDefinition       { return $this->addColumn($name, 'TEXT', ['kind' => 'json']); }
    public function binary(string $name): ColumnDefinition     { return $this->addColumn($name, 'BLOB'); }
    public function uuid(string $name): ColumnDefinition       { return $this->addColumn($name, 'TEXT', ['kind' => 'uuid', 'length' => 36]); }

    /**
     * `enum` is enforced via CHECK constraint. SQLite has no native ENUM.
     * @param string[] $values
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        return $this->addColumn($name, 'TEXT', ['enum' => array_values($values)]);
    }

    /**
     * Foreign-key column convenience — declares `<related>_id INTEGER` and
     * registers a FK to `<related>(id)`. Use `->references('foo')->on('bar')`
     * for non-conventional names.
     */
    public function foreignId(string $name): ColumnDefinition
    {
        $col = $this->addColumn($name, 'INTEGER', ['nullable' => false]);
        $col->_isFkSugar = true;
        return $col;
    }

    /** Adds `created_at` / `updated_at` (INTEGER unix timestamps). */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->default(0);
        $this->timestamp('updated_at')->default(0);
    }

    /** Adds `deleted_at` (nullable timestamp) for soft-delete patterns. */
    public function softDeletes(): void
    {
        $this->timestamp('deleted_at')->nullable();
    }

    // -------- Constraints --------

    /**
     * Add a non-unique index over one or more columns. Returns the index name
     * so the caller can also drop it later via `Schema::table(...->dropIndex(...))`.
     */
    public function index(string|array $cols, ?string $name = null): string
    {
        $cols = (array) $cols;
        foreach ($cols as $c) Db::ident($c);
        $name ??= self::indexName($this->table, $cols, false);
        Db::ident($name);
        $this->indexes[] = ['name' => $name, 'cols' => array_values($cols), 'unique' => false];
        return $name;
    }

    public function unique(string|array $cols, ?string $name = null): string
    {
        $cols = (array) $cols;
        foreach ($cols as $c) Db::ident($c);
        $name ??= self::indexName($this->table, $cols, true);
        Db::ident($name);
        $this->indexes[] = ['name' => $name, 'cols' => array_values($cols), 'unique' => true];
        return $name;
    }

    /** Composite primary key (call once; cannot mix with `id()`). */
    public function primary(array $cols): void
    {
        foreach ($cols as $c) Db::ident($c);
        $this->compositePrimaries[] = array_values($cols);
    }

    /**
     * Add a FOREIGN KEY constraint. Use the returned object's fluent API to
     * pin onDelete / onUpdate behaviour:
     *   $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
     */
    public function foreign(string|array $cols): ForeignKeyDefinition
    {
        $cols = (array) $cols;
        foreach ($cols as $c) Db::ident($c);
        $fk = new ForeignKeyDefinition(array_values($cols), $this);
        return $fk;
    }

    // -------- ALTER helpers --------

    /** Drop a column (SQLite ≥ 3.35). */
    public function dropColumn(string $name): void
    {
        Db::ident($name);
        $this->drops[] = $name;
    }

    /** Rename a column (SQLite ≥ 3.25). */
    public function renameColumn(string $from, string $to): void
    {
        Db::ident($from);
        Db::ident($to);
        $this->renames[] = ['from' => $from, 'to' => $to];
    }

    /** Drop an index by name. */
    public function dropIndex(string $name): void
    {
        Db::ident($name);
        $this->drops[] = "__idx__:{$name}";
    }

    // -------- Internal --------

    private function addColumn(string $name, string $type, array $opts = []): ColumnDefinition
    {
        Db::ident($name);
        $col = new ColumnDefinition($name, $type, $opts);
        $this->columns[] = $col;
        return $col;
    }

    public static function indexName(string $table, array $cols, bool $unique): string
    {
        $prefix = $unique ? 'uq' : 'idx';
        $name = $prefix . '_' . $table . '_' . implode('_', $cols);
        // SQLite identifier max length is 64k but keep names readable.
        return strlen($name) > 60 ? substr($name, 0, 50) . '_' . substr(hash('sha256', $name), 0, 8) : $name;
    }
}

/**
 * Single-column definition. Returned from every `Blueprint::string()`-style
 * factory; the fluent API binds the modifier directly to the column.
 */
final class ColumnDefinition
{
    public string $name;
    public string $type;
    public bool $nullable = false;
    public mixed $default = null;
    public bool $hasDefault = false;
    public bool $primary = false;
    public bool $autoincrement = false;
    public bool $unsigned = false;
    public bool $unique = false;
    public bool $index = false;
    public ?string $check = null;
    public bool $useCurrentTs = false;
    /** @var array<string,mixed> Internal kind / enum / length / etc. */
    public array $opts;
    /** Marks `foreignId()` so the compiler auto-inserts a FK to <name without _id>(id). */
    public bool $_isFkSugar = false;

    public function __construct(string $name, string $type, array $opts = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->opts = $opts;
        $this->nullable = $opts['nullable'] ?? true;
        if (!empty($opts['primary']))       $this->primary = true;
        if (!empty($opts['autoincrement'])) $this->autoincrement = true;
    }

    public function nullable(bool $v = true): self                  { $this->nullable = $v; return $this; }
    public function notNull(): self                                  { $this->nullable = false; return $this; }
    public function default(mixed $v): self                          { $this->default = $v; $this->hasDefault = true; return $this; }
    public function unsigned(): self                                 { $this->unsigned = true; return $this; }
    public function unique(): self                                   { $this->unique = true; return $this; }
    public function index(): self                                    { $this->index = true; return $this; }
    public function primary(): self                                  { $this->primary = true; return $this; }
    public function autoincrement(): self                            { $this->autoincrement = true; return $this; }
    public function check(string $expr): self                        { $this->check = $expr; return $this; }
    public function useCurrent(): self                               { $this->useCurrentTs = true; $this->hasDefault = true; return $this; }

    /** Fluent FK shorthand: `->references('id')->on('users')`. */
    public function references(string $col): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition([$this->name], null);
        $fk->refCols = [$col];
        $this->_pendingFk = $fk;
        return $fk;
    }

    /** Set internally by `references()` so `Schema` can pick up the FK. */
    public ?ForeignKeyDefinition $_pendingFk = null;
}

/**
 * Fluent foreign-key constraint. Created by `Blueprint::foreign(...)` or
 * `ColumnDefinition::references(...)`. Calling `on()` finalises the FK.
 */
final class ForeignKeyDefinition
{
    /** @var string[] */
    public array $cols;
    /** @var string[] */
    public array $refCols = ['id'];
    public string $refTable = '';
    public string $onDelete = 'NO ACTION';
    public string $onUpdate = 'NO ACTION';
    private ?Blueprint $blueprint;

    /** @param string[] $cols */
    public function __construct(array $cols, ?Blueprint $blueprint)
    {
        $this->cols = $cols;
        $this->blueprint = $blueprint;
    }

    /** Set referenced column(s). */
    public function references(string|array $col): self
    {
        $this->refCols = array_map('strval', (array) $col);
        foreach ($this->refCols as $c) Db::ident($c);
        return $this;
    }

    /** Set the referenced table and register the FK on the blueprint. */
    public function on(string $table): self
    {
        Db::ident($table);
        $this->refTable = $table;
        if ($this->blueprint instanceof Blueprint) {
            $this->blueprint->foreignKeys[] = [
                'cols'      => $this->cols,
                'ref_table' => $this->refTable,
                'ref_cols'  => $this->refCols,
                'on_delete' => $this->onDelete,
                'on_update' => $this->onUpdate,
            ];
        }
        return $this;
    }

    public function onDelete(string $action): self { $this->onDelete = self::action($action); return $this; }
    public function onUpdate(string $action): self { $this->onUpdate = self::action($action); return $this; }
    public function cascadeOnDelete(): self        { $this->onDelete = 'CASCADE'; return $this; }
    public function nullOnDelete(): self           { $this->onDelete = 'SET NULL'; return $this; }
    public function restrictOnDelete(): self       { $this->onDelete = 'RESTRICT'; return $this; }

    private static function action(string $a): string
    {
        return match (strtoupper(trim($a))) {
            'CASCADE'     => 'CASCADE',
            'SET NULL'    => 'SET NULL',
            'SET DEFAULT' => 'SET DEFAULT',
            'RESTRICT'    => 'RESTRICT',
            'NO ACTION'   => 'NO ACTION',
            default       => throw new InvalidArgumentException("Invalid FK action: {$a}"),
        };
    }
}

/**
 * Schema — declarative, idempotent table definitions on top of `Db`. Uses
 * `Blueprint` to collect columns + indexes + FKs and compiles them into
 * standard SQLite DDL via prepared identifiers (no user-supplied SQL is
 * ever interpolated).
 *
 *   Schema::create('users', function (Blueprint $t) {
 *       $t->id();
 *       $t->string('email')->unique();
 *       $t->timestamps();
 *   });
 *   Schema::table('users', fn(Blueprint $t) => $t->string('phone')->nullable());
 *   Schema::dropIfExists('users');
 *
 * Returns the list of statements that were actually executed so callers can
 * use it inside `Db::migrate([...])` for an idempotent migration history.
 */
final class Schema
{
    /**
     * Create a table. The callback receives a Blueprint to declare columns,
     * indexes, and FKs. Subsequent identical calls are no-ops (CREATE TABLE
     * IF NOT EXISTS) so this is safe to run on every boot.
     *
     * @param callable(Blueprint):void $cb
     * @return string[] List of SQL statements that were issued.
     */
    public static function create(string $table, callable $cb): array
    {
        $bp = new Blueprint($table, isAlter: false);
        $cb($bp);
        $stmts = self::compileCreate($bp);
        foreach ($stmts as $sql) Db::exec($sql);
        return $stmts;
    }

    /** Like `create()`, but also runs when the table already exists (no-op). */
    public static function createIfNotExists(string $table, callable $cb): array
    {
        return self::create($table, $cb);
    }

    /**
     * Modify an existing table (ADD / DROP / RENAME column, add/drop indexes,
     * add FKs via add column).
     *
     * @param callable(Blueprint):void $cb
     * @return string[] List of SQL statements that were issued.
     */
    public static function table(string $table, callable $cb): array
    {
        $bp = new Blueprint($table, isAlter: true);
        $cb($bp);
        $stmts = self::compileAlter($bp);
        foreach ($stmts as $sql) Db::exec($sql);
        return $stmts;
    }

    public static function dropIfExists(string $table): void
    {
        Db::ident($table);
        Db::exec('DROP TABLE IF EXISTS "' . $table . '"');
    }

    public static function rename(string $from, string $to): void
    {
        Db::ident($from);
        Db::ident($to);
        Db::exec('ALTER TABLE "' . $from . '" RENAME TO "' . $to . '"');
    }

    /** True if a table with this name exists. */
    public static function hasTable(string $table): bool
    {
        Db::ident($table);
        return Db::value(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?",
            [$table],
        ) !== null;
    }

    /** True if the named table has the named column. */
    public static function hasColumn(string $table, string $column): bool
    {
        Db::ident($table);
        Db::ident($column);
        foreach (Db::columns($table) as $row) {
            if (($row['name'] ?? null) === $column) return true;
        }
        return false;
    }

    /** Drop an index by name (no-op if it doesn't exist). */
    public static function dropIndexIfExists(string $name): void
    {
        Db::ident($name);
        Db::exec('DROP INDEX IF EXISTS "' . $name . '"');
    }

    // -------- Compilation --------

    /** @return string[] */
    public static function compileCreate(Blueprint $bp): array
    {
        $colSql = [];
        $autoIncSeen = false;
        foreach ($bp->columns as $col) {
            // foreignId sugar: auto-derive FK if user didn't add explicit one.
            if ($col->_isFkSugar && empty(self::findFkFor($bp, $col->name))) {
                // foo_id → foos / users_id → users (strip trailing _id, pluralise loosely).
                $base = preg_replace('/_id$/', '', $col->name);
                if (is_string($base) && $base !== '' && $base !== $col->name) {
                    $bp->foreignKeys[] = [
                        'cols' => [$col->name], 'ref_table' => self::guessPlural($base),
                        'ref_cols' => ['id'], 'on_delete' => 'NO ACTION', 'on_update' => 'NO ACTION',
                    ];
                }
            }
            // ColumnDefinition::references(...)->on(...) declared on the column.
            if ($col->_pendingFk instanceof ForeignKeyDefinition && $col->_pendingFk->refTable !== '') {
                $bp->foreignKeys[] = [
                    'cols'      => $col->_pendingFk->cols,
                    'ref_table' => $col->_pendingFk->refTable,
                    'ref_cols'  => $col->_pendingFk->refCols,
                    'on_delete' => $col->_pendingFk->onDelete,
                    'on_update' => $col->_pendingFk->onUpdate,
                ];
            }
            $sql = self::compileColumn($col, single: true, allowAutoIncrement: !$autoIncSeen);
            if ($col->autoincrement) $autoIncSeen = true;
            $colSql[] = $sql;
        }

        foreach ($bp->compositePrimaries as $cols) {
            $quoted = array_map(fn($c) => '"' . $c . '"', $cols);
            $colSql[] = 'PRIMARY KEY (' . implode(',', $quoted) . ')';
        }

        foreach ($bp->foreignKeys as $fk) {
            $colSql[] = self::compileForeignKey($fk);
        }

        $stmts = [];
        $stmts[] = 'CREATE TABLE IF NOT EXISTS "' . $bp->table . '" (' . implode(', ', $colSql) . ')';

        // Per-column indexes / uniques declared inline.
        foreach ($bp->columns as $col) {
            if ($col->unique) {
                $name = Blueprint::indexName($bp->table, [$col->name], true);
                $stmts[] = 'CREATE UNIQUE INDEX IF NOT EXISTS "' . $name . '" ON "' . $bp->table . '" ("' . $col->name . '")';
            } elseif ($col->index) {
                $name = Blueprint::indexName($bp->table, [$col->name], false);
                $stmts[] = 'CREATE INDEX IF NOT EXISTS "' . $name . '" ON "' . $bp->table . '" ("' . $col->name . '")';
            }
        }
        // Multi-column indexes via Blueprint::index() / unique().
        foreach ($bp->indexes as $idx) {
            $kw = $idx['unique'] ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
            $cols = implode(',', array_map(fn($c) => '"' . $c . '"', $idx['cols']));
            $stmts[] = $kw . ' IF NOT EXISTS "' . $idx['name'] . '" ON "' . $bp->table . '" (' . $cols . ')';
        }
        return $stmts;
    }

    /** @return string[] */
    public static function compileAlter(Blueprint $bp): array
    {
        $stmts = [];
        $tbl = '"' . $bp->table . '"';

        foreach ($bp->renames as $r) {
            $stmts[] = 'ALTER TABLE ' . $tbl . ' RENAME COLUMN "' . $r['from'] . '" TO "' . $r['to'] . '"';
        }
        foreach ($bp->drops as $d) {
            if (str_starts_with($d, '__idx__:')) {
                $stmts[] = 'DROP INDEX IF EXISTS "' . substr($d, 8) . '"';
            } else {
                $stmts[] = 'ALTER TABLE ' . $tbl . ' DROP COLUMN "' . $d . '"';
            }
        }
        foreach ($bp->columns as $col) {
            // ALTER TABLE ADD COLUMN cannot embed FK + we cannot have UNIQUE inline
            // on SQLite ADD; require explicit unique() index after.
            $stmts[] = 'ALTER TABLE ' . $tbl . ' ADD COLUMN ' . self::compileColumn($col, single: false, allowAutoIncrement: false);
            if ($col->unique) {
                $name = Blueprint::indexName($bp->table, [$col->name], true);
                $stmts[] = 'CREATE UNIQUE INDEX IF NOT EXISTS "' . $name . '" ON ' . $tbl . ' ("' . $col->name . '")';
            } elseif ($col->index) {
                $name = Blueprint::indexName($bp->table, [$col->name], false);
                $stmts[] = 'CREATE INDEX IF NOT EXISTS "' . $name . '" ON ' . $tbl . ' ("' . $col->name . '")';
            }
        }
        foreach ($bp->indexes as $idx) {
            $kw = $idx['unique'] ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
            $cols = implode(',', array_map(fn($c) => '"' . $c . '"', $idx['cols']));
            $stmts[] = $kw . ' IF NOT EXISTS "' . $idx['name'] . '" ON ' . $tbl . ' (' . $cols . ')';
        }
        return $stmts;
    }

    private static function compileColumn(ColumnDefinition $col, bool $single, bool $allowAutoIncrement): string
    {
        $sql = '"' . $col->name . '" ' . self::sqliteAffinity($col);

        // INTEGER PRIMARY KEY (alias for ROWID) — SQLite-specific autoincrement form.
        if ($col->autoincrement && $allowAutoIncrement && $col->primary) {
            $sql .= ' PRIMARY KEY AUTOINCREMENT';
        } elseif ($col->primary && $single) {
            $sql .= ' PRIMARY KEY';
        }

        if (!$col->nullable && !($col->autoincrement && $col->primary)) {
            $sql .= ' NOT NULL';
        }

        if ($col->hasDefault) {
            if ($col->useCurrentTs) {
                // Column kind decides the literal: timestamp = unix int, datetime = ISO.
                $sql .= match ($col->opts['kind'] ?? '') {
                    'datetime' => " DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))",
                    'date'     => " DEFAULT (date('now'))",
                    default    => " DEFAULT (strftime('%s','now'))",
                };
            } else {
                $sql .= ' DEFAULT ' . self::quoteLiteral($col->default);
            }
        }

        if ($col->unsigned) {
            $sql .= ' CHECK("' . $col->name . '" >= 0)';
        }
        if (!empty($col->opts['enum']) && is_array($col->opts['enum'])) {
            $vals = implode(',', array_map(self::quoteLiteral(...), $col->opts['enum']));
            $sql .= ' CHECK("' . $col->name . '" IN (' . $vals . '))';
        }
        if ($col->check !== null) {
            $sql .= ' CHECK(' . $col->check . ')';
        }

        return $sql;
    }

    private static function compileForeignKey(array $fk): string
    {
        $cols    = implode(',', array_map(fn($c) => '"' . $c . '"', $fk['cols']));
        $refCols = implode(',', array_map(fn($c) => '"' . $c . '"', $fk['ref_cols']));
        $sql = 'FOREIGN KEY (' . $cols . ') REFERENCES "' . $fk['ref_table'] . '"(' . $refCols . ')';
        if (!empty($fk['on_delete']) && $fk['on_delete'] !== 'NO ACTION') $sql .= ' ON DELETE ' . $fk['on_delete'];
        if (!empty($fk['on_update']) && $fk['on_update'] !== 'NO ACTION') $sql .= ' ON UPDATE ' . $fk['on_update'];
        return $sql;
    }

    private static function sqliteAffinity(ColumnDefinition $col): string
    {
        $t = strtoupper($col->type);
        // Strip parameterised types — SQLite ignores them but they're documented:
        if (str_starts_with($t, 'NUMERIC')) return 'NUMERIC';
        return match (true) {
            $t === 'BIGINT'  => 'INTEGER',
            $t === 'INTEGER' => 'INTEGER',
            $t === 'TEXT'    => 'TEXT',
            $t === 'BLOB'    => 'BLOB',
            $t === 'REAL'    => 'REAL',
            default          => $t,
        };
    }

    private static function quoteLiteral(mixed $v): string
    {
        if ($v === null)       return 'NULL';
        if (is_bool($v))       return $v ? '1' : '0';
        if (is_int($v))        return (string) $v;
        if (is_float($v))      return (string) $v;
        // Single-quote escaped for use as a SQL literal.
        return "'" . str_replace("'", "''", (string) $v) . "'";
    }

    private static function findFkFor(Blueprint $bp, string $col): array
    {
        foreach ($bp->foreignKeys as $fk) {
            if (in_array($col, $fk['cols'], true)) return $fk;
        }
        return [];
    }

    /** Crude pluralisation good enough for FK conventions. */
    private static function guessPlural(string $base): string
    {
        if (preg_match('/(s|x|z|sh|ch)$/i', $base)) return $base . 'es';
        if (preg_match('/[^aeiou]y$/i', $base))     return preg_replace('/y$/i', 'ies', $base) ?? $base . 's';
        return $base . 's';
    }
}

// =====================================================================
// SECTION 14.5 — QUERY BUILDER (fluent terse API on top of Db)
// =====================================================================

/**
 * QueryBuilder — chainable fluent layer over `Db::*`. Always uses prepared
 * statements; identifiers are validated. Returned by `Db::table()` and
 * `R::table()`.
 *
 *   R::table('users')->where('active', 1)->where('age', '>', 18)
 *                    ->orderBy('id', 'desc')->take(10)->get();
 *   R::table('users')->find(7);
 *   R::table('users')->whereIn('role', ['admin','staff'])->pluck('email');
 *   R::table('orders')->where('id', $id)->increment('quantity', 2);
 *   R::table('users')->upsert(['email'=>'a@b','name'=>'A'], ['email']);
 *   R::table('logs')->where('user_id', $u)->chunk(500, fn($rows) => …);
 *   $page = R::table('posts')->where('published', 1)->latest()->paginate($p, 20);
 *
 * Supported operators: =, !=, <>, <, <=, >, >=, LIKE, NOT LIKE, IS, IS NOT.
 */
final class QueryBuilder
{
    private string $table;
    /** @var array<int,string> */
    private array $cols = ['*'];
    private bool $distinct = false;
    /** @var array<int,array{kind:string,sql:string,bindings:array<int,scalar|null>,bool:string}> */
    private array $wheres = [];
    /** @var array<int,array{table:string,a:string,op:string,b:string,type:string}> */
    private array $joins = [];
    /** @var array<int,string> */
    private array $orders = [];
    /** @var array<int,string> */
    private array $groups = [];
    /** @var array<int,array{sql:string,bindings:array<int,scalar|null>}> */
    private array $havings = [];
    private ?int $limit  = null;
    private ?int $offset = null;
    private string $primaryKey = 'id';
    /**
     * Pending eager loads. Each entry resolves at terminator time and runs a
     * single secondary query (no N+1). Shape varies by `kind`:
     *   hasMany / hasOne: ['kind','as','table','fk','localKey']
     *   belongsTo:        ['kind','as','table','fk','ownerKey']
     *   belongsToMany:    ['kind','as','table','pivot','fkLocal','fkRelated','localKey','relatedKey']
     *
     * @var array<int,array<string,mixed>>
     */
    private array $with = [];

    public function __construct(string $table)
    {
        Db::ident($table); // validate
        $this->table = $table;
    }

    /** Override the primary key (defaults to "id"). Used by `find()`. */
    public function key(string $name): self
    {
        Db::ident($name);
        $this->primaryKey = $name;
        return $this;
    }

    // -------- Builder --------

    public function select(string|array $cols): self
    {
        $cols = is_array($cols) ? $cols : array_map('trim', explode(',', $cols));
        $this->cols = [];
        foreach ($cols as $c) {
            if ($c === '*') { $this->cols[] = '*'; continue; }
            // allow "col AS alias"
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(\s+AS\s+([A-Za-z_][A-Za-z0-9_]*))?$/i', $c, $m)) {
                $this->cols[] = '"' . $m[1] . '"' . (isset($m[3]) ? ' AS "' . $m[3] . '"' : '');
            } else {
                throw new InvalidArgumentException("Invalid select column: {$c}");
            }
        }
        return $this;
    }

    public function distinct(bool $on = true): self { $this->distinct = $on; return $this; }

    /**
     * Where helper. Accepts:
     *   ->where('id', 7)                       (= 7)
     *   ->where('age', '>', 18)
     *   ->where(['active' => 1, 'role' => 'admin'])  (all =, AND)
     *   ->where(fn($q) => $q->where(...)->orWhere(...))   (grouped)
     */
    public function where(string|array|callable $col, mixed $opOrVal = null, mixed $val = null): self
    {
        return $this->addWhere('AND', $col, $opOrVal, $val, func_num_args());
    }

    public function orWhere(string|array|callable $col, mixed $opOrVal = null, mixed $val = null): self
    {
        return $this->addWhere('OR', $col, $opOrVal, $val, func_num_args());
    }

    public function whereIn(string $col, array $values): self     { return $this->addInWhere('AND', $col, $values, false); }
    public function orWhereIn(string $col, array $values): self    { return $this->addInWhere('OR',  $col, $values, false); }
    public function whereNotIn(string $col, array $values): self   { return $this->addInWhere('AND', $col, $values, true);  }
    public function orWhereNotIn(string $col, array $values): self { return $this->addInWhere('OR',  $col, $values, true);  }

    public function whereNull(string $col): self        { Db::ident($col); $this->wheres[] = ['kind'=>'raw','sql'=>'"' . $col . '" IS NULL',     'bindings'=>[], 'bool'=>'AND']; return $this; }
    public function whereNotNull(string $col): self     { Db::ident($col); $this->wheres[] = ['kind'=>'raw','sql'=>'"' . $col . '" IS NOT NULL', 'bindings'=>[], 'bool'=>'AND']; return $this; }
    public function whereBetween(string $col, mixed $low, mixed $high): self
    {
        Db::ident($col);
        $this->wheres[] = ['kind'=>'raw','sql'=>'"' . $col . '" BETWEEN ? AND ?', 'bindings'=>[$low,$high], 'bool'=>'AND'];
        return $this;
    }
    /**
     * Raw LIKE pattern. The `$pattern` is forwarded verbatim — `%` and `_`
     * are SQL wildcards, so an unescaped user-supplied pattern lets the
     * caller match anything. Prefer `whereLikeContains/Prefix/Suffix` when
     * `$pattern` came from user input.
     */
    public function whereLike(string $col, string $pattern): self
    {
        return $this->where($col, 'LIKE', $pattern);
    }

    /**
     * Safe LIKE helpers. They escape `%`, `_` and `\\` in `$value` and emit
     * `LIKE ... ESCAPE '\\'` so user input can't smuggle wildcards into the
     * pattern (OWASP A03 — input validation around LIKE).
     */
    public function whereLikeContains(string $col, string $value): self
    {
        return $this->whereLikeEscaped($col, '%' . self::escapeLike($value) . '%');
    }
    public function whereLikePrefix(string $col, string $value): self
    {
        return $this->whereLikeEscaped($col, self::escapeLike($value) . '%');
    }
    public function whereLikeSuffix(string $col, string $value): self
    {
        return $this->whereLikeEscaped($col, '%' . self::escapeLike($value));
    }

    private function whereLikeEscaped(string $col, string $pattern): self
    {
        Db::ident($col);
        // ESCAPE expects a single-character literal — we use backslash. The
        // SQL fragment renders literally as: LIKE ? ESCAPE '\'
        $this->wheres[] = [
            'kind'     => 'raw',
            'sql'      => '"' . $col . "\" LIKE ? ESCAPE '\\'",
            'bindings' => [$pattern],
            'bool'     => 'AND',
        ];
        return $this;
    }

    /** Escape SQL LIKE wildcards (`%`, `_`) and the escape char (`\`) itself. */
    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['kind'=>'raw','sql'=>$sql,'bindings'=>array_values($bindings),'bool'=>'AND'];
        return $this;
    }

    public function join(string $table, string $a, string $op, string $b, string $type = 'INNER'): self
    {
        Db::ident($table);
        $this->joins[] = [
            'table' => $table,
            'a'     => $this->qualify($a),
            'op'    => $this->safeOp($op),
            'b'     => $this->qualify($b),
            'type'  => strtoupper($type),
        ];
        return $this;
    }
    public function leftJoin(string $table, string $a, string $op, string $b): self  { return $this->join($table, $a, $op, $b, 'LEFT'); }
    public function rightJoin(string $table, string $a, string $op, string $b): self { return $this->join($table, $a, $op, $b, 'RIGHT'); }

    public function orderBy(string $col, string $dir = 'asc'): self
    {
        Db::ident($col);
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $this->orders[] = '"' . $col . '" ' . $dir;
        return $this;
    }
    public function latest(string $col = 'id'): self  { return $this->orderBy($col, 'desc'); }
    public function oldest(string $col = 'id'): self  { return $this->orderBy($col, 'asc'); }

    public function groupBy(string ...$cols): self
    {
        foreach ($cols as $c) { Db::ident($c); $this->groups[] = '"' . $c . '"'; }
        return $this;
    }

    public function having(string $col, string $op, mixed $val): self
    {
        Db::ident($col);
        $op = $this->safeOp($op);
        $this->havings[] = ['sql' => '"' . $col . '" ' . $op . ' ?', 'bindings' => [$val]];
        return $this;
    }

    public function limit(int $n): self  { $this->limit  = max(0, $n); return $this; }
    public function offset(int $n): self { $this->offset = max(0, $n); return $this; }
    public function take(int $n): self   { return $this->limit($n); }
    public function skip(int $n): self   { return $this->offset($n); }

    // -------- Relationships (eager loading) --------

    /**
     * Eager-load a hasMany relationship. After `get()`/`first()`, every parent
     * row will gain an `$row[$as]` key holding the (possibly empty) array of
     * matching child rows. Always runs as a single extra `IN (…)` query — no
     * N+1.
     *
     *   $users = R::table('users')->with('posts', 'user_id')->get();
     *   foreach ($users as $u) { foreach ($u['posts'] as $post) { … } }
     *
     * `$as` defaults to the child-table name; pass `$asAlias` to rename.
     */
    public function with(string $childTable, string $fk, string $localKey = 'id', ?string $asAlias = null): self
    {
        Db::ident($childTable); Db::ident($fk); Db::ident($localKey);
        $this->with[] = [
            'kind' => 'hasMany', 'as' => $asAlias ?? $childTable,
            'table' => $childTable, 'fk' => $fk, 'localKey' => $localKey,
        ];
        return $this;
    }

    /** Eager-load a hasOne relationship — first match per parent (or null). */
    public function withOne(string $childTable, string $fk, string $localKey = 'id', ?string $asAlias = null): self
    {
        Db::ident($childTable); Db::ident($fk); Db::ident($localKey);
        $this->with[] = [
            'kind' => 'hasOne', 'as' => $asAlias ?? $childTable,
            'table' => $childTable, 'fk' => $fk, 'localKey' => $localKey,
        ];
        return $this;
    }

    /**
     * Eager-load a belongsTo (parent) relationship. For each row, fetches the
     * parent referenced by `$childFk` from `$parentTable`.
     *
     *   $posts = R::table('posts')->withParent('users','user_id','user')->get();
     *   foreach ($posts as $p) { echo $p['user']['email'] ?? '?'; }
     */
    public function withParent(string $parentTable, string $childFk, ?string $asAlias = null, string $ownerKey = 'id'): self
    {
        Db::ident($parentTable); Db::ident($childFk); Db::ident($ownerKey);
        $this->with[] = [
            'kind' => 'belongsTo', 'as' => $asAlias ?? $parentTable,
            'table' => $parentTable, 'fk' => $childFk, 'ownerKey' => $ownerKey,
        ];
        return $this;
    }

    /**
     * Eager-load a belongsToMany (pivot) relationship. Runs one extra query
     * joining through `$pivot`.
     *
     *   $users = R::table('users')->withPivot('roles','role_user','user_id','role_id')->get();
     */
    public function withPivot(
        string $relatedTable,
        string $pivot,
        string $fkLocal,
        string $fkRelated,
        ?string $asAlias = null,
        string $localKey = 'id',
        string $relatedKey = 'id',
    ): self {
        Db::ident($relatedTable); Db::ident($pivot); Db::ident($fkLocal);
        Db::ident($fkRelated); Db::ident($localKey); Db::ident($relatedKey);
        $this->with[] = [
            'kind' => 'belongsToMany', 'as' => $asAlias ?? $relatedTable,
            'table' => $relatedTable, 'pivot' => $pivot,
            'fkLocal' => $fkLocal, 'fkRelated' => $fkRelated,
            'localKey' => $localKey, 'relatedKey' => $relatedKey,
        ];
        return $this;
    }

    // -------- Inspection --------

    public function toSql(): string         { return $this->compileSelect(); }
    public function getBindings(): array    { return $this->collectBindings(); }
    public function explain(): array        { return Db::explain($this->compileSelect(), $this->collectBindings()); }

    // -------- Terminators --------

    /** @return array<int,array<string,mixed>> */
    public function get(): array
    {
        $rows = Db::select($this->compileSelect(), $this->collectBindings());
        return $this->with === [] ? $rows : $this->resolveEagerLoads($rows);
    }

    /** @return array<string,mixed>|null */
    public function first(): ?array
    {
        $orig  = $this->limit;
        $this->limit = 1;
        try {
            $row = Db::selectOne($this->compileSelect(), $this->collectBindings());
        } finally {
            $this->limit = $orig;
        }
        if ($row === null || $this->with === []) return $row;
        $resolved = $this->resolveEagerLoads([$row]);
        return $resolved[0] ?? null;
    }

    /**
     * Run each registered `with` as exactly one secondary query and stitch the
     * results back into `$rows`. No N+1.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function resolveEagerLoads(array $rows): array
    {
        if ($rows === []) return $rows;

        foreach ($this->with as $rel) {
            $kind = $rel['kind'];
            $as   = $rel['as'];

            if ($kind === 'hasMany' || $kind === 'hasOne') {
                $localKey = $rel['localKey'];
                $ids = self::collectIds($rows, $localKey);
                if ($ids === []) {
                    foreach ($rows as &$r) $r[$as] = ($kind === 'hasMany') ? [] : null;
                    unset($r);
                    continue;
                }
                $sql = 'SELECT * FROM "' . $rel['table'] . '" WHERE "' . $rel['fk']
                     . '" IN (' . self::placeholders(count($ids)) . ')';
                $children = Db::select($sql, array_values($ids));
                $bucket = [];
                foreach ($children as $c) {
                    $bucket[(string) ($c[$rel['fk']] ?? '')][] = $c;
                }
                foreach ($rows as &$r) {
                    $key = (string) ($r[$localKey] ?? '');
                    $list = $bucket[$key] ?? [];
                    $r[$as] = ($kind === 'hasOne') ? ($list[0] ?? null) : $list;
                }
                unset($r);
                continue;
            }

            if ($kind === 'belongsTo') {
                $fk = $rel['fk'];
                $ownerKey = $rel['ownerKey'];
                $ids = self::collectIds($rows, $fk);
                if ($ids === []) {
                    foreach ($rows as &$r) $r[$as] = null; unset($r);
                    continue;
                }
                $sql = 'SELECT * FROM "' . $rel['table'] . '" WHERE "' . $ownerKey
                     . '" IN (' . self::placeholders(count($ids)) . ')';
                $parents = Db::select($sql, array_values($ids));
                $byKey = [];
                foreach ($parents as $p) $byKey[(string) ($p[$ownerKey] ?? '')] = $p;
                foreach ($rows as &$r) {
                    $r[$as] = $byKey[(string) ($r[$fk] ?? '')] ?? null;
                }
                unset($r);
                continue;
            }

            if ($kind === 'belongsToMany') {
                $localKey = $rel['localKey'];
                $ids = self::collectIds($rows, $localKey);
                if ($ids === []) {
                    foreach ($rows as &$r) $r[$as] = []; unset($r);
                    continue;
                }
                $sql = 'SELECT "' . $rel['table'] . '".*, "' . $rel['pivot'] . '"."' . $rel['fkLocal'] . '" AS __pivot_local '
                     . 'FROM "' . $rel['table'] . '" '
                     . 'INNER JOIN "' . $rel['pivot'] . '" ON "' . $rel['pivot'] . '"."' . $rel['fkRelated']
                     . '" = "' . $rel['table'] . '"."' . $rel['relatedKey'] . '" '
                     . 'WHERE "' . $rel['pivot'] . '"."' . $rel['fkLocal']
                     . '" IN (' . self::placeholders(count($ids)) . ')';
                $related = Db::select($sql, array_values($ids));
                $bucket = [];
                foreach ($related as $row) {
                    $key = (string) ($row['__pivot_local'] ?? '');
                    unset($row['__pivot_local']);
                    $bucket[$key][] = $row;
                }
                foreach ($rows as &$r) {
                    $r[$as] = $bucket[(string) ($r[$localKey] ?? '')] ?? [];
                }
                unset($r);
                continue;
            }
        }
        return $rows;
    }

    private static function collectIds(array $rows, string $key): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $v = $r[$key] ?? null;
            if ($v === null) continue;
            $ids[(string) $v] = $v;
        }
        return array_values($ids);
    }

    private static function placeholders(int $n): string
    {
        return $n <= 0 ? '' : implode(',', array_fill(0, $n, '?'));
    }

    /** Find a row by primary key. Null on miss. */
    public function find(int|string $id): ?array
    {
        return (clone $this)->where($this->primaryKey, $id)->first();
    }

    public function value(string $col): mixed
    {
        $orig = $this->cols; $origLimit = $this->limit;
        $this->cols = [Db::ident($col)];
        $this->limit = 1;
        try { return Db::value($this->compileSelect(), $this->collectBindings()); }
        finally { $this->cols = $orig; $this->limit = $origLimit; }
    }

    /** Single-column array (or `'val' => 'key'` map when `$keyCol` is given). */
    public function pluck(string $col, ?string $keyCol = null): array
    {
        $cols = $keyCol ? [Db::ident($col), Db::ident($keyCol)] : [Db::ident($col)];
        $origCols = $this->cols;
        $this->cols = $cols;
        try {
            $rows = Db::select($this->compileSelect(), $this->collectBindings());
        } finally {
            $this->cols = $origCols;
        }
        if ($keyCol === null) return array_column($rows, $col);
        $out = [];
        foreach ($rows as $r) $out[$r[$keyCol]] = $r[$col];
        return $out;
    }

    public function exists(): bool
    {
        $sql = 'SELECT EXISTS (' . $this->compileSelect(true) . ')';
        return (bool) Db::value($sql, $this->collectBindings());
    }

    public function count(string $col = '*'): int   { return (int)   $this->aggregate('COUNT', $col); }
    public function sum(string $col): int|float     { return         $this->aggregate('SUM',   $col) ?? 0; }
    public function avg(string $col): float         { return (float) ($this->aggregate('AVG',   $col) ?? 0); }
    public function min(string $col): mixed         { return         $this->aggregate('MIN',   $col); }
    public function max(string $col): mixed         { return         $this->aggregate('MAX',   $col); }

    /**
     * Process the result set in chunks of `$size`. Stops if the callback
     * returns `false`. Uses keyset pagination (by primary key) — orders of
     * magnitude faster than OFFSET for large tables.
     *
     * @param callable(array<int,array<string,mixed>>):mixed $cb
     */
    public function chunk(int $size, callable $cb): void
    {
        if ($size < 1) throw new InvalidArgumentException('chunk size must be >= 1');
        $lastId = null;
        // Force a deterministic order on the primary key for keyset paging.
        $this->orders[] = '"' . $this->primaryKey . '" ASC';

        while (true) {
            $q = clone $this;
            if ($lastId !== null) $q->where($this->primaryKey, '>', $lastId);
            $q->limit = $size;
            $rows = Db::select($q->compileSelect(), $q->collectBindings());
            if (empty($rows)) return;
            if ($cb($rows) === false) return;
            $last = end($rows);
            $lastId = $last[$this->primaryKey] ?? null;
            if ($lastId === null || count($rows) < $size) return;
        }
    }

    /** Iterate one row at a time. Stops if the callback returns `false`. */
    public function each(callable $cb): void
    {
        foreach (Db::iterate($this->compileSelect(), $this->collectBindings()) as $i => $row) {
            if ($cb($row, $i) === false) return;
        }
    }

    /**
     * Paginated result. Returns:
     *   ['data' => [...], 'meta' => ['page','pages','per_page','total','from','to','has_prev','has_next']]
     */
    public function paginate(int $page = 1, int $perPage = 20): array
    {
        $count = (clone $this)->count();
        $meta  = Util::pagination($count, $perPage, $page);
        $this->limit  = $perPage;
        $this->offset = $meta['offset'];
        return ['data' => $this->get(), 'meta' => $meta];
    }

    // -------- Mutators --------

    public function insert(array $data): string                                      { return Db::insert($this->table, $data); }
    public function insertMany(array $rows): int                                     { return Db::insertMany($this->table, $rows); }
    public function upsert(array $data, array $uniqueBy, ?array $update = null): int { return Db::upsert($this->table, $data, $uniqueBy, $update); }

    public function update(array $data): int
    {
        $this->requireWhere('UPDATE');
        if (empty($data)) throw new InvalidArgumentException('Update data must not be empty');
        $sets = []; $vals = [];
        foreach ($data as $col => $val) {
            Db::ident((string) $col);
            $sets[] = '"' . $col . '" = ?';
            $vals[] = $val;
        }
        [$where, $whereBindings] = $this->compileWheres();
        $sql = 'UPDATE "' . $this->table . '" SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return Db::query($sql, array_merge($vals, $whereBindings))->rowCount();
    }

    public function delete(): int
    {
        $this->requireWhere('DELETE');
        [$where, $whereBindings] = $this->compileWheres();
        $sql = 'DELETE FROM "' . $this->table . '" WHERE ' . $where;
        return Db::query($sql, $whereBindings)->rowCount();
    }

    public function increment(string $col, int|float $by = 1): int
    {
        $this->requireWhere('UPDATE (increment)');
        Db::ident($col);
        [$where, $whereBindings] = $this->compileWheres();
        $sql = 'UPDATE "' . $this->table . '" SET "' . $col . '" = "' . $col . '" + ? WHERE ' . $where;
        return Db::query($sql, array_merge([$by], $whereBindings))->rowCount();
    }

    public function decrement(string $col, int|float $by = 1): int
    {
        return $this->increment($col, -$by);
    }

    /** Truncate the table — SQLite uses DELETE then resets the autoincrement. */
    public function truncate(): void
    {
        Db::pdo()->exec('DELETE FROM "' . $this->table . '"');
        // Reset autoincrement counter if sqlite_sequence row exists.
        try {
            Db::query('DELETE FROM sqlite_sequence WHERE name = ?', [$this->table]);
        } catch (Throwable) { /* sqlite_sequence may not exist */ }
    }

    // -------- Internal --------

    private function aggregate(string $fn, string $col): mixed
    {
        $expr = $col === '*' ? '*' : Db::ident($col);
        $origCols = $this->cols;
        $this->cols = [$fn . '(' . $expr . ')'];
        try { return Db::value($this->compileSelect(), $this->collectBindings()); }
        finally { $this->cols = $origCols; }
    }

    /**
     * @param int $argc number of arguments in the public-facing `where()` /
     *                  `orWhere()` call. Required to disambiguate
     *                  `where('col', null)` from `where('col', '=', null)`
     *                  and from a missing second arg (callable/array form).
     */
    private function addWhere(string $bool, mixed $col, mixed $opOrVal, mixed $val, int $argc = 4): self
    {
        if (is_callable($col)) {
            $sub = new self($this->table);
            $col($sub);
            if (empty($sub->wheres)) return $this;
            [$sql, $bindings] = $sub->compileWheres();
            $this->wheres[] = ['kind'=>'group','sql'=>'(' . $sql . ')','bindings'=>$bindings,'bool'=>$bool];
            return $this;
        }
        if (is_array($col)) {
            foreach ($col as $k => $v) {
                Db::ident((string) $k);
                if ($v === null) {
                    $this->wheres[] = ['kind'=>'raw','sql'=>'"' . $k . '" IS NULL','bindings'=>[],'bool'=>$bool];
                } else {
                    $this->wheres[] = ['kind'=>'simple','sql'=>'"' . $k . '" = ?','bindings'=>[$v],'bool'=>$bool];
                }
            }
            return $this;
        }
        Db::ident($col);

        // 3-arg form: ->where('col', $op, $val)
        if ($argc >= 3) {
            $op = $this->safeOp((string) $opOrVal);
            if ($val === null) {
                // Auto-promote = / != to IS NULL / IS NOT NULL — using a
                // bound NULL with `=` never matches in SQL.
                if ($op === '=' || $op === 'IS') {
                    $this->wheres[] = ['kind'=>'raw','sql'=>'"' . $col . '" IS NULL','bindings'=>[],'bool'=>$bool];
                    return $this;
                }
                if ($op === '!=' || $op === '<>' || $op === 'IS NOT') {
                    $this->wheres[] = ['kind'=>'raw','sql'=>'"' . $col . '" IS NOT NULL','bindings'=>[],'bool'=>$bool];
                    return $this;
                }
                throw new InvalidArgumentException("Operator [{$op}] not supported with NULL value");
            }
            $this->wheres[] = ['kind'=>'simple','sql'=>'"' . $col . '" ' . $op . ' ?','bindings'=>[$val],'bool'=>$bool];
            return $this;
        }

        // 2-arg form: ->where('col', $value)
        if ($opOrVal === null) {
            // ->where('col', null) — user wants "col IS NULL".
            $this->wheres[] = ['kind'=>'raw','sql'=>'"' . $col . '" IS NULL','bindings'=>[],'bool'=>$bool];
            return $this;
        }
        $this->wheres[] = ['kind'=>'simple','sql'=>'"' . $col . '" = ?','bindings'=>[$opOrVal],'bool'=>$bool];
        return $this;
    }

    private function addInWhere(string $bool, string $col, array $values, bool $not): self
    {
        Db::ident($col);
        if (empty($values)) {
            // Match nothing for IN / everything for NOT IN.
            $this->wheres[] = ['kind'=>'raw','sql'=>$not ? '1=1' : '1=0','bindings'=>[],'bool'=>$bool];
            return $this;
        }
        $marks = implode(',', array_fill(0, count($values), '?'));
        $sql   = '"' . $col . '" ' . ($not ? 'NOT IN' : 'IN') . ' (' . $marks . ')';
        $this->wheres[] = ['kind'=>'raw','sql'=>$sql,'bindings'=>array_values($values),'bool'=>$bool];
        return $this;
    }

    private function safeOp(string $op): string
    {
        $op = strtoupper(trim($op));
        $allowed = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'];
        if (!in_array($op, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported operator: {$op}");
        }
        return $op;
    }

    private function qualify(string $expr): string
    {
        // Allow "table.col" or "col"
        if (str_contains($expr, '.')) {
            [$t, $c] = explode('.', $expr, 2);
            Db::ident($t); Db::ident($c);
            return '"' . $t . '"."' . $c . '"';
        }
        Db::ident($expr);
        return '"' . $expr . '"';
    }

    private function compileSelect(bool $forExists = false): string
    {
        $cols = $forExists ? ['1'] : $this->cols;
        $sql  = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
              . implode(', ', $cols)
              . ' FROM "' . $this->table . '"';

        foreach ($this->joins as $j) {
            $sql .= ' ' . $j['type'] . ' JOIN "' . $j['table'] . '" ON ' . $j['a'] . ' ' . $j['op'] . ' ' . $j['b'];
        }
        if (!empty($this->wheres)) {
            [$where] = $this->compileWheres();
            $sql .= ' WHERE ' . $where;
        }
        if (!empty($this->groups))  $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        if (!empty($this->havings)) $sql .= ' HAVING '   . implode(' AND ', array_column($this->havings, 'sql'));
        if (!empty($this->orders))  $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        if ($this->limit  !== null) $sql .= ' LIMIT '    . (int) $this->limit;
        if ($this->offset !== null) $sql .= ' OFFSET '   . (int) $this->offset;
        return $sql;
    }

    /** @return array{0:string,1:array<int,scalar|null>} */
    private function compileWheres(): array
    {
        $sqlParts = []; $bindings = [];
        foreach ($this->wheres as $i => $w) {
            $prefix = $i === 0 ? '' : ' ' . $w['bool'] . ' ';
            $sqlParts[] = $prefix . $w['sql'];
            foreach ($w['bindings'] as $b) $bindings[] = $b;
        }
        return [implode('', $sqlParts), $bindings];
    }

    private function collectBindings(): array
    {
        $out = [];
        foreach ($this->wheres as $w) foreach ($w['bindings'] as $b) $out[] = $b;
        foreach ($this->havings as $h) foreach ($h['bindings'] as $b) $out[] = $b;
        return $out;
    }

    private function requireWhere(string $verb): void
    {
        if (empty($this->wheres)) {
            throw new InvalidArgumentException(
                "Refusing {$verb} without WHERE — call ->whereRaw('1=1') explicitly to affect all rows.",
            );
        }
    }
}

// =====================================================================
// SECTION 14.5 — UPLOAD GUARD (fluent, secure-by-default file uploads)
// =====================================================================

/**
 * Fluent upload validator + storer.
 *
 * Replaces the verbose `R::saveFile($field, $dir, $opts)` form with a
 * chainable builder that bakes OWASP-safe defaults in:
 *
 *   $path = R::upload('avatar')
 *       ->image()                     // preset: jpg/png/webp/gif, magic-bytes sniff
 *       ->maxSize('2mb')
 *       ->maxDimensions(2000, 2000)
 *       ->stripExif()                 // GD re-encode to drop metadata
 *       ->to('storage/avatars')
 *       ->save();
 *
 *   $paths = R::uploads('photos')
 *       ->image()
 *       ->maxFiles(10)
 *       ->to('storage/gallery')
 *       ->saveAll();
 *
 * Defaults applied to every upload (cannot be disabled):
 *   - is_uploaded_file() guard
 *   - finfo magic-bytes mime sniff (Content-Type header is untrusted)
 *   - traversal containment via Util::resolvePathInside()
 *   - random hashed filename (16 bytes hex + ext)
 *   - atomic temp move + chmod 0640
 *   - .htaccess deny-execute stub auto-written into a fresh upload dir
 *
 * After save(), call Util::uploadErrors() to read structured failures.
 */
final class UploadGuard
{
    private string $field;
    private bool $multi;
    private string $destDir = 'storage/uploads';

    /** @var string[] */
    private array $allowMime = [];
    /** @var string[] */
    private array $allowExt  = [];
    private int   $maxBytes  = 0;          // 0 = derive from ROUTER_MAX_UPLOAD_BYTES
    private int   $minBytes  = 0;
    private int   $maxFiles  = 1;
    private ?int  $maxWidth  = null;
    private ?int  $maxHeight = null;
    private ?int  $minWidth  = null;
    private ?int  $minHeight = null;
    private bool  $stripExif = false;
    private bool  $hashedName = true;
    private bool  $overwrite = false;
    private int   $chmod     = 0640;
    /** @var ?callable(string $absPath, array $meta):bool */
    private $scanner = null;
    /** @var ?callable(string $name):string */
    private $renamer = null;

    /** @var array<int,array{field:string,reason:string,detail?:mixed}> */
    private static array $errors = [];

    /**
     * Test seam — closures that replace `is_uploaded_file` / `move_uploaded_file`.
     * Production code MUST NOT set these; they exist only so the smoke harness
     * can simulate HTTP uploads from CLI. Both default to null (= use the real
     * built-ins).
     *
     * @internal
     * @var ?Closure(string):bool
     */
    public static ?Closure $isUploadedFn = null;
    /**
     * @internal
     * @var ?Closure(string,string):bool
     */
    public static ?Closure $moveUploadedFn = null;

    public function __construct(string $field, bool $multi = false)
    {
        $this->field = $field;
        $this->multi = $multi;
    }

    // -------- Presets (each pins a strict mime + ext + size) --------

    /** Bitmap images. PNG/JPEG/WebP/GIF, max 5 MiB by default. */
    public function image(int $maxBytes = 5_242_880): self
    {
        $this->allowMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $this->allowExt  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if ($this->maxBytes === 0) $this->maxBytes = $maxBytes;
        return $this;
    }

    /** User avatar. Stricter — 1 MiB, 1024×1024 cap, EXIF stripped. */
    public function avatar(int $maxBytes = 1_048_576): self
    {
        $this->image($maxBytes);
        $this->maxDimensions(1024, 1024);
        $this->stripExif();
        return $this;
    }

    /** PDF and common Office docs. 10 MiB default. */
    public function document(int $maxBytes = 10_485_760): self
    {
        $this->allowMime = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
        ];
        $this->allowExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];
        if ($this->maxBytes === 0) $this->maxBytes = $maxBytes;
        return $this;
    }

    /** ZIP / tar / gz archives. 50 MiB default. */
    public function archive(int $maxBytes = 52_428_800): self
    {
        $this->allowMime = [
            'application/zip',
            'application/x-zip-compressed',
            'application/x-tar',
            'application/gzip',
            'application/x-gzip',
        ];
        $this->allowExt = ['zip', 'tar', 'gz', 'tgz'];
        if ($this->maxBytes === 0) $this->maxBytes = $maxBytes;
        return $this;
    }

    /** mp4 / webm video; mp3 / ogg audio. 100 MiB default. */
    public function media(int $maxBytes = 104_857_600): self
    {
        $this->allowMime = [
            'video/mp4', 'video/webm', 'video/ogg',
            'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/webm', 'audio/wav',
        ];
        $this->allowExt = ['mp4', 'webm', 'ogg', 'mp3', 'm4a', 'wav'];
        if ($this->maxBytes === 0) $this->maxBytes = $maxBytes;
        return $this;
    }

    // -------- Explicit overrides --------

    /** @param string[] $list */
    public function mime(array $list): self        { $this->allowMime = array_values($list); return $this; }

    /** @param string[] $list */
    public function extensions(array $list): self  { $this->allowExt  = array_map(static fn($e) => strtolower(ltrim((string)$e, '.')), $list); return $this; }

    /** Accepts an int (bytes) or a string like "2mb" / "500kb" / "10MB". */
    public function maxSize(int|string $size): self { $this->maxBytes = self::parseSize($size); return $this; }
    public function minSize(int|string $size): self { $this->minBytes = self::parseSize($size); return $this; }

    public function maxDimensions(int $w, int $h): self { $this->maxWidth = $w; $this->maxHeight = $h; return $this; }
    public function minDimensions(int $w, int $h): self { $this->minWidth = $w; $this->minHeight = $h; return $this; }

    public function maxFiles(int $n): self { $this->maxFiles = max(1, $n); return $this; }

    /**
     * Re-encode images via GD on save, dropping all metadata (EXIF / GPS / ICC).
     * Silently no-op on platforms without GD; pass `strict: true` to fail
     * the upload instead.
     */
    public function stripExif(bool $strict = false): self
    {
        $this->stripExif = true;
        if ($strict && !extension_loaded('gd')) {
            self::recordError($this->field, 'gd_not_loaded', 'stripExif(strict) requires the GD extension.');
        }
        return $this;
    }

    /** Use a random 16-byte hex filename + the validated extension. (default ON) */
    public function hashedName(bool $on = true): self     { $this->hashedName = $on; return $this; }

    /** Keep the user's original (sanitised) filename. Off by default for security. */
    public function keepOriginalName(): self              { $this->hashedName = false; return $this; }

    /** Custom renamer — receives the sanitised user name + ext, returns the basename to write. */
    public function rename(callable $fn): self            { $this->renamer = $fn; return $this; }

    public function chmod(int $mode): self                { $this->chmod = $mode; return $this; }
    public function overwrite(bool $on = true): self      { $this->overwrite = $on; return $this; }

    /**
     * Pluggable virus / content scanner. Receives the absolute tmp path + the
     * detected metadata; must return true if the file is safe. A false return
     * fails the upload and surfaces `scan_failed` in `uploadErrors()`.
     *
     * @param callable(string $absPath, array{name:string,size:int,mime:string,ext:string}):bool $fn
     */
    public function scanWith(callable $fn): self          { $this->scanner = $fn; return $this; }

    /** Destination dir relative to ROUTER_BASE_DIR (or absolute). */
    public function to(string $dir): self                 { $this->destDir = $dir; return $this; }

    /** Throws (401) unless a session user is logged in. Easy auth gate. */
    public function requireAuth(int $statusCode = 401): self
    {
        if (Util::userId() === null) {
            Util::abort($statusCode, 'Authentication required for upload.');
        }
        return $this;
    }

    // -------- Terminators --------

    /**
     * Save a single file. Returns the absolute storage path on success, or
     * `null` on any validation / IO failure. Inspect `Util::uploadErrors()`
     * for structured details when `null` is returned.
     */
    public function save(): ?string
    {
        self::$errors = [];
        $files = $this->collectFiles();
        if ($files === []) {
            self::recordError($this->field, 'no_file', 'No file uploaded for this field.');
            return null;
        }
        $first = $files[0];
        return $this->persist($first);
    }

    /**
     * Save every file in a multi-file field. Returns the array of stored
     * paths (possibly empty). Per-file failures are added to `uploadErrors()`
     * but do not abort the whole batch.
     *
     * @return string[]
     */
    public function saveAll(): array
    {
        self::$errors = [];
        $files = $this->collectFiles();
        if ($files === []) {
            self::recordError($this->field, 'no_file', 'No files uploaded for this field.');
            return [];
        }
        if (count($files) > $this->maxFiles) {
            self::recordError($this->field, 'too_many_files', [
                'count' => count($files),
                'max'   => $this->maxFiles,
            ]);
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            $p = $this->persist($f);
            if ($p !== null) $out[] = $p;
        }
        return $out;
    }

    /**
     * Validate everything but do not move the file. Returns an array of
     * structured errors; empty array means the upload is acceptable.
     *
     * @return array<int,array<string,mixed>>
     */
    public function validate(): array
    {
        self::$errors = [];
        $files = $this->collectFiles();
        if ($files === []) {
            self::recordError($this->field, 'no_file');
            return self::$errors;
        }
        foreach ($files as $f) $this->validateFile($f);
        return self::$errors;
    }

    // -------- Internals --------

    /** @return array<int,array<string,mixed>> */
    private function collectFiles(): array
    {
        return $this->multi ? Util::files($this->field) : array_filter([Util::file($this->field)]);
    }

    /** @return ?string */
    private function persist(array $f): ?string
    {
        $meta = $this->validateFile($f);
        if ($meta === null) return null;

        // Pre-create the destination directory so realpath() can resolve it
        // for the containment check. Validate the *path* first (no traversal,
        // no null bytes, must remain inside ROUTER_BASE_DIR) before mkdir.
        $clean = $this->destDir;
        if ($clean === '' || str_contains($clean, "\0") || str_contains($clean, '..')) {
            self::recordError($this->field, 'dest_outside_base', $this->destDir);
            return null;
        }
        $destCandidate = self::isAbsolute($clean)
            ? $clean
            : rtrim(ROUTER_BASE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
              . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($clean, '/\\'));

        $existed = is_dir($destCandidate);
        if (!$existed && !@mkdir($destCandidate, 0750, true) && !is_dir($destCandidate)) {
            self::recordError($this->field, 'mkdir_failed', $destCandidate);
            return null;
        }

        // Now the parent exists; verify it lives inside ROUTER_BASE_DIR.
        $destAbs = Util::resolvePathInside($destCandidate, ROUTER_BASE_DIR, mustBeDir: true);
        if ($destAbs === null) {
            self::recordError($this->field, 'dest_outside_base', $this->destDir);
            return null;
        }
        if (!$existed) self::writeDenyExecuteStubs($destAbs);

        $finalName = $this->makeFinalName($meta);
        $target    = rtrim($destAbs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalName;

        if (!$this->overwrite && file_exists($target)) {
            $finalName = bin2hex(random_bytes(8)) . '_' . $finalName;
            $target    = rtrim($destAbs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalName;
        }

        if ($this->stripExif && in_array($meta['mime'], ['image/jpeg','image/png','image/webp','image/gif'], true) && extension_loaded('gd')) {
            $reencoded = $this->reencodeImage($f['tmp_name'], $meta['mime'], $target);
            if ($reencoded === false) {
                self::recordError($this->field, 'reencode_failed', $f['tmp_name']);
                return null;
            }
            // Upon successful re-encode the temp upload file is no longer needed.
            @unlink($f['tmp_name']);
        } else {
            $moved = self::$moveUploadedFn !== null
                ? (bool) (self::$moveUploadedFn)($f['tmp_name'], $target)
                : @move_uploaded_file($f['tmp_name'], $target);
            if (!$moved) {
                self::recordError($this->field, 'move_failed', $target);
                return null;
            }
        }

        @chmod($target, $this->chmod);
        return $target;
    }

    /** @return ?array{name:string,size:int,mime:string,ext:string} */
    private function validateFile(array $f): ?array
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::recordError($this->field, 'php_error', (int) $f['error']);
            return null;
        }
        $tmpName = (string) ($f['tmp_name'] ?? '');
        $isUpload = self::$isUploadedFn !== null
            ? (bool) (self::$isUploadedFn)($tmpName)
            : is_uploaded_file($tmpName);
        if (!$isUpload) {
            self::recordError($this->field, 'not_uploaded', $tmpName);
            return null;
        }

        $size = (int) $f['size'];
        $cap  = $this->maxBytes > 0 ? $this->maxBytes : (defined('ROUTER_MAX_UPLOAD_BYTES') ? ROUTER_MAX_UPLOAD_BYTES : 5_242_880);
        if ($size > $cap) {
            self::recordError($this->field, 'too_large', ['size' => $size, 'max' => $cap]);
            return null;
        }
        if ($this->minBytes > 0 && $size < $this->minBytes) {
            self::recordError($this->field, 'too_small', ['size' => $size, 'min' => $this->minBytes]);
            return null;
        }

        // Magic-bytes mime sniff — never trust the client `Content-Type`.
        $mime = Util::mime($f['tmp_name']);
        if ($this->allowMime !== [] && !in_array($mime, $this->allowMime, true)) {
            self::recordError($this->field, 'mime_not_allowed', ['detected' => $mime, 'allowed' => $this->allowMime]);
            return null;
        }

        $sanitised = Util::sanitiseFilename((string) $f['name']);
        $ext = strtolower(pathinfo($sanitised, PATHINFO_EXTENSION));
        if ($this->allowExt !== [] && !in_array($ext, $this->allowExt, true)) {
            self::recordError($this->field, 'ext_not_allowed', ['detected' => $ext, 'allowed' => $this->allowExt]);
            return null;
        }

        // Image dimension caps via getimagesize (memory-blowup defence).
        if (($this->maxWidth || $this->maxHeight || $this->minWidth || $this->minHeight)
            && str_starts_with($mime, 'image/')) {
            $info = @getimagesize($f['tmp_name']);
            if (!is_array($info)) {
                self::recordError($this->field, 'image_unreadable');
                return null;
            }
            [$w, $h] = $info;
            if ($this->maxWidth  && $w > $this->maxWidth)  { self::recordError($this->field, 'too_wide',  ['w' => $w, 'max' => $this->maxWidth]);  return null; }
            if ($this->maxHeight && $h > $this->maxHeight) { self::recordError($this->field, 'too_tall',  ['h' => $h, 'max' => $this->maxHeight]); return null; }
            if ($this->minWidth  && $w < $this->minWidth)  { self::recordError($this->field, 'too_narrow',['w' => $w, 'min' => $this->minWidth]);  return null; }
            if ($this->minHeight && $h < $this->minHeight) { self::recordError($this->field, 'too_short', ['h' => $h, 'min' => $this->minHeight]); return null; }
        }

        $meta = ['name' => $sanitised, 'size' => $size, 'mime' => $mime, 'ext' => $ext];

        if ($this->scanner !== null) {
            try {
                $clean = (bool) ($this->scanner)($f['tmp_name'], $meta);
            } catch (Throwable $e) {
                self::recordError($this->field, 'scan_threw', $e->getMessage());
                return null;
            }
            if (!$clean) {
                self::recordError($this->field, 'scan_failed', $meta);
                return null;
            }
        }
        return $meta;
    }

    private function makeFinalName(array $meta): string
    {
        if ($this->renamer !== null) {
            $name = (string) ($this->renamer)($meta['name']);
            return Util::sanitiseFilename($name === '' ? 'file' : $name);
        }
        if ($this->hashedName) {
            return bin2hex(random_bytes(16)) . ($meta['ext'] !== '' ? '.' . $meta['ext'] : '');
        }
        return $meta['name'];
    }

    private function reencodeImage(string $src, string $mime, string $dst): bool
    {
        if (!extension_loaded('gd')) return false;
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            'image/gif'  => @imagecreatefromgif($src),
            default      => false,
        };
        if (!$img) return false;

        // Preserve transparency where applicable.
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $tmp = $dst . '.tmp.' . bin2hex(random_bytes(6));
        $ok  = match ($mime) {
            'image/jpeg' => @imagejpeg($img, $tmp, 90),
            'image/png'  => @imagepng($img, $tmp, 6),
            'image/webp' => function_exists('imagewebp') ? @imagewebp($img, $tmp, 90) : false,
            'image/gif'  => @imagegif($img, $tmp),
            default      => false,
        };
        imagedestroy($img);
        if (!$ok) { @unlink($tmp); return false; }
        if (!@rename($tmp, $dst)) { @unlink($tmp); return false; }
        return true;
    }

    private static function isAbsolute(string $p): bool
    {
        if ($p === '') return false;
        if ($p[0] === '/' || $p[0] === '\\') return true;
        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $p);
    }

    private static function parseSize(int|string $v): int
    {
        if (is_int($v)) return max(0, $v);
        $s = strtolower(trim($v));
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(b|k|kb|m|mb|g|gb)?$/', $s, $m) !== 1) return 0;
        $n = (float) $m[1];
        return match ($m[2] ?? 'b') {
            'k', 'kb' => (int) ($n * 1024),
            'm', 'mb' => (int) ($n * 1024 * 1024),
            'g', 'gb' => (int) ($n * 1024 * 1024 * 1024),
            default   => (int) $n,
        };
    }

    /** Drop a deny-execute stub into a brand-new upload directory. */
    private static function writeDenyExecuteStubs(string $dir): void
    {
        $apache = <<<'HT'
# Auto-generated by Router.php — refuse to execute anything in this directory.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .phtml .phar .pl .py .jsp .asp .htm .html .shtml .sh .cgi
RemoveType    .php .phtml .phar .pl .py .jsp .asp .htm .html .shtml .sh .cgi
AddType text/plain .php .phtml .phar .pl .py .jsp .asp .htm .html .shtml .sh .cgi
<FilesMatch "\.(php|phtml|phar|pl|py|jsp|asp|sh|cgi)$">
    Require all denied
</FilesMatch>
HT;
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess',  $apache);

        $iis = <<<'IIS'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <handlers accessPolicy="Read" />
  </system.webServer>
</configuration>
IIS;
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'web.config', $iis);

        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }

    private static function recordError(string $field, string $reason, mixed $detail = null): void
    {
        self::$errors[] = $detail === null
            ? ['field' => $field, 'reason' => $reason]
            : ['field' => $field, 'reason' => $reason, 'detail' => $detail];
    }

    /** @return array<int,array<string,mixed>> */
    public static function errors(): array { return self::$errors; }
}

// =====================================================================
// SECTION 15 — UTIL HELPER (auto-accessible from route .php files)
// =====================================================================

/**
 * Util — terse helpers exposed globally. Routes can call Util::json($data),
 * Util::view('home', ['title'=>'Hi']), Util::redirect('/login'), Util::abort(403)
 * etc. without `use` or extra `require` calls (they all live in the global
 * namespace alongside the rest of this file).
 *
 * Aliased as `R` for brevity:
 *   R::json($data); R::view('home'); R::abort(404); R::input(); R::env('FOO');
 */
final class Util
{
    private static array $flash = [];

    // -------- Response writers --------

    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            foreach ($headers as $k => $v) self::header((string) $k, (string) $v);
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function text(string $body, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $body;
        exit;
    }

    public static function html(string $body, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo $body;
        exit;
    }

    public static function ok(mixed $data = ['ok' => true]): never        { self::json($data, 200); }
    public static function created(mixed $data = ['ok' => true]): never   { self::json($data, 201); }
    public static function noContent(): never
    {
        if (!headers_sent()) http_response_code(204);
        exit;
    }

    /**
     * HTTP redirect. Defaults to **same-origin only**: arbitrary external URLs
     * are rejected to defend against open-redirect attacks (OWASP A03). Pass
     * `$external = true` to opt-in to redirecting away from the application
     * (e.g. for OAuth callback flows). CR/LF in `$location` is always rejected
     * (response-splitting defence).
     */
    public static function redirect(string $location, int $status = 302, bool $external = false): never
    {
        // Response-splitting defence: refuse any control character in $location.
        if (preg_match('/[\r\n\0]/', $location)) {
            self::abort(400, 'Invalid redirect target');
        }

        if (!$external && !self::isSafeRedirectTarget($location)) {
            // Cross-origin redirect requested without opt-in — refuse.
            CacheEngine::logEvent('warn', 'Refused open redirect', ['target' => $location]);
            self::abort(400, 'Cross-origin redirect refused; pass external=true to opt-in.');
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $location);
        }
        exit;
    }

    /**
     * True when $location is safe to redirect to without opt-in (relative path,
     * absolute path on this host, or absolute URL whose host matches the
     * current/trusted host list).
     */
    public static function isSafeRedirectTarget(string $location): bool
    {
        if ($location === '') return false;
        // Pure path (relative or rooted) — same origin by definition.
        if ($location[0] === '/' && (strlen($location) === 1 || $location[1] !== '/')) return true;
        if ($location[0] !== '/' && !preg_match('#^[a-z][a-z0-9+\-.]*:#i', $location)) return true;

        // Protocol-relative (//host/...) or absolute URL — host must match.
        $parsed = @parse_url($location);
        if (!is_array($parsed) || !isset($parsed['host'])) return false;
        $host    = strtolower((string) $parsed['host']);
        $current = DomainResolver::currentHost();
        if ($host === $current) return true;

        $trusted = defined('ROUTER_TRUSTED_HOSTS') ? ROUTER_TRUSTED_HOSTS : [];
        return !empty($trusted)
            && DomainResolver::hostMatches(array_map('strval', $trusted), $host);
    }

    /**
     * Render a PHP view from ROUTER_VIEWS_DIR. Variables in $vars are extracted
     * into the template's local scope. Use Util::e() to escape.
     */
    public static function view(string $name, array $vars = [], int $status = 200): never
    {
        $views = ROUTER_VIEWS_DIR;
        $base  = (str_starts_with($views, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $views))
            ? $views
            : ROUTER_BASE_DIR . DIRECTORY_SEPARATOR . $views;
        $base = realpath($base);
        if ($base === false) self::abort(500, 'Views directory not found');

        $name = ltrim(str_replace(['\\', '..'], ['/', ''], $name), '/');
        $rel  = preg_match('/\.php$/', $name) ? $name : $name . '.php';
        $file = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));

        if ($file === false || !str_starts_with($file, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            self::abort(500, "View not found: {$name}");
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
        }
        // Render the template in an isolated scope.
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })($file, $vars);
        exit;
    }

    public static function abort(int $code, string $message = ''): never
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=UTF-8');
        }
        $msg = $message !== '' ? $message : self::statusText($code);
        echo SecurityLayer::buildErrorPage($code, self::statusText($code), $msg);
        exit;
    }

    public static function status(int $code): void
    {
        if (!headers_sent()) http_response_code($code);
    }

    /**
     * Emit an HTTP response header. Both name and value are checked for CR/LF
     * to defend against response-splitting (OWASP A03). PHP's own `header()`
     * already rejects CR/LF in the value, but we also reject it on the name
     * and on null bytes to fail loudly rather than silently dropping the call.
     */
    public static function header(string $name, string $value): void
    {
        // Validate inputs *before* checking headers_sent so a malformed call
        // is reported immediately, even when output has already been buffered.
        if ($name === '' || preg_match('/[^A-Za-z0-9!#$%&\'*+\-.^_`|~]/', $name)) {
            throw new InvalidArgumentException('Invalid HTTP header name');
        }
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('Invalid HTTP header value');
        }
        if (headers_sent()) return;
        header("{$name}: {$value}");
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // -------- Request introspection --------

    /** @return array{get:array,post:array,json:array,files:array,raw:string} */
    public static function input(): array { return SecurityLayer::collectInput(); }

    public static function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) return $_GET;
        return $_GET[$key] ?? $default;
    }

    public static function body(?string $key = null, mixed $default = null): mixed
    {
        $in = self::input();
        $merged = $in['post'] + $in['json'];
        if ($key === null) return $merged;
        return $merged[$key] ?? $default;
    }

    public static function ip(): string                   { return SecurityLayer::clientIp(); }
    public static function host(): string                 { return DomainResolver::currentHost(); }
    public static function folder(): ?string              { return DomainResolver::currentFolder(); }
    public static function isHttps(): bool                { return SecurityLayer::isHttps(); }
    public static function method(): string               { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
    public static function path(): string                 { return Router::parseUri(); }
    public static function userAgent(): string            { return (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''); }
    public static function bearer(): ?string
    {
        $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/^Bearer\s+(.+)$/i', $h, $m) ? trim($m[1]) : null;
    }

    // -------- Routing helpers --------

    public static function url(string $name, array $params = []): string  { return Router::url($name, $params); }
    public static function csrf(): string                                  { return SecurityLayer::csrfToken(); }
    /** Validate a submitted CSRF token (or auto-pull from POST/header). */
    public static function csrfValidate(?string $submitted = null): bool   { return SecurityLayer::csrfValidate($submitted); }
    /** Rotate the CSRF token (call after privilege change). */
    public static function csrfRotate(): void                              { SecurityLayer::csrfRotate(); }
    public static function nonce(): string                                 { return SecurityLayer::cspNonce(); }

    // -------- CSP fluent builder --------

    /**
     * Returns a fresh fluent CSP builder. Calling `apply()` overwrites the
     * Content-Security-Policy header that init() emitted so route files can
     * tighten or relax the policy per-page.
     */
    public static function csp(): Csp { return Csp::make(); }

    // -------- Env / config --------

    public static function env(string $key, mixed $default = null): mixed { return Env::get($key, $default); }

    // -------- Cache --------

    public static function cacheGet(string $key): mixed                    { return CacheEngine::get($key); }
    public static function cacheSet(string $key, mixed $value, int $ttl = 3600): bool { return CacheEngine::set($key, $value, $ttl); }
    public static function cacheDel(string $key): void                     { CacheEngine::delete($key); }

    // -------- Cookies --------

    /**
     * Set a cookie with secure-by-default attributes (HttpOnly, SameSite=Lax,
     * Secure when HTTPS). Pass `$opts` to override any of `expires`, `path`,
     * `domain`, `secure`, `httponly`, `samesite`. Cookie name is validated
     * to defeat header-injection. Allowed SameSite values: Lax, Strict, None.
     */
    public static function cookie(string $name, string $value = '', int $ttl = 0, array $opts = []): void
    {
        // Validate inputs *before* the headers_sent short-circuit so calling
        // cookie() with bad input always raises (defence-in-depth).
        if ($name === '' || preg_match('/[\x00-\x20\x7f()<>@,;:\\\\"\/\[\]?={}\s]/', $name)) {
            throw new InvalidArgumentException('Invalid cookie name');
        }
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('Invalid cookie value');
        }
        if (headers_sent()) return;
        $defaults = [
            'expires'  => $ttl > 0 ? time() + $ttl : 0,
            'path'     => '/',
            'secure'   => SecurityLayer::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $merged = $opts + $defaults;
        // Validate samesite, force Secure when SameSite=None per browser rules.
        $merged['samesite'] = match (strtolower((string) ($merged['samesite'] ?? 'Lax'))) {
            'strict' => 'Strict',
            'none'   => 'None',
            default  => 'Lax',
        };
        if ($merged['samesite'] === 'None') $merged['secure'] = true;
        setcookie($name, $value, $merged);
    }

    public static function getCookie(string $name, ?string $default = null): ?string
    {
        return $_COOKIE[$name] ?? $default;
    }

    // -------- Session / flash --------

    public static function session(?string $key = null, mixed $default = null): mixed
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure'   => SecurityLayer::isHttps(),
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
        if ($key === null) return $_SESSION ?? [];
        return $_SESSION[$key] ?? $default;
    }

    public static function sessionPut(string $key, mixed $value): void
    {
        self::session(); // ensure started
        $_SESSION[$key] = $value;
    }

    public static function flash(string $key, mixed $value = null): mixed
    {
        self::session();
        if (func_num_args() === 1) {
            $v = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);
            return $v;
        }
        $_SESSION['_flash'][$key] = $value;
        return $value;
    }

    // -------- Logging --------

    public static function log(string $message, array $context = [], string $level = 'error'): void
    {
        CacheEngine::logEvent($level, $message, $context);
    }

    public static function logInfo(string $msg, array $ctx = []): void  { CacheEngine::logEvent('info',  $msg, $ctx); }
    public static function logWarn(string $msg, array $ctx = []): void  { CacheEngine::logEvent('warn',  $msg, $ctx); }
    public static function logError(string $msg, array $ctx = []): void { CacheEngine::logEvent('error', $msg, $ctx); }

    /**
     * Record a panic — a silent failure / warning that *must* be visible to
     * the operator. Routes to `.ncache/logs/Panic.txt` (separate from the
     * request log) so it stays noticeable.
     */
    public static function panic(string $msg, array $ctx = []): void    { CacheEngine::panic($msg, $ctx); }

    /** Read the most recent N panic blocks from Panic.txt (newest first). */
    public static function panicTail(int $n = 20): array                { return CacheEngine::panicTail($n); }

    // -------- Files (uploads, reads, writes, downloads) --------

    /**
     * Return a single uploaded file as a structured array, or null when the
     * field is missing / empty / failed. Multi-file fields return the first.
     *
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public static function file(string $field): ?array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f)) return null;

        if (is_array($f['name'] ?? null)) {
            // Multi-file field — pick the first valid entry.
            foreach (self::files($field) as $entry) {
                return $entry;
            }
            return null;
        }
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        return [
            'name'     => (string) ($f['name']     ?? ''),
            'type'     => (string) ($f['type']     ?? ''),
            'tmp_name' => (string) ($f['tmp_name'] ?? ''),
            'error'    => (int)    ($f['error']    ?? UPLOAD_ERR_OK),
            'size'     => (int)    ($f['size']     ?? 0),
        ];
    }

    /**
     * Return ALL entries of a multi-file upload field.
     *
     * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function files(string $field): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f)) return [];
        if (!is_array($f['name'] ?? null)) {
            $single = self::file($field);
            return $single === null ? [] : [$single];
        }
        $out = [];
        foreach ((array) $f['name'] as $i => $_n) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = [
                'name'     => (string) ($f['name'][$i]     ?? ''),
                'type'     => (string) ($f['type'][$i]     ?? ''),
                'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''),
                'error'    => (int)    ($f['error'][$i]    ?? UPLOAD_ERR_OK),
                'size'     => (int)    ($f['size'][$i]     ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Start a fluent upload validator for a single file field.
     *
     *   $path = R::upload('avatar')->image()->maxSize('2mb')->to('storage')->save();
     */
    public static function upload(string $field): UploadGuard
    {
        return new UploadGuard($field, multi: false);
    }

    /**
     * Start a fluent upload validator for a multi-file field.
     *
     *   $paths = R::uploads('photos')->image()->maxFiles(10)->to('storage')->saveAll();
     */
    public static function uploads(string $field): UploadGuard
    {
        return new UploadGuard($field, multi: true);
    }

    /**
     * Structured per-field errors from the most recent UploadGuard save() call.
     * Each entry: ['field' => string, 'reason' => string, 'detail' => mixed?].
     *
     * Reasons: no_file, php_error, not_uploaded, too_large, too_small,
     * mime_not_allowed, ext_not_allowed, image_unreadable, too_wide,
     * too_tall, too_narrow, too_short, scan_threw, scan_failed,
     * dest_outside_base, mkdir_failed, move_failed, reencode_failed,
     * too_many_files, gd_not_loaded.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function uploadErrors(): array
    {
        return UploadGuard::errors();
    }

    /**
     * Build a stateless, time-limited HMAC-signed download URL.
     *
     * Verified by the route handler returned from `signedDownloadRoute()`.
     * The signature binds the relative path, expiry, and disposition; tampering
     * with any of those parameters invalidates the URL.
     *
     *   $url = R::signedUrl('avatars/abc.jpg', ttl: 3600);
     *   Router::get('/_dl', R::signedDownloadRoute('storage'));
     */
    public static function signedUrl(
        string $relPath,
        int $ttl = 3600,
        string $disposition = 'inline',
        string $route = '/_dl'
    ): string {
        $relPath     = ltrim(str_replace('\\', '/', $relPath), '/');
        $expires     = time() + max(1, $ttl);
        $disposition = $disposition === 'attachment' ? 'attachment' : 'inline';
        $payload     = $relPath . "\n" . $expires . "\n" . $disposition;
        $sig         = self::base64url(hash_hmac('sha256', $payload, APP_SECRET, true));
        $qs = http_build_query([
            'p'   => $relPath,
            'exp' => $expires,
            'd'   => $disposition,
            'sig' => $sig,
        ]);
        return $route . '?' . $qs;
    }

    /**
     * Validate a signed URL's query parameters. Returns the relative path on
     * success, or null on any tampering / expiry / shape failure.
     *
     * @param array<string,mixed>|null $query Defaults to $_GET.
     */
    public static function verifySignedUrl(?array $query = null): ?string
    {
        $q = $query ?? $_GET;
        $rel  = (string) ($q['p']   ?? '');
        $exp  = (int)    ($q['exp'] ?? 0);
        $disp = (string) ($q['d']   ?? 'inline');
        $sig  = (string) ($q['sig'] ?? '');
        if ($rel === '' || $exp <= 0 || $sig === '') return null;
        if (str_contains($rel, "\0") || str_contains($rel, '..')) return null;
        if ($disp !== 'inline' && $disp !== 'attachment') return null;
        if (time() >= $exp) return null;

        $expected = self::base64url(hash_hmac('sha256', $rel . "\n" . $exp . "\n" . $disp, APP_SECRET, true));
        if (!hash_equals($expected, $sig)) return null;
        return $rel;
    }

    /**
     * Validate a signed URL and stream the file. Aborts with 403 / 404 on
     * any failure. Files are resolved inside `$baseDir` (relative to
     * ROUTER_BASE_DIR) — paths outside the base are refused.
     */
    public static function serveSigned(string $baseDir = 'storage'): never
    {
        $rel = self::verifySignedUrl();
        if ($rel === null) self::abort(403, 'Invalid or expired signed URL.');

        $baseAbs = self::resolvePathInside($baseDir, ROUTER_BASE_DIR, allowMissing: false, mustBeDir: true);
        if ($baseAbs === null) self::abort(500, 'Signed download base directory misconfigured.');

        $abs = self::resolvePathInside($rel, $baseAbs);
        if ($abs === null || !is_file($abs) || !is_readable($abs)) self::abort(404);

        $disp = (string) ($_GET['d'] ?? 'inline');
        self::streamFile($abs, basename($abs), inline: $disp !== 'attachment');
    }

    /**
     * Convenience wrapper: returns a callable suitable for `Router::get('/_dl', …)`
     * that validates the signed URL and streams the file.
     */
    public static function signedDownloadRoute(string $baseDir = 'storage'): callable
    {
        return static function () use ($baseDir): never {
            self::serveSigned($baseDir);
        };
    }

    /**
     * Persist an uploaded file to $destDir, validating size, mime and extension.
     * Returns the absolute destination path on success, or null on failure.
     *
     * @param array{
     *   max_bytes?: int,
     *   allow_mime?: string[],
     *   allow_ext?:  string[],
     *   filename?:   string,
     *   overwrite?:  bool
     * } $opts
     */
    public static function saveFile(string $field, string $destDir, array $opts = []): ?string
    {
        $file = self::file($field);
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            self::logError('Upload failed', ['field' => $field, 'file' => $file]);
            return null;
        }

        $maxBytes  = (int)   ($opts['max_bytes']  ?? (defined('ROUTER_MAX_UPLOAD_BYTES') ? ROUTER_MAX_UPLOAD_BYTES : 5 * 1024 * 1024));
        $allowMime = (array) ($opts['allow_mime'] ?? []);
        $allowExt  = array_map('strtolower', (array) ($opts['allow_ext']  ?? []));
        $forceName = isset($opts['filename']) ? (string) $opts['filename'] : null;
        $overwrite = (bool)  ($opts['overwrite']  ?? false);

        if ($maxBytes > 0 && $file['size'] > $maxBytes) {
            self::logWarn('Upload too large', ['size' => $file['size'], 'max' => $maxBytes]);
            return null;
        }
        $isUpl = UploadGuard::$isUploadedFn !== null
            ? (bool) (UploadGuard::$isUploadedFn)($file['tmp_name'])
            : is_uploaded_file($file['tmp_name']);
        if (!$isUpl) {
            self::logError('Upload tmp_name is not an uploaded file', ['tmp' => $file['tmp_name']]);
            return null;
        }

        // Mime sniff via finfo (or extension fallback) — the Content-Type header
        // sent by the client is untrusted.
        $detected = self::mime($file['tmp_name']);
        if (!empty($allowMime) && !in_array($detected, $allowMime, true)) {
            self::logWarn('Upload mime not allowed', ['detected' => $detected, 'allowed' => $allowMime]);
            return null;
        }

        $cleanName = self::sanitiseFilename($file['name']);
        $ext       = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
        if (!empty($allowExt) && !in_array($ext, $allowExt, true)) {
            self::logWarn('Upload extension not allowed', ['ext' => $ext, 'allowed' => $allowExt]);
            return null;
        }

        // Destination directory must resolve within the project root unless absolute.
        if ($destDir === '' || str_contains($destDir, "\0") || str_contains($destDir, '..')) {
            self::logError('Upload dest dir outside project', ['dir' => $destDir]);
            return null;
        }
        $destCandidate = (preg_match('#^(?:/|\\\\|[A-Za-z]:[\\\\/])#', $destDir) === 1)
            ? $destDir
            : rtrim(ROUTER_BASE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
              . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($destDir, '/\\'));
        if (!is_dir($destCandidate) && !@mkdir($destCandidate, 0750, true) && !is_dir($destCandidate)) {
            self::logError('Upload dest dir create failed', ['dir' => $destCandidate]);
            return null;
        }
        $destAbs = self::resolvePathInside($destCandidate, ROUTER_BASE_DIR, mustBeDir: true);
        if ($destAbs === null) {
            self::logError('Upload dest dir outside project', ['dir' => $destDir]);
            return null;
        }

        $finalName = $forceName !== null
            ? self::sanitiseFilename($forceName)
            : (bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : ''));
        $target = rtrim($destAbs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalName;

        if (!$overwrite && file_exists($target)) {
            $finalName = bin2hex(random_bytes(8)) . '_' . $finalName;
            $target    = rtrim($destAbs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalName;
        }

        $moved = UploadGuard::$moveUploadedFn !== null
            ? (bool) (UploadGuard::$moveUploadedFn)($file['tmp_name'], $target)
            : @move_uploaded_file($file['tmp_name'], $target);
        if (!$moved) {
            self::logError('move_uploaded_file failed', ['target' => $target]);
            return null;
        }
        @chmod($target, 0640);
        return $target;
    }

    /** Read a file safely, refusing paths outside $base (default = ROUTER_BASE_DIR). */
    public static function readFile(string $path, ?string $base = null): ?string
    {
        $abs = self::resolvePathInside($path, $base ?? ROUTER_BASE_DIR);
        if ($abs === null || !is_file($abs) || !is_readable($abs)) return null;
        $data = @file_get_contents($abs);
        return $data === false ? null : $data;
    }

    /** Atomic write — writes to a temp file in the same directory and renames. */
    public static function writeFile(string $path, string $content, ?string $base = null, int $mode = 0640): bool
    {
        $abs = self::resolvePathInside($path, $base ?? ROUTER_BASE_DIR, allowMissing: true);
        if ($abs === null) return false;

        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;

        $tmp = $abs . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) return false;
        if (!@rename($tmp, $abs)) { @unlink($tmp); return false; }
        @chmod($abs, $mode);
        return true;
    }

    public static function deleteFile(string $path, ?string $base = null): bool
    {
        $abs = self::resolvePathInside($path, $base ?? ROUTER_BASE_DIR);
        if ($abs === null || is_link($abs)) return false;
        return @unlink($abs);
    }

    public static function fileExists(string $path, ?string $base = null): bool
    {
        $abs = self::resolvePathInside($path, $base ?? ROUTER_BASE_DIR);
        return $abs !== null && file_exists($abs);
    }

    /**
     * Stream a file inline with the correct Content-Type. Refuses path traversal
     * and files outside $base. Supports HTTP Range requests so video/audio seeks
     * and large downloads behave correctly.
     */
    public static function send(string $path, ?string $base = null, ?string $asName = null): never
    {
        $abs = self::resolvePathInside($path, $base ?? ROUTER_BASE_DIR);
        if ($abs === null || !is_file($abs) || !is_readable($abs)) self::abort(404);

        self::streamFile($abs, $asName, inline: true);
    }

    /** Force the browser to download the file with a `Content-Disposition: attachment` header. */
    public static function download(string $path, ?string $asName = null, ?string $base = null): never
    {
        $abs = self::resolvePathInside($path, $base ?? ROUTER_BASE_DIR);
        if ($abs === null || !is_file($abs) || !is_readable($abs)) self::abort(404);

        self::streamFile($abs, $asName, inline: false);
    }

    /** Detect a file's MIME via finfo, falling back to extension lookup. */
    public static function mime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f !== false) {
                $detected = @finfo_file($f, $path);
                @finfo_close($f);
                if (is_string($detected) && $detected !== '') return $detected;
            }
        }
        return DomainResolver::mimeType($path);
    }

    /**
     * Strip directory separators, null bytes and traversal sequences from a
     * user-supplied filename. Returns a non-empty safe filename ('file' fallback).
     */
    public static function sanitiseFilename(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name); // control chars
        $name = str_replace(['/', '\\'], '_', $name);
        $name = preg_replace('/\.{2,}/', '.', $name) ?? $name;
        $name = trim($name, " .");
        if ($name === '' || $name === '.' || $name === '..') $name = 'file';
        // Limit length (255 is the FS-typical max basename).
        if (strlen($name) > 200) {
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $stem = substr($stem, 0, 200 - (strlen($ext) > 0 ? strlen($ext) + 1 : 0));
            $name = $stem . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    /** Create a secure tempfile under sys_get_temp_dir(). Returns the absolute path. */
    public static function tempFile(string $prefix = 'router_'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temp file');
        }
        @chmod($tmp, 0600);
        return $tmp;
    }

    /**
     * Resolve $path against $base safely. Refuses null bytes, prevents path
     * traversal, and ensures the resolved path is contained in $base.
     */
    public static function resolvePathInside(
        string $path,
        string $base,
        bool $allowMissing = false,
        bool $mustBeDir = false,
    ): ?string {
        if ($path === '' || str_contains($path, "\0")) return null;

        // Absolute paths are checked against $base (must be inside).
        $candidate = $path;
        if (!self::isAbsolutePath($candidate)) {
            $candidate = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                       . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($candidate, '/\\'));
        }

        $baseReal = realpath($base);
        if ($baseReal === false) return null;
        $prefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $real = realpath($candidate);
        if ($real === false) {
            if (!$allowMissing) return null;
            // Resolve parent and append basename so we can validate non-existent targets.
            $parent = realpath(dirname($candidate));
            if ($parent === false) return null;
            $real   = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($candidate);
        }
        if ($real !== rtrim($baseReal, DIRECTORY_SEPARATOR) && !str_starts_with($real, $prefix)) {
            return null;
        }
        if ($mustBeDir) {
            // For dest-dir use, accept missing, leaf-dir, or existing dir.
            if (file_exists($real) && !is_dir($real)) return null;
        }
        return $real;
    }

    private static function isAbsolutePath(string $p): bool
    {
        if ($p === '') return false;
        if ($p[0] === '/' || $p[0] === '\\') return true;
        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $p);
    }

    private static function streamFile(string $abs, ?string $asName, bool $inline): never
    {
        $size = filesize($abs);
        $mime = self::mime($abs);
        $name = self::sanitiseFilename($asName ?? basename($abs));
        // RFC 6266: percent-encode non-ASCII via filename*=UTF-8'' and supply
        // a sanitised ASCII-only `filename=` for legacy clients. Strip "/\
        // from the legacy form to defeat header-injection via quoted-string
        // escapes.
        $asciiName = preg_replace('/[^\x20-\x7e]/', '_', $name) ?? $name;
        $asciiName = str_replace(['"', '\\', "\r", "\n"], '_', $asciiName);
        $encName   = rawurlencode($name);
        $disp      = ($inline ? 'inline' : 'attachment')
                   . '; filename="' . $asciiName . '"'
                   . "; filename*=UTF-8''" . $encName;

        $start = 0; $end = $size > 0 ? $size - 1 : 0; $status = 200;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($size > 0 && is_string($range) && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            $rs = $m[1] === '' ? null : (int) $m[1];
            $re = $m[2] === '' ? null : (int) $m[2];
            if ($rs === null && $re !== null) { $start = max(0, $size - $re); $end = $size - 1; }
            elseif ($rs !== null) { $start = $rs; $end = $re !== null ? min($re, $size - 1) : $size - 1; }
            if ($start > $end || $start >= $size) {
                if (!headers_sent()) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                }
                exit;
            }
            $status = 206;
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . $disp);
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . ($end - $start + 1));
            if ($status === 206) header("Content-Range: bytes {$start}-{$end}/{$size}");
            $mtime = filemtime($abs);
            if ($mtime !== false) header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            header('X-Content-Type-Options: nosniff');
        }

        if (self::method() === 'HEAD') exit;

        $fp = @fopen($abs, 'rb');
        if ($fp === false) exit;
        try {
            if ($start > 0) fseek($fp, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(8192, $remaining));
                if ($chunk === false) break;
                echo $chunk;
                $remaining -= strlen($chunk);
                @ob_flush(); @flush();
            }
        } finally {
            fclose($fp);
        }
        exit;
    }

    // -------- Cryptography (passwords, encryption, random tokens) --------

    /** Hash a password using bcrypt or argon2id when available. */
    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /** Verify a plaintext password against a previously-hashed value. */
    public static function verify(string $password, string $hash): bool
    {
        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }

    /** True if `password_hash()` would produce a different (stronger) hash now. */
    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }

    /** Generate a URL-safe random token of $bytes bytes (default 32). */
    public static function randomToken(int $bytes = 32): string
    {
        if ($bytes < 1) $bytes = 32;
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * Encrypt arbitrary plaintext with AES-256-GCM. Returns a self-describing
     * URL-safe string that decrypt() can round-trip. $secret defaults to APP_SECRET.
     */
    public static function encrypt(string $plain, ?string $secret = null): string
    {
        $key   = self::deriveKey($secret ?? APP_SECRET);
        $iv    = random_bytes(12);
        $tag   = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) throw new RuntimeException('Encryption failed');
        return 'v1.' . rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
    }

    /** Decrypt a token produced by encrypt(). Returns null if tampering is detected. */
    public static function decrypt(string $token, ?string $secret = null): ?string
    {
        if (!str_starts_with($token, 'v1.')) return null;
        $blob = base64_decode(strtr(substr($token, 3), '-_', '+/'), true);
        if ($blob === false || strlen($blob) < 12 + 16 + 1) return null;

        $iv     = substr($blob, 0, 12);
        $tag    = substr($blob, 12, 16);
        $cipher = substr($blob, 28);
        $key    = self::deriveKey($secret ?? APP_SECRET);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    private static function deriveKey(string $secret): string
    {
        // 32 bytes for AES-256.
        return hash_hkdf('sha256', $secret, 32, 'router-aes256-gcm', '');
    }

    public static function constantTimeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    // -------- Validation --------

    /**
     * Quick single-field validator. Rule string is `|`-separated tokens like
     * 'required|string|max:255' / 'int|min:1|max:1000' / 'email' / 'in:a,b,c'.
     * Returns true on pass, false on fail.
     */
    public static function is(mixed $value, string $rules): bool
    {
        return self::validateValue($value, $rules) === null;
    }

    /**
     * Bulk validator: pass an associative array and rule map. Returns
     * ['ok' => bool, 'errors' => ['field' => 'message', …]].
     *
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @return array{ok:bool,errors:array<string,string>}
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $err = self::validateValue($data[$field] ?? null, (string) $rule);
            if ($err !== null) $errors[$field] = $err;
        }
        return ['ok' => empty($errors), 'errors' => $errors];
    }

    private static function validateValue(mixed $value, string $rules): ?string
    {
        $tokens = array_filter(array_map('trim', explode('|', $rules)), fn($t) => $t !== '');
        $required = in_array('required', $tokens, true);
        $isEmpty  = $value === null || $value === '' || (is_array($value) && empty($value));

        if ($isEmpty) return $required ? 'is required' : null;

        foreach ($tokens as $tok) {
            if ($tok === 'required') continue;
            [$rule, $arg] = array_pad(explode(':', $tok, 2), 2, null);
            $err = match ($rule) {
                'string'  => is_string($value)               ? null : 'must be a string',
                'int'     => filter_var($value, FILTER_VALIDATE_INT)  !== false ? null : 'must be an integer',
                'number'  => is_numeric($value)              ? null : 'must be numeric',
                'bool'    => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null ? null : 'must be boolean',
                'email'   => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'must be an email',
                'url'     => filter_var($value, FILTER_VALIDATE_URL)   !== false ? null : 'must be a URL',
                'ip'      => filter_var($value, FILTER_VALIDATE_IP)    !== false ? null : 'must be an IP',
                'uuid'    => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value) ? null : 'must be a UUID',
                'slug'    => preg_match('/^[A-Za-z0-9_-]+$/', (string) $value) ? null : 'must be a slug',
                'alnum'   => preg_match('/^[A-Za-z0-9]+$/', (string) $value)   ? null : 'must be alphanumeric',
                'min'     => self::compareLen($value, (int) $arg, '<')  ? 'is too short' : null,
                'max'     => self::compareLen($value, (int) $arg, '>')  ? 'is too long'  : null,
                'between' => self::checkBetween($value, (string) $arg)  ? null : "must be between {$arg}",
                'in'      => in_array((string) $value, array_map('trim', explode(',', (string) $arg)), true) ? null : "must be one of {$arg}",
                'regex'   => preg_match("#{$arg}#", (string) $value) === 1 ? null : 'has invalid format',
                default   => null,
            };
            if ($err !== null) return $err;
        }
        return null;
    }

    private static function compareLen(mixed $value, int $bound, string $op): bool
    {
        if (is_numeric($value)) {
            $n = $value + 0;
            return $op === '<' ? $n < $bound : $n > $bound;
        }
        $len = is_string($value)
            ? (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value))
            : (is_array($value) ? count($value) : 0);
        return $op === '<' ? $len < $bound : $len > $bound;
    }

    private static function checkBetween(mixed $value, string $arg): bool
    {
        $parts = array_map('trim', explode(',', $arg));
        if (count($parts) !== 2) return false;
        [$lo, $hi] = [(int) $parts[0], (int) $parts[1]];
        return !self::compareLen($value, $lo, '<') && !self::compareLen($value, $hi, '>');
    }

    // -------- Auth / brute-force throttle --------

    /**
     * Track a sensitive action (login, password reset, OTP) for rate-limited
     * abuse protection. Returns true while still under the limit, false (and
     * sends a 429) when exceeded.
     */
    public static function throttleAttempt(string $key, int $max = 5, int $window = 300): bool
    {
        $info = SecurityLayer::rateLimit($max, $window, bucket: 'attempt:' . $key);
        return !$info['exceeded'];
    }

    /**
     * Mark the current session as authenticated for $userId. Regenerates the
     * session id (defeats fixation), records IP and user-agent fingerprints,
     * and returns the stored snapshot.
     *
     * @return array{user_id:scalar,ip:string,ua:string,login_at:int}
     */
    public static function loginAs(int|string $userId): array
    {
        self::session();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $snap = [
            'user_id'  => $userId,
            'ip'       => self::ip(),
            'ua'       => substr(self::userAgent(), 0, 200),
            'login_at' => time(),
        ];
        $_SESSION['_auth'] = $snap;
        return $snap;
    }

    /** Forget any logged-in user for the current session. */
    public static function logout(): void
    {
        self::session();
        unset($_SESSION['_auth']);
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    /** Currently authenticated user id (scalar) or null. */
    public static function userId(): int|string|null
    {
        $a = self::session('_auth');
        return is_array($a) && isset($a['user_id']) ? $a['user_id'] : null;
    }

    /**
     * Verify the current request's user-agent matches the snapshot recorded
     * by `loginAs()`. UA changes mid-session usually mean session-cookie
     * theft (OWASP A07). When `$strictIp` is true the IP must also match.
     * Logs a warning and forcibly logs out on mismatch.
     */
    public static function verifyAuthFingerprint(bool $strictIp = false): bool
    {
        $a = self::session('_auth');
        if (!is_array($a) || !isset($a['user_id'])) return false;

        $expectedUa = (string) ($a['ua'] ?? '');
        $currentUa  = substr(self::userAgent(), 0, 200);
        $uaOk       = ($expectedUa === '' || hash_equals($expectedUa, $currentUa));

        $expectedIp = (string) ($a['ip'] ?? '');
        $ipOk       = !$strictIp || $expectedIp === '' || $expectedIp === self::ip();

        if (!$uaOk || !$ipOk) {
            CacheEngine::logEvent('warn', 'Auth fingerprint mismatch — forcing logout', [
                'user_id'       => $a['user_id'] ?? null,
                'expected_ip'   => $expectedIp,
                'current_ip'    => self::ip(),
                'ua_changed'    => !$uaOk,
                'ip_changed'    => !$ipOk,
            ]);
            self::logout();
            return false;
        }
        return true;
    }

    /** Convenience guard middleware factory: redirects unauth'd traffic. */
    public static function authGuard(string $redirectTo = '/login'): callable
    {
        return function () use ($redirectTo) {
            if (self::userId() === null) {
                if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                    self::json(['error' => 'unauthorized'], 401);
                }
                self::redirect($redirectTo);
            }
            return true;
        };
    }

    // -------- Database (SQLite, lazy-initialised) --------

    /** Underlying PDO instance (opens the database on first call). */
    public static function db(): PDO { return Db::pdo(); }

    /** @return array<int,array<string,mixed>> */
    public static function select(string $sql, array $params = []): array
    {
        return Db::select($sql, $params);
    }

    /** @return array<string,mixed>|null */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        return Db::selectOne($sql, $params);
    }

    public static function value(string $sql, array $params = []): mixed
    {
        return Db::value($sql, $params);
    }

    public static function insert(string $table, array $data): string
    {
        return Db::insert($table, $data);
    }

    public static function updateRow(string $table, array $data, string $where, array $whereParams = []): int
    {
        return Db::update($table, $data, $where, $whereParams);
    }

    public static function deleteRow(string $table, string $where, array $whereParams = []): int
    {
        return Db::delete($table, $where, $whereParams);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return Db::transaction($callback);
    }

    public static function execSql(string $sql): int { return Db::exec($sql); }

    /** @return Generator<int,array<string,mixed>> */
    public static function iterate(string $sql, array $params = []): Generator
    {
        return Db::iterate($sql, $params);
    }

    /**
     * @param string[] $migrations
     * @return string[]
     */
    public static function migrate(array $migrations, string $namespace = 'default'): array
    {
        return Db::migrate($migrations, $namespace);
    }

    /** Begin a fluent query for `$table`. See `QueryBuilder` for chainable API. */
    public static function table(string $table): QueryBuilder { return Db::table($table); }

    /** @param array<int,array<string,scalar|null>> $rows */
    public static function insertMany(string $table, array $rows): int
    {
        return Db::insertMany($table, $rows);
    }

    /**
     * @param array<string,scalar|null> $data
     * @param string[] $uniqueBy
     * @param string[]|null $update
     */
    public static function upsert(string $table, array $data, array $uniqueBy, ?array $update = null): int
    {
        return Db::upsert($table, $data, $uniqueBy, $update);
    }

    public static function dbOptimize(): void                  { Db::optimize(); }
    public static function dbAnalyze(?string $table = null): void { Db::analyze($table); }
    public static function dbVacuum(): void                    { Db::vacuum(); }

    /** @return array<int,array<string,mixed>> Query plan for `$sql`. */
    public static function explainSql(string $sql, array $params = []): array
    {
        return Db::explain($sql, $params);
    }

    /** @return array<string,mixed> PRAGMA snapshot. */
    public static function dbStats(): array { return Db::stats(); }

    // -------- Schema (proxies to Schema::*) --------

    /**
     * Declarative table creation. Idempotent — running twice on an existing
     * table is a no-op (CREATE TABLE IF NOT EXISTS). See `Blueprint` for the
     * full column / index / FK API.
     *
     * @param callable(Blueprint):void $cb
     * @return string[] Statements that were issued.
     */
    public static function schemaCreate(string $table, callable $cb): array { return Schema::create($table, $cb); }

    /** @param callable(Blueprint):void $cb */
    public static function schemaTable(string $table, callable $cb): array  { return Schema::table($table, $cb); }
    public static function schemaDropIfExists(string $table): void          { Schema::dropIfExists($table); }
    public static function schemaRename(string $from, string $to): void     { Schema::rename($from, $to); }
    public static function schemaHasTable(string $table): bool              { return Schema::hasTable($table); }
    public static function schemaHasColumn(string $table, string $col): bool { return Schema::hasColumn($table, $col); }

    // -------- Relationships --------

    /**
     * Direct hasMany lookup — return all rows in `$childTable` whose `$fk`
     * column equals the `$localKey` value of `$parent`. Pass either a row
     * array (uses `$parent[$localKey]`) or the bare key value.
     *
     *   $posts = R::hasMany($user, 'posts', 'user_id');
     *
     * @return array<int,array<string,mixed>>
     */
    public static function hasMany(array|int|string $parent, string $childTable, string $fk, string $localKey = 'id'): array
    {
        return Db::hasMany($parent, $childTable, $fk, $localKey);
    }

    /** Same shape as `hasMany`, but returns a single child or `null`. */
    public static function hasOne(array|int|string $parent, string $childTable, string $fk, string $localKey = 'id'): ?array
    {
        return Db::hasOne($parent, $childTable, $fk, $localKey);
    }

    /**
     * Direct belongsTo lookup — return the parent row referenced by
     * `$child[$fk]`. Pass either a row array (uses `$child[$fk]`) or the
     * bare key value.
     */
    public static function belongsTo(array|int|string $child, string $parentTable, string $fk, string $ownerKey = 'id'): ?array
    {
        return Db::belongsTo($child, $parentTable, $fk, $ownerKey);
    }

    /**
     * Direct belongsToMany lookup over a pivot table. Returns related rows
     * joined through `$pivot.<fkLocal> = parent.<localKey>` and
     * `$pivot.<fkRelated> = related.<relatedKey>`.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function belongsToMany(
        array|int|string $parent,
        string $relatedTable,
        string $pivot,
        string $fkLocal,
        string $fkRelated,
        string $localKey = 'id',
        string $relatedKey = 'id',
    ): array {
        return Db::belongsToMany($parent, $relatedTable, $pivot, $fkLocal, $fkRelated, $localKey, $relatedKey);
    }

    // -------- HTTP caching --------

    /**
     * Set `ETag` (strong) and short-circuit with 304 if the request's
     * `If-None-Match` matches. Pass body content (string) or a precomputed tag.
     */
    public static function etag(string $contentOrTag, bool $isTag = false): string
    {
        $tag = $isTag
            ? trim($contentOrTag, '"')
            : substr(hash('sha256', $contentOrTag), 0, 27);
        $value = '"' . $tag . '"';
        if (!headers_sent()) header('ETag: ' . $value);

        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($inm !== '') {
            // Header may be a comma-separated list per RFC 7232.
            foreach (array_map('trim', explode(',', $inm)) as $part) {
                if ($part === $value || $part === '*') {
                    if (!headers_sent()) http_response_code(304);
                    exit;
                }
            }
        }
        return $value;
    }

    /**
     * Set `Last-Modified` and 304 short-circuit if `If-Modified-Since` >=.
     * Accepts a Unix timestamp or a parseable date string.
     */
    public static function lastModified(int|string $time): string
    {
        $ts = is_int($time) ? $time : (int) strtotime($time);
        $http = gmdate('D, d M Y H:i:s', $ts) . ' GMT';
        if (!headers_sent()) header('Last-Modified: ' . $http);

        $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($ims !== '' && ($since = strtotime($ims)) !== false && $ts <= $since) {
            if (!headers_sent()) http_response_code(304);
            exit;
        }
        return $http;
    }

    /**
     * Emit a `Cache-Control` header. Pass directives as a single comma string
     * or an array. Aliases: `R::cacheControl(['public', 'max-age' => 300])`.
     */
    public static function cacheControl(array|string $directives): void
    {
        if (headers_sent()) return;
        if (is_string($directives)) { header('Cache-Control: ' . $directives); return; }

        $parts = [];
        foreach ($directives as $k => $v) {
            if (is_int($k))                  $parts[] = (string) $v;          // 'public', 'no-store', 'immutable'
            elseif ($v === true)             $parts[] = (string) $k;
            elseif ($v === false)            continue;
            else                             $parts[] = $k . '=' . $v;        // 'max-age=300', 's-maxage=600'
        }
        header('Cache-Control: ' . implode(', ', $parts));
    }

    public static function ifNoneMatch(): ?string
    {
        $h = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        return $h === '' ? null : $h;
    }

    public static function ifModifiedSince(): ?int
    {
        $h = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($h === '') return null;
        $ts = strtotime($h);
        return $ts === false ? null : $ts;
    }

    // -------- Request introspection --------

    /** Read a single request header (case-insensitive). */
    public static function header_in(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return (string) $_SERVER[$key];
        // Special cases not prefixed with HTTP_
        $alt = match (strtolower($name)) {
            'content-type'   => $_SERVER['CONTENT_TYPE']   ?? null,
            'content-length' => $_SERVER['CONTENT_LENGTH'] ?? null,
            default          => null,
        };
        return $alt !== null ? (string) $alt : null;
    }

    /** Collect all request headers as a name-preserving associative array. */
    public static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $out[$name] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']))   $out['content-type']   = (string) $_SERVER['CONTENT_TYPE'];
        if (isset($_SERVER['CONTENT_LENGTH'])) $out['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        return $out;
    }

    /** Parsed `Accept` header as an array of types ordered by quality desc. */
    public static function accept(): array
    {
        $h = self::header_in('Accept') ?? '';
        if ($h === '') return [];
        $items = [];
        foreach (explode(',', $h) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $bits = explode(';', $part);
            $type = strtolower(trim(array_shift($bits)));
            $q    = 1.0;
            foreach ($bits as $b) {
                $b = trim($b);
                if (str_starts_with($b, 'q=')) $q = (float) substr($b, 2);
            }
            $items[] = ['type' => $type, 'q' => $q];
        }
        usort($items, fn($a, $b) => $b['q'] <=> $a['q']);
        return $items;
    }

    /** True when the client wants JSON (XHR, `Accept: application/json`, or `*+json`). */
    public static function wantsJson(): bool
    {
        $xrw = self::header_in('X-Requested-With') ?? '';
        if (strcasecmp($xrw, 'XMLHttpRequest') === 0) return true;
        foreach (self::accept() as $a) {
            if ($a['type'] === 'application/json') return true;
            if (str_ends_with($a['type'], '+json')) return true;
        }
        return false;
    }

    // -------- Time helpers --------

    public static function now(): int { return time(); }

    public static function iso8601(int|string|null $time = null): string
    {
        $ts = $time === null ? time() : (is_int($time) ? $time : (int) strtotime($time));
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    // -------- Identifier helpers --------

    /** RFC 4122 v4 UUID (random). */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant 10
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-'
             . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** ULID — 26-char Crockford base32, sortable by time (ms precision). */
    public static function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $timeMs   = (int) floor(microtime(true) * 1000);

        $time = '';
        for ($i = 9; $i >= 0; $i--) {
            $time = $alphabet[$timeMs & 0x1f] . $time;
            $timeMs >>= 5;
        }

        $rand = random_bytes(10);
        $bits = '';
        for ($i = 0; $i < 10; $i++) $bits .= str_pad(decbin(ord($rand[$i])), 8, '0', STR_PAD_LEFT);
        $randPart = '';
        for ($i = 0; $i < 16; $i++) {
            $chunk = substr($bits, $i * 5, 5);
            $randPart .= $alphabet[bindec($chunk)];
        }
        return $time . $randPart;
    }

    // -------- Base64-URL helpers --------

    public static function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $s): ?string
    {
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $r = base64_decode(strtr($s, '-_', '+/'), true);
        return $r === false ? null : $r;
    }

    // -------- String helpers --------

    /** ASCII slugify (UTF-8 aware via mbstring; falls back to ASCII transliteration). */
    public static function slug(string $text, string $sep = '-'): string
    {
        // Drop combining marks where iconv is available.
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($tr !== false) $text = $tr;
        }
        $text = strtolower($text);
        $text = preg_replace('~[^a-z0-9]+~', $sep, $text) ?? '';
        return trim($text, $sep);
    }

    /** UTF-8-safe truncate with optional ellipsis. */
    public static function truncate(string $s, int $max, string $end = '…'): string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
        if ($len <= $max) return $s;
        $cut = max($max - (function_exists('mb_strlen') ? mb_strlen($end, 'UTF-8') : strlen($end)), 0);
        $head = function_exists('mb_substr') ? mb_substr($s, 0, $cut, 'UTF-8') : substr($s, 0, $cut);
        return $head . $end;
    }

    // -------- Array helpers --------

    /** Dot-notation access: `Util::dot($cfg, 'app.db.host', 'localhost')`. */
    public static function dot(array $array, string $path, mixed $default = null): mixed
    {
        if ($path === '') return $array;
        $cur = $array;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return $default;
            }
        }
        return $cur;
    }

    /** Dot-notation set (in place). */
    public static function dotSet(array &$array, string $path, mixed $value): void
    {
        if ($path === '') return;
        $segs = explode('.', $path);
        $ref  = &$array;
        $last = array_pop($segs);
        foreach ($segs as $seg) {
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) $ref[$seg] = [];
            $ref = &$ref[$seg];
        }
        $ref[$last] = $value;
    }

    /** @param iterable<int|string,array<string,mixed>> $rows */
    public static function pluck(iterable $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists($key, $row)) $out[] = $row[$key];
        }
        return $out;
    }

    public static function indexBy(iterable $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists($key, $row)) $out[(string) $row[$key]] = $row;
        }
        return $out;
    }

    public static function groupBy(iterable $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists($key, $row)) {
                $out[(string) $row[$key]][] = $row;
            }
        }
        return $out;
    }

    /** Recursive merge (right wins on scalar conflict; arrays are merged). */
    public static function deepMerge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::deepMerge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    /**
     * Pagination metadata.
     * @return array{page:int,pages:int,per_page:int,total:int,from:int,to:int,offset:int,has_prev:bool,has_next:bool}
     */
    public static function pagination(int $total, int $perPage, int $page): array
    {
        $total   = max(0, $total);
        $perPage = max(1, $perPage);
        $pages   = (int) max(1, ceil($total / $perPage));
        $page    = (int) min($pages, max(1, $page));
        $offset  = ($page - 1) * $perPage;
        $from    = $total === 0 ? 0 : $offset + 1;
        $to      = min($offset + $perPage, $total);
        return [
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'total'    => $total,
            'from'     => $from,
            'to'       => $to,
            'offset'   => $offset,
            'has_prev' => $page > 1,
            'has_next' => $page < $pages,
        ];
    }

    // -------- Misc --------

    public static function statusText(int $code): string
    {
        return match ($code) {
            200 => 'OK', 201 => 'Created', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
            413 => 'Payload Too Large', 414 => 'URI Too Long',
            422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 502 => 'Bad Gateway',
            503 => 'Service Unavailable', 504 => 'Gateway Timeout',
            default => 'HTTP ' . $code,
        };
    }
}



// Short alias so route files can call R::json($data) etc.
if (!class_exists('R', false)) {
    class_alias(Util::class, 'R');
}



// =====================================================================
// SECTION 16 — ANALYTICS ENGINE
// =====================================================================

final class AnalyticsEngine
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        
        // Ensure the database is initialized
        Db::init();

        // 1. Idempotent Schema Creation
        Db::exec('
            CREATE TABLE IF NOT EXISTS _router_analytics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                method TEXT NOT NULL,
                path TEXT NOT NULL,
                status INTEGER NOT NULL,
                ms REAL NOT NULL,
                ip TEXT NOT NULL,
                ua TEXT NOT NULL
            )
        ');

        // Create indexes for fast querying on the JSON dashboard
        Db::exec('CREATE INDEX IF NOT EXISTS idx_analytics_ts ON _router_analytics(ts)');
        Db::exec('CREATE INDEX IF NOT EXISTS idx_analytics_path ON _router_analytics(path)');

        // 2. Register the shutdown hook to record the request asynchronously
        register_shutdown_function([self::class, 'record']);
        
        self::$booted = true;
    }

    public static function record(): void
    {
        $path = Router::parseUri();
        
        // Skip logging the analytics endpoint itself, static files, or cache clears to prevent noise
        if (str_starts_with($path, '/_analytics') || str_starts_with($path, '/_cc')) return;

        $status = http_response_code() ?: 200;
        
        // Fallback to microtime if Profiler wasn't started (e.g., in production mode)
        $ms = class_exists('Profiler') && Profiler::elapsedMs() > 0 
            ? Profiler::elapsedMs() 
            : (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;

        try {
            Db::insert('_router_analytics', [
                'ts'     => time(),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'path'   => $path,
                'status' => $status,
                'ms'     => round($ms, 2),
                'ip'     => SecurityLayer::clientIp(),
                'ua'     => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255)
            ]);
        } catch (Throwable $e) {
            // Silently swallow DB locks/errors on shutdown so we don't crash the teardown
            CacheEngine::logError('Analytics insertion failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Generate aggregated JSON statistics for a given time window.
     */
    public static function generateReport(int $days = 7): array
    {
        $since = time() - ($days * 86400);

        // Global Totals
        $totals = Db::selectOne('
            SELECT 
                COUNT(*) as total_requests,
                COUNT(DISTINCT ip) as unique_visitors,
                ROUND(AVG(ms), 2) as avg_response_ms,
                MAX(ms) as max_response_ms
            FROM _router_analytics 
            WHERE ts >= ?
        ', [$since]);

        // Top 10 Paths
        $topPaths = Db::select('
            SELECT path, COUNT(*) as hits, ROUND(AVG(ms), 2) as avg_ms 
            FROM _router_analytics 
            WHERE ts >= ? 
            GROUP BY path 
            ORDER BY hits DESC 
            LIMIT 10
        ', [$since]);

        // Status Code Distribution
        $statusCodes = Db::select('
            SELECT status, COUNT(*) as count 
            FROM _router_analytics 
            WHERE ts >= ? 
            GROUP BY status 
            ORDER BY count DESC
        ', [$since]);

        // Daily Traffic Trend (Grouping by day)
        $daily = Db::select("
            SELECT 
                date(ts, 'unixepoch', 'localtime') as day, 
                COUNT(*) as hits, 
                COUNT(DISTINCT ip) as uniques 
            FROM _router_analytics 
            WHERE ts >= ? 
            GROUP BY day 
            ORDER BY day ASC
        ", [$since]);

        return [
            'window' => "Last {$days} days",
            'generated_at' => date('c'),
            'summary' => [
                'requests' => (int) ($totals['total_requests'] ?? 0),
                'uniques'  => (int) ($totals['unique_visitors'] ?? 0),
                'avg_latency_ms' => (float) ($totals['avg_response_ms'] ?? 0),
                'max_latency_ms' => (float) ($totals['max_response_ms'] ?? 0),
            ],
            'status_codes' => $statusCodes,
            'top_paths' => $topPaths,
            'daily_trend' => $daily
        ];
    }
}


// =====================================================================
// SECTION 17 — TELEGRAM BOT INTEGRATION
// =====================================================================

final class TelegramNotifier
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        
        // Register shutdown function so the API call happens AFTER the user gets their page
        register_shutdown_function([self::class, 'notify']);
        self::$booted = true;
    }

    public static function notify(): void
    {
        $token  = Env::get('TELEGRAM_BOT_TOKEN', '');
        $chatId = Env::get('TELEGRAM_CHAT_ID', '');

        if ($token === '' || $chatId === '') return;

        $path = Router::parseUri();
        
        // 1. FILTER NOISE: Ignore static files, internal router paths, and common bots
        if (str_starts_with($path, '/_') || $path === '/favicon.ico') return;
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        if (preg_match('/bot|crawl|spider|slurp|mediapartners|lighthouse/i', $ua)) return;

        // 2. RATE LIMIT: Prevent Telegram API bans and phone spam
        $ip = SecurityLayer::clientIp();
        $cacheKey = "tg_notify_" . md5($ip);
        
        // If we've already notified about this IP in the last 30 minutes (1800 seconds), skip.
        if (CacheEngine::get($cacheKey)) return;
        
        // Mark this IP as seen for the next 30 minutes
        CacheEngine::set($cacheKey, true, 1800);

        // 3. BUILD MESSAGE
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $domain = DomainResolver::currentHost();
        $safeUa = substr($ua, 0, 100) . (strlen($ua) > 100 ? '...' : '');
        
        $text = "🌐 *New Visitor on {$domain}*\n";
        $text .= "📍 *Path:* `{$method} {$path}`\n";
        $text .= "🖥 *IP:* `{$ip}`\n";
        $text .= "📱 *Device:* `{$safeUa}`";

        // 4. FIRE AND FORGET: Execute with a strict 1.5-second timeout
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = http_build_query([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown'
        ]);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $data,
                'timeout' => 1.5 // Abort if Telegram takes longer than 1.5s
            ]
        ]);

        // Suppress errors with @ so a network failure doesn't write to your error logs
        @file_get_contents($url, false, $context);
    }
}

//class 18: Crypto Engine
final class CryptoEngine
{
    // A standard BIP39 wordlist subset for seed generation
    private const WORDLIST = ['apple','brave','crane','delta','eagle','flame','ghost','heart','index','jolly','karma','lemon','mango','noble','ocean','pixel','quest','raven','solar','tiger','unity','vocal','wheat','xenon','yacht','zebra'];

    public static function generateRecoverySeeds(): array
    {
        $seeds = [];
        for ($i = 0; $i < 12; $i++) {
            $seeds[] = self::WORDLIST[random_int(0, count(self::WORDLIST) - 1)];
        }
        return $seeds;
    }

    public static function hashSeeds(array $seeds): string
    {
        // Hash the sorted, concatenated seeds so order doesn't matter during recovery
        sort($seeds);
        return hash('sha256', implode('|', $seeds));
    }

    public static function issueJwt(array $payload, int $ttl = 86400): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['exp'] = time() + $ttl;
        $payload['iat'] = time();
        $payloadJson = json_encode($payload);

        $base64UrlHeader = R::base64url($header);
        $base64UrlPayload = R::base64url($payloadJson);
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, APP_SECRET, true);
        $base64UrlSignature = R::base64url($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function verifyJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;
        $validSignature = R::base64url(hash_hmac('sha256', $header . "." . $payload, APP_SECRET, true));

        if (!hash_equals($validSignature, $signature)) return null;

        $decodedPayload = json_decode(R::base64urlDecode($payload), true);
        if (!$decodedPayload || !isset($decodedPayload['exp']) || $decodedPayload['exp'] < time()) {
            return null; // Expired or invalid
        }

        return $decodedPayload;
    }
}

// =====================================================================
// END OF Router.php
// Including this file has zero side effects.
// Call Router::init(); Router::dispatch(); from your front controller.
// =====================================================================
