<?php

namespace Lily\Auth;

use Lily\Database\Db;
use Lily\Foundation\Application;

/**
 * Class Bolt
 *
 * Provides a lightweight authentication and personal access token management system.
 */
class Bolt
{
    /**
     * @var Db The database instance for token storage.
     */
    private Db $db;

    /**
     * @var string The path to the compiled tokens cache file.
     */
    private string $cacheFile;

    /**
     * Bolt constructor.
     *
     * Initializes the database connection and sets the cache file path.
     */
    public function __construct()
    {
        $app = Application::getInstance();
        $basePath = $app ? $app->getBasePath() : dirname(__DIR__, 3);
        
        $dbPath = $basePath . '/database/database.sqlite';
        $this->db = new Db(['dsn' => 'sqlite:' . $dbPath]);
        
        $this->cacheFile = $basePath . '/storage/auth/tokens.php';
    }

    /**
     * Ensures that the personal access tokens table exists in the database.
     *
     * @return void
     */
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

    /**
     * Issues a new personal access token for a given user.
     *
     * @param int|string $userId The user ID to issue the token for.
     * @param string $name An optional name to identify the token.
     * @return string The generated plain text token.
     */
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

    /**
     * Revokes a specific token for a user.
     *
     * @param int|string $userId The user ID whose token should be revoked.
     * @param string $name The name of the token to revoke.
     * @return void
     */
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

    /**
     * Compiles all valid tokens into a highly efficient PHP cache file.
     *
     * @return void
     */
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
