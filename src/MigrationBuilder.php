<?php

namespace Netsells\MigrationGenerator;

use Carbon\Carbon;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard;

/**
 * Takes an array of columns with their name, type and options
 * and generates PHP code for a Laravel migration
 *
 * Class MigrationBuilder
 * @package App\Migration
 */
class MigrationBuilder
{
    /**
     * This represents the $table variable in the AST for the migration
     */
    protected $tableVariable;
    private $columns;
    private $migrationName;
    /**
     * is this migration creating a table or modifying?
     */
    private $isCreating;
    private $tableName;

    public function __construct(array $columns, $migrationName, $tableName, $isCreating = true)
    {
        $this->columns = $columns;
        $this->migrationName = $migrationName;
        $this->tableName = $tableName;
        $this->isCreating = $isCreating;
    }

    public function generate()
    {
        $this->tableVariable = new Variable('table');
        return (new Standard(['shortArraySyntax' => true]))->prettyPrintFile(
            $this->migrationStatements()
        );
    }

    /**
     * generates the Laravel convention file name
     */
    public function fileName()
    {
        $timestamp = Carbon::now()->format('Y_m_d_his');
        return $timestamp . '_' . snake_case($this->migrationName) . '.php';
    }

    private function foreignKeyStatement(array $column)
    {
        $foreignKey = $column['foreign_key'];
        $foreign = new MethodCall($this->tableVariable, 'foreign', [new String_($column['name'])]);
        $references = new MethodCall($foreign, 'references', [new String_($foreignKey['column_name'] ?? 'id')]);
        $foreignTable = new MethodCall($references, 'on', [new String_($foreignKey['references'])]);
        $onUpdate = new MethodCall($foreignTable, 'onUpdate', [new String_($foreignKey['on_update'])]);
        $onDelete = new MethodCall($onUpdate, 'onDelete', [new String_($foreignKey['on_delete'])]);
        return $onDelete;
    }

    private function migrationStatements()
    {
        $tableName = $this->tableName;
        $className = studly_case($this->migrationName);
        $factory = new BuilderFactory;
        $class = $factory->class($className)->extend('Migration')
            ->addStmt($factory->method('up')->makePublic()
                ->addStmt($this->upStatement($tableName)))
            ->addStmt($factory->method('down')->makePublic()
                ->addStmt($this->downStatement($tableName)));
        $builders = [
            $factory->use('Illuminate\Support\Facades\Schema'),
            $factory->use('Illuminate\Database\Schema\Blueprint'),
            $factory->use('Illuminate\Database\Migrations\Migration'),
            $class
        ];
        return collect($builders)->map(function ($builder) {
            return $builder->getNode();
        })->all();
    }

    private function schemaStatement(array $statements, $tableName)
    {
        // name of the method to call off the Schema facade
        $schemaMethodName = $this->isCreating ? 'create' : 'table';
        $closure = new Closure([
            'params' => [new Param('table', null, new Name('Blueprint'))],
            'stmts' => $statements
        ]);
        return new StaticCall(new Name('Schema'), $schemaMethodName, [new String_($tableName), $closure]);
    }

    /**
     * Statements which are inside the Schema closure argument inside the up method
     * @return array
     */
    private function upStatements()
    {
        foreach ($this->columns as $column) {
            $columnStatement = $this->methodCallForColumn($column);

            if ($column['nullable']) {
                // for chained method calls the expression is the input / variable to the next method call
                $columnStatement = new MethodCall($columnStatement, 'nullable');
            }

            if (array_key_exists('default', $column) && $column['default']) {
                // for chained method calls the expression is the input / variable to the next method call
                $columnStatement = new MethodCall($columnStatement, 'default', [new String_($column['default'])]);
            }

            if (array_key_exists('unsigned', $column) && $column['unsigned']) {
                $columnStatement = new MethodCall($columnStatement, 'unsigned');
            }

            if (array_key_exists('is_foreign_key', $column) && $column['is_foreign_key']) {
                $columnStatements[] = $columnStatement;
                $columnStatements[] = $this->foreignKeyStatement($column);
                continue;
            }

            $columnStatements[] = $columnStatement;
        }
        if ($this->isCreating) {
            $columnStatements[] = $this->timestampColumnsStatement();
        }
        return $columnStatements;
    }

    private function downStatements()
    {
        $tableNames = collect($this->columns)->map(function ($column) {
            return new String_($column['name']);
        })->all();
        $dropColumnStmt = new MethodCall($this->tableVariable, 'dropColumn', [new Array_($tableNames)]);
        return [$dropColumnStmt];
    }

    private function timestampColumnsStatement()
    {
        return new MethodCall($this->tableVariable, 'timestamps');
    }

    /*
     * Will return the body of the up() method
     */
    private function upStatement($tableName): StaticCall
    {
        return $this->schemaStatement($this->upStatements(), $tableName);
    }

    /*
     * Will return the body for the down() method
     */
    private function downStatement($tableName): StaticCall
    {
        if ($this->isCreating) {
            return new StaticCall(new Name('Schema'), 'dropIfExists', [new String_($tableName)]);
        }
        return $this->schemaStatement($this->downStatements(), $tableName);
    }

    private function methodCallForColumn($column): MethodCall
    {
        $parameters = [new String_($column['name'])];

        if ($column['type'] === 'enum') {
            $allowedValueStrings = [];
            if (array_key_exists('allowed_values', $column)) {
                $allowedValueStrings = collect($column['allowed_values'])->map(function ($value) {
                    return new String_($value);
                })->all();
            }
            $allowedValues = new Array_($allowedValueStrings);
            $parameters[] = $allowedValues;
        }

        return new MethodCall($this->tableVariable, $column['type'], $parameters);
    }
}
