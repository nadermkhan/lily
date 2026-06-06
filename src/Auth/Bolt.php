<?php

namespace Lily\Auth;

use Lily\Database\Db;
use Lily\Foundation\Application;

class Bolt
{
    private Db $db;
    private string $cacheFile;

    public function __construct()
    {
        $app = Application::getInstance();
        $basePath = $app ? $app->getBasePath() : dirname(__DIR__, 3);
        
        $dbPath = $basePath . '/database/database.sqlite';
        $this->db = new Db(['dsn' => 'sqlite:' . $dbPath]);
        
        $this->cacheFile = $basePath . '/storage/auth/tokens.php';
    }

    private function ensureTableExists(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS personal_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL
            )
        ");
    }

    public function issueToken(int|string $userId, string $name = 'default'): string
    {
        $this->ensureTableExists();
        $plainTextToken = 'lily_' . bin2hex(random_bytes(20));
        $hash = hash('sha256', $plainTextToken);

        $stmt = $this->db->getPdo()->prepare("
            INSERT INTO personal_access_tokens (user_id, name, token, created_at)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $name,
            $hash,
            date('Y-m-d H:i:s')
        ]);

        $this->compile();

        return $plainTextToken;
    }

    public function revokeToken(int|string $userId, string $name = 'default'): void
    {
        $this->ensureTableExists();
        $stmt = $this->db->getPdo()->prepare("
            DELETE FROM personal_access_tokens 
            WHERE user_id = ? AND name = ?
        ");
        
        $stmt->execute([$userId, $name]);

        $this->compile();
    }

    public function compile(): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $this->ensureTableExists();

        $stmt = $this->db->query("SELECT token, user_id FROM personal_access_tokens");
        $tokens = [];
        
        while ($row = $stmt->fetch()) {
            $tokens[$row['token']] = $row['user_id'];
        }

        $export = var_export($tokens, true);
        $php = "<?php\n\n// Lily Bolt Compiled Tokens Cache\n// Do not edit manually!\n\nreturn $export;\n";
        
        file_put_contents($this->cacheFile, $php);
        
        // Invalidate opcache if enabled
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->cacheFile, true);
        }
    }
}
