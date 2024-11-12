<?php

namespace San\Crud\Utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SchemaUtils {
    public static function getTables(string|array $exclude) {
        if(method_exists(DB::connection(),'getDoctrineSchemaManager')){
            $tables = DB::connection()->getDoctrineSchemaManager()->listTableNames();
        } else {
            $tables = Schema::getTableListing();
        }
        return array_values(array_filter($tables, fn($table) => !in_array($table, (array) $exclude)));

    }

    public static function getTableFields(string $tableName, array $excludedColumns = [], array $alwaysIgnoredColumns = ['id', 'created_at', 'updated_at', 'deleted_at']) {
        $ignoredColumns = array_merge((array) $excludedColumns, (array) $alwaysIgnoredColumns);        
        // ugly enum hack as doctrine does not support enum types
        // https://www.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/mysql-enums.html#solution-1-mapping-to-varchars
        if(method_exists(DB::connection(),'getDoctrineSchemaManager')){
            DB::connection()->getDoctrineSchemaManager()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'guid');

            $indexes = DB::getDoctrineSchemaManager()->listTableIndexes($tableName);
            $uniqueColumns = [];
            foreach ($indexes as $index) {
                if ($index->isUnique() && count($index->getColumns()) === 1) {
                    $uniqueColumns = array_merge($uniqueColumns, $index->getColumns());
                }
            }    
            $columns = DB::getDoctrineSchemaManager()->listTableColumns($tableName);
            foreach ($columns as $column) {
                if (in_array($column->getName(), $ignoredColumns)) continue;
    
                $field = ['id' => $column->getName(), 'type' => $column->getType()->getName(), 'name' => Str::title(str_replace('_', ' ', $column->getName())), 'nullable' => !$column->getNotnull()];
    
                if ($field['type'] == 'guid') {
                    try {
                        $enums = DB::select("SHOW COLUMNS FROM $tableName WHERE Field = '$field[name]'");
                        $field['values'] = explode(',', str_replace("'", '', substr($enums[0]->Type, 5, -1)));
                    } catch (\Throwable $e) {
                    }
                }
    
                if (preg_match('/^(.*?)_id$/', $field['id'], $matches)) {
                    $relatedTable = Str::plural($matches[1]);
                    if (self::tableExists($relatedTable)) {
                        $field['relation'] = $matches[1];
                        $field['related_table'] = $relatedTable;
                    }
                }
    
                //check if column is unique index
                if (in_array($field['id'], $uniqueColumns)) {
                    $field['unique'] = TRUE;
                }
                $fields[] = $field;
            }
    
        }
        else{ // Laravel 11 upgrade changes: https://laravel.com/docs/11.x/upgrade#doctrine-dbal-removal
            $columns = Schema::getColumns($tableName);
            $indexes = Schema::getIndexes($tableName);
            $uniqueColumns = [];
            foreach ($indexes as $index) {
                if ($index['unique'] && count($index['columns']) === 1) {
                    $uniqueColumns = array_merge($uniqueColumns, $index['columns']);
                }
            }

            foreach ($columns as $column) {
                if (in_array($column['name'], $ignoredColumns)) continue;
    
                $field = ['id' => $column['name'], 'type' => $column['type_name'], 'name' => Str::title(str_replace('_', ' ', $column['name'])), 'nullable' => !$column['nullable']];
    
                if ($field['type'] == 'guid') {
                    try {
                        $enums = DB::select("SHOW COLUMNS FROM $tableName WHERE Field = '$field[name]'");
                        $field['values'] = explode(',', str_replace("'", '', substr($enums[0]->Type, 5, -1)));
                    } catch (\Throwable $e) {
                    }
                }
    
                if (preg_match('/^(.*?)_id$/', $field['id'], $matches)) {
                    $relatedTable = Str::plural($matches[1]);//@test Portuguese conversions
                    if (self::tableExists($relatedTable)) {
                        $field['relation'] = $matches[1];
                        $field['related_table'] = $relatedTable;
                    }
                }
    
                //check if column is unique index
                if (in_array($field['id'], $uniqueColumns)) {
                    $field['unique'] = TRUE;
                }
    
                $fields[] = $field;
            }
            
        }

        return $fields ?? [];
    }

    public static function firstHumanReadableField(string $table, string $key = NULL) {
        $all = self::getTableFields($table);
        if (empty($all)) return NULL;

        foreach ($all as $f) {
            if (preg_match('/_id$/', $f['id'])) continue;
            if (preg_match('/(string|text)/', $f['type'])) return $key ? $f[$key] : $f;
            $last = $f['id'];
        }

        $result = $last ?? $all[0];
        return $key ? $result[$key] : $result;
    }

    public static function getTableFieldsWithIds(string $table, array $excludedColumns = []) {

        return array_values(array_filter(self::getTableFields($table, $excludedColumns), fn($f) => !empty($f['relation'])));
    }

    public static function getUserIdField(string $tableName, $userIdField = 'user_id') {
        if (!self::hasTable($tableName)) return NULL;
        return \Schema::hasColumn($tableName, $userIdField) ? $userIdField : NULL;
    }

    public static function hasTable(string $tableName) {
        return \Schema::hasTable($tableName);
    }

    public static function hasTimestamps(string $tableName) {
        return \Schema::hasColumn($tableName, 'created_at') && \Schema::hasColumn($tableName, 'updated_at');
    }

    public static function hasSoftDelete(string $tableName) {
        return \Schema::hasColumn($tableName, 'deleted_at');
    }

    public static function tableExists(string $tableName) {
        return DB::connection()->getSchemaBuilder()->hasTable($tableName);
    }
}
