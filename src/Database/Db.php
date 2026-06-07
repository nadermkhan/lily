<?php

namespace Lily\Database;

use PDO;
use PDOException;

/**
 * Class Db
 *
 * Represents a database connection and provides basic query capabilities.
 */
class Db
{
    /**
     * @var PDO The underlying PDO instance used for database operations.
     */
    private PDO $pdo;

    /**
     * Db constructor.
     *
     * Initializes the database connection using the provided configuration.
     *
     * @param array $config Configuration array containing 'dsn', 'username', 'password', and 'options'.
     * @throws \RuntimeException If the database connection fails.
     */
    public function __construct(array $config)
    {
        $dsn = $config['dsn'] ?? 'sqlite::memory:';
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $options = $config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Retrieves the underlying PDO instance.
     *
     * @return PDO The active PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Executes a SQL query with optional bound parameters.
     *
     * @param string $sql The SQL statement to execute.
     * @param array $params Optional array of parameters to bind to the SQL statement.
     * @return \PDOStatement|false Returns the PDOStatement on success or false on failure.
     */
    public function query(string $sql, array $params = []): \PDOStatement|false
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
