<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

use PhpOrbit\Database\Model;

/**
 * A minimal {@see Model} fixture, backed by a `widgets` table:
 * `CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL, quantity INTEGER NOT NULL)`.
 */
final class Widget extends Model
{
    public string $name = '';

    public int $quantity = 0;

    protected static function table(): string
    {
        return 'widgets';
    }

    protected static function fromRow(array $row): static
    {
        $model = new static();
        $model->name = (string) ($row['name'] ?? '');
        $model->quantity = (int) ($row['quantity'] ?? 0);

        return $model;
    }

    public function toRow(): array
    {
        return [
            'name' => $this->name,
            'quantity' => $this->quantity,
        ];
    }
}
