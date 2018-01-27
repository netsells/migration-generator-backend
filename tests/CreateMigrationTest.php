<?php

namespace Netsells\MigrationGenerator\Tests;

use Netsells\MigrationGenerator\MigrationBuilder;
use PHPUnit\Framework\TestCase;

class CreateMigrationTest extends TestCase
{
    // quick regression test, as was always generating Schema::table
    public function testCreateMigrationSchema()
    {
        $columns = [
            [
                'name' => 'first_name',
                'type' => 'string',
                'nullable' => false
            ]
        ];

        $builder = new MigrationBuilder($columns, 'create_users_table', 'users');
        $migration = $builder->generate();

        $this->assertContains('Schema::create(\'users\',', $migration);

        // correct argument in closure
        $this->assertContains('Blueprint $table', $migration);
    }

    public function testModifyMigrationSchema()
    {
        $columns = [
            [
                'name' => 'first_name',
                'type' => 'string',
                'nullable' => false
            ]
        ];

        $builder = new MigrationBuilder($columns, 'create_users_table', 'users', false);
        $migration = $builder->generate();

        $this->assertContains('Schema::table(\'users\',', $migration);
    }
}
