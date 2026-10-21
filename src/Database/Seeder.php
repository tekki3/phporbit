<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

interface Seeder
{
    public function run(Connection $database): void;
}
