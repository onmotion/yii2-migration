<?php declare(strict_types=1);

namespace bizley\migration\table;

use yii\base\BaseObject;
use yii\base\InvalidArgumentException;

/**
 * Class TableStructure
 * @package bizley\migration\table
 *
 * @property string $schema
 */
class TableStructure extends BaseObject
{
    public const SCHEMA_MSSQL = 'mssql';
    public const SCHEMA_OCI = 'oci';
    public const SCHEMA_PGSQL = 'pgsql';
    public const SCHEMA_SQLITE = 'sqlite';
    public const SCHEMA_CUBRID = 'cubrid';
    public const SCHEMA_MYSQL = 'mysql';
    public const SCHEMA_UNSUPPORTED = 'unsupported';

    /**
     * @var string
     */
    public $name;
    /**
     * @var TablePrimaryKey
     */
    public $primaryKey;
    /**
     * @var TableColumn[]
     */
    public $columns = [];
    /**
     * @var TableIndex[]
     */
    public $indexes = [];
    /**
     * @var TableForeignKey[]
     */
    public $foreignKeys = [];
    /**
     * @var bool
     */
    public $generalSchema = true;
    /**
     * @var bool
     */
    public $usePrefix = true;
    /**
     * @var string
     */
    public $dbPrefix;

    protected $_schema;

    /**
     * Returns schema type.
     * @return string
     */
    public function getSchema(): string
    {
        return $this->_schema;
    }

    /**
     * Sets schema type based on the currently used schema class.
     * @param string|null $schemaClass
     */
    public function setSchema(?string $schemaClass): void
    {
        switch ($schemaClass) {
            case 'yii\db\mssql\Schema':
                $this->_schema = self::SCHEMA_MSSQL;
                break;
            case 'yii\db\oci\Schema':
                $this->_schema = self::SCHEMA_OCI;
                break;
            case 'yii\db\pgsql\Schema':
                $this->_schema = self::SCHEMA_PGSQL;
                break;
            case 'yii\db\sqlite\Schema':
                $this->_schema = self::SCHEMA_SQLITE;
                break;
            case 'yii\db\cubrid\Schema':
                $this->_schema = self::SCHEMA_CUBRID;
                break;
            case 'yii\db\mysql\Schema':
                $this->_schema = self::SCHEMA_MYSQL;
                break;
            default:
                $this->_schema = self::SCHEMA_UNSUPPORTED;
        }
    }

    /**
     * Renders table name.
     * @return string
     */
    public function renderName(): string
    {
        $tableName = $this->name;
        if (!$this->usePrefix) {
            return $tableName;
        }
        if ($this->dbPrefix && strpos($this->name, $this->dbPrefix) === 0) {
            $tableName = substr($this->name, mb_strlen($this->dbPrefix, 'UTF-8'));
        }
        return '{{%' . $tableName . '}}';
    }

    /**
     * Renders the migration structure.
     * @return string
     */
    public function render(): string
    {
        return $this->renderTable() . $this->renderPk() . $this->renderIndexes() . $this->renderForeignKeys() . "\n";
    }

    /**
     * Renders the table.
     * @return string
     */
    public function renderTable(): string
    {
        $output = '';

        $tableOptionsSet = false;
        if ($this->generalSchema || $this->schema === self::SCHEMA_MYSQL) {
            $output .= <<<'PHP'
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }


PHP;
            $tableOptionsSet = true;
        }
        $output .= "        \$this->createTable('" . $this->renderName() . "', [";
        foreach ($this->columns as $column) {
            $output .= "\n" . $column->render($this);
        }
        $output .= "\n        ]" . ($tableOptionsSet ? ', $tableOptions' : '') . ");\n";

        return $output;
    }

    /**
     * Renders the primary key.
     * @return string
     */
    public function renderPk(): string
    {
        $output = '';
        if ($this->primaryKey->isComposite()) {
            $output .= "\n" . $this->primaryKey->render($this);
        }
        return $output;
    }

    /**
     * Renders the indexes.
     * @return string
     */
    public function renderIndexes(): string
    {
        $output = '';
        if ($this->indexes) {
            foreach ($this->indexes as $index) {
                foreach ($this->foreignKeys as $foreignKey) {
                    if ($foreignKey->name === $index->name) {
                        continue 2;
                    }
                }
                $output .= "\n" . $index->render($this);
            }
        }
        return $output;
    }

    /**
     * Renders the foreign keys.
     * @return string
     */
    public function renderForeignKeys(): string
    {
        $output = '';
        if ($this->foreignKeys) {
            foreach ($this->foreignKeys as $foreignKey) {
                $output .= "\n" . $foreignKey->render($this);
            }
        }
        return $output;
    }

    /**
     * Builds table structure based on the list of changes from the Updater.
     * @param TableChange[] $changes
     * @throws InvalidArgumentException
     */
    public function applyChanges(array $changes): void
    {
        /* @var $change TableChange */
        foreach ($changes as $change) {
            if (!$change instanceof TableChange) {
                throw new InvalidArgumentException('You must provide array of TableChange objects.');
            }
            switch ($change->method) {
                case 'createTable':
                    /* @var $column TableColumn */
                    foreach ($change->value as $column) {
                        $this->columns[$column->name] = $column;
                        if ($column->isPrimaryKey || $column->isColumnAppendPK($this->schema)) {
                            if ($this->primaryKey === null) {
                                $this->primaryKey = new TablePrimaryKey(['columns' => [$column->name]]);
                            } else {
                                $this->primaryKey->addColumn($column->name);
                            }
                        }
                    }
                    break;
                case 'addColumn':
                    $this->columns[$change->value->name] = $change->value;
                    if ($change->value->isPrimaryKey || $change->value->isColumnAppendPK($this->schema)) {
                        if ($this->primaryKey === null) {
                            $this->primaryKey = new TablePrimaryKey(['columns' => [$change->value->name]]);
                        } else {
                            $this->primaryKey->addColumn($change->value->name);
                        }
                    }
                    break;
                case 'dropColumn':
                    unset($this->columns[$change->value]);
                    break;
                case 'renameColumn':
                    if (isset($this->columns[$change->value['old']])) {
                        $this->columns[$change->value['new']] = $this->columns[$change->value['old']];
                        $this->columns[$change->value['new']]->name = $change->value['new'];
                        unset($this->columns[$change->value['old']]);
                    }
                    break;
                case 'alterColumn':
                    $this->columns[$change->value->name] = $change->value;
                    break;
                case 'addPrimaryKey':
                    $this->primaryKey = $change->value;
                    foreach ($this->primaryKey->columns as $column) {
                        if (isset($this->columns[$column])) {
                            if (empty($this->columns[$column]->append)) {
                                $this->columns[$column]->append = $this->columns[$column]->prepareSchemaAppend($this, true, false);
                            } elseif (!$this->columns[$column]->isColumnAppendPK($this->schema)) {
                                $this->columns[$column]->append .= ' ' . $this->columns[$column]->prepareSchemaAppend($this, true, false);
                            }
                        }
                    }
                    break;
                case 'dropPrimaryKey':
                    if ($this->primaryKey !== null) {
                        foreach ($this->primaryKey->columns as $column) {
                            if (isset($this->columns[$column]) && !empty($this->columns[$column]->append)) {
                                $this->columns[$column]->append = $this->columns[$column]->removePKAppend($this->schema);
                            }
                        }
                    }
                    $this->primaryKey = null;
                    break;
                case 'addForeignKey':
                    $this->foreignKeys[$change->value->name] = $change->value;
                    break;
                case 'dropForeignKey':
                    unset($this->foreignKeys[$change->value]);
                    break;
                case 'createIndex':
                    $this->indexes[$change->value->name] = $change->value;
                    break;
                case 'dropIndex':
                    unset($this->indexes[$change->value]);
                    break;
                case 'addCommentOnColumn':
                    if (isset($this->columns[$change->value->name])) {
                        $this->columns[$change->value->name]->comment = $change->value->comment;
                    }
                    break;
                case 'dropCommentFromColumn':
                    if (isset($this->columns[$change->value])) {
                        $this->columns[$change->value]->comment = null;
                    }
            }
        }
    }
}
