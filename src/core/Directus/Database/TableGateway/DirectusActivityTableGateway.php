<?php

namespace Directus\Database\TableGateway;

use Directus\Database\Query\Builder;
use Directus\Database\SchemaService;
use Directus\Permissions\Acl;
use Directus\Util\ArrayUtils;
use Directus\Util\DateTimeUtils;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Insert;
use Zend\Db\Sql\Predicate\In;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Where;

class DirectusActivityTableGateway extends RelationalTableGateway
{
    // Populates directus_activity.type
    const TYPE_ENTRY    = 'ENTRY';
    const TYPE_FILES    = 'FILES';
    const TYPE_SETTINGS = 'SETTINGS';
    const TYPE_LOGIN    = 'LOGIN';
    const TYPE_COMMENT  = 'COMMENT';

    // Populates directus_activity.action
    const ACTION_ADD    = 'ADD';
    const ACTION_UPDATE = 'UPDATE';
    const ACTION_DELETE = 'DELETE';
    const ACTION_LOGIN  = 'LOGIN';
    const ACTION_SOFT_DELETE = 'SOFT_DELETE';
    const ACTION_REVERT = 'REVERT';

    public static $_tableName = 'directus_activity';

    public $primaryKeyFieldName = 'id';

    public static function makeLogTypeFromTableName($table)
    {
        switch ($table) {
            // @todo these first two are assumptions. are they correct?
            case 'directus_settings':
                return self::TYPE_SETTINGS;
            case 'directus_files':
                return self::TYPE_FILES;
            default:
                return self::TYPE_ENTRY;
        }
    }

    /**
     * DirectusActivityTableGateway constructor.
     *
     * @param AdapterInterface $adapter
     * @param Acl $acl
     */
    public function __construct(AdapterInterface $adapter, $acl = null)
    {
        parent::__construct(self::$_tableName, $adapter, $acl);
    }

    public function fetchFeed($params = [])
    {
        $params['order'] = ['id' => 'DESC'];
        $params = $this->applyDefaultEntriesSelectParams($params);
        $builder = new Builder($this->getAdapter());
        $builder->from($this->getTable());

        // TODO: Move this to applyDefaultEntriesSelectParams method
        $tableSchema = $this->getTableSchema();
        $columns = SchemaService::getAllCollectionFieldsName($tableSchema->getName());
        if (ArrayUtils::has($params, 'columns')) {
            $columns = ArrayUtils::get($params, 'columns');
        }

        $builder->columns($columns);
        $hasActiveColumn = $tableSchema->hasStatusColumn();

        $builder = $this->applyParamsToTableEntriesSelect($params, $builder, $tableSchema, $hasActiveColumn);
        $select = $builder->buildSelect();

        $select
            ->where
            ->nest
            ->isNull('parent_id')
            ->OR
            ->equalTo('type', 'FILES')
            ->unnest;

        $rowset = $this->selectWith($select);
        $rowset = $rowset->toArray();

        $countTotalWhere = new Where;
        $countTotalWhere
            ->isNull('parent_id')
            ->OR
            ->equalTo('type', 'FILES');

        return $this->wrapData($this->parseRecord($rowset), false, ArrayUtils::get($params, 'meta', 0));
    }

    public function recordLogin($userId)
    {
        $logData = [
            'type' => self::TYPE_LOGIN,
            'collection' => 'directus_users',
            'action' => self::ACTION_LOGIN,
            'user' => $userId,
            'item' => $userId,
            'datetime' => DateTimeUtils::nowInUTC()->toString(),
            'ip' => \Directus\get_request_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
        ];

        $insert = new Insert($this->getTable());
        $insert
            ->values($logData);

        $this->insertWith($insert);
    }

    /**
     * Get the last update date from a list of row ids in the given table
     *
     * @param string $table
     * @param mixed $ids
     * @param array $params
     *
     * @return array|null
     */
    public function getLastUpdated($table, $ids, array $params = [])
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $sql = new Sql($this->adapter);
        $select = $sql->select($this->getTable());

        $select->columns([
            'row_id',
            'user',
            'datetime' => new Expression('MAX(datetime)')
        ]);

        $select->where([
            'table_name' => $table,
            'type' => 'ENTRY',
            new In('action', ['UPDATE', 'ADD']),
            new In('row_id', $ids)
        ]);

        $select->group(['row_id', 'user']);
        $select->order(['datetime' => 'DESC']);

        $statement = $this->sql->prepareStatementForSqlObject($select);
        $result = iterator_to_array($statement->execute());

        return $this->wrapData($this->parseRecord($result), false, ArrayUtils::get($params, 'meta', 0));
    }

    public function getMetadata($table, $id)
    {
        $sql = new Sql($this->adapter);
        $select = $sql->select($this->getTable());

        $select->columns([
            'action',
            'user',
            'datetime' => new Expression('MAX(datetime)')
        ]);

        $on = 'directus_users.id = directus_activity.user';
        $select->join('directus_users', $on, []);

        $select->where([
            'table_name' => $table,
            'row_id' => $id,
            'type' => $table === 'directus_files' ? static::TYPE_FILES : static::TYPE_ENTRY,
            new In('action', ['ADD', 'UPDATE'])
        ]);

        $select->group([
            'action',
            'user'
        ]);

        $select->limit(2);

        $statement = $this->sql->prepareStatementForSqlObject($select);
        $result = iterator_to_array($statement->execute());
        $result = $this->parseRecord($result);

        $data = [
            'created_on' => null,
            'created_by' => null,
            'updated_on' => null,
            'updated_by' => null
        ];

        foreach ($result as $row) {
            switch (ArrayUtils::get($row, 'action')) {
                case static::ACTION_ADD:
                    $data['created_by'] = $row['user'];
                    $data['created_on'] = $row['datetime'];
                    break;
                case static::ACTION_UPDATE:
                    $data['updated_by'] = $row['user'];
                    $data['updated_on'] = $row['datetime'];
                    break;
            }
        }

        if (!$data['updated_by'] && !$data['updated_on']) {
            $data['updated_on'] = $data['created_on'];
            $data['updated_by'] = $data['created_by'];
        }

        return $data;
    }
}
