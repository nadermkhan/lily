<?php

namespace Lily\Database;

class QueryBuilder
{
    private Db $db;
    private string $table = '';
    private array $where = [];
    private array $bindings = [];

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->where[] = "{$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function get(): array
    {
        $sql = "SELECT * FROM {$this->table}";

        if (!empty($this->where)) {
            $sql .= " WHERE " . implode(" AND ", $this->where);
        }

        $stmt = $this->db->query($sql, $this->bindings);
        return $stmt->fetchAll();
    }
}
