<?php

declare(strict_types=1);

use App\Auth\DatabaseUserProvider;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Seeder;

return new class implements Seeder {
    public function run(Connection $database): void
    {
        $users = new DatabaseUserProvider($database, new PasswordHasher());

        if ($users->findByEmail('demo@example.test') === null) {
            $users->create('demo@example.test', 'correct-horse-battery', 'Demo User');
        }
    }
};
