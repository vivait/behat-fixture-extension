<?php
namespace Vivait\FixtureExtension\Logger;

use Doctrine\DBAL\Logging\SQLLogger,
    Doctrine\DBAL\Types\Type,
    Doctrine\DBAL\Platforms\AbstractPlatform;

class FixtureCacheLogger implements SQLLogger
{
    const QUERY_TYPE_SELECT = "SELECT";
    const QUERY_TYPE_UPDATE = "UPDATE";
    const QUERY_TYPE_INSERT = "INSERT";
    const QUERY_TYPE_DELETE = "DELETE";
    const QUERY_TYPE_CREATE = "CREATE";
    const QUERY_TYPE_ALTER  = "ALTER";

    private $dbPlatform;
    private $loggedQueryTypes;

    /**
     * Executed SQL queries.
     *
     * @var array
     */
    public $queries = array();

    public function __construct(AbstractPlatform $dbPlatform, array $loggedQueryTypes = array())
    {
        $this->dbPlatform = $dbPlatform;
        $this->loggedQueryTypes = $loggedQueryTypes;
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, array $params = null, array $types = null)
    {
        $sql = $this->fixDoctrineSql($sql);

        if ($this->isLoggable($sql)) {
            $params = $this->resolveParamTypes($params, $types);

            $this->queries[] = array(
                'sql' => $sql,
                'params' => $params
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery()
    {

    }

    private function isLoggable($sql)
    {
        if (empty($this->loggedQueryTypes)) {
            return true;
        }

        foreach ($this->loggedQueryTypes as $validType) {
            if (strpos($sql, $validType) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $sql
     * @return string
     */
    protected function fixDoctrineSql($sql)
    {
        if ($sql === '"START TRANSACTION"') {
            $sql = 'START TRANSACTION';

            return $sql;
        } else if ($sql === '"SAVEPOINT"') {
            $sql = 'SAVEPOINT';

            return $sql;
        } else if ($sql === '"COMMIT"') {
            $sql = 'COMMIT';

            return $sql;
        }
        {
            return $sql;
        }
    }

    /**
     * @param array $params
     * @param array $types
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function resolveParamTypes(array $params, array $types)
    {
        if (empty($params)) {
            return $params;
        }

        $newParams = [];
        foreach ($params as $key => $param) {
            if (isset($types[$key])) {
                $type = Type::getType($types[$key]);
                $newParams[] = $type->convertToDatabaseValue($param, $this->dbPlatform);
            } else {
                $newParams[] = $param;
            }
        }

        return $newParams;
    }
}
