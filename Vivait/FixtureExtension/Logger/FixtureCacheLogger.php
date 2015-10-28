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
        if ($sql === '"START TRANSACTION"') {
            $sql = 'START TRANSACTION';
        }
        else if ($sql === '"SAVEPOINT"') {
            $sql = 'SAVEPOINT';
        }
        else if ($sql === '"COMMIT"') {
            $sql = 'COMMIT';
        }

        if ($this->isLoggable($sql)) {
//            if (strpos($sql, 'INSERT INTO queue_user3') !== false) {
//                var_dump('Found trouble query');
//                var_dump($params);
//                var_dump($types);
//            }

            if (!empty($params)) {
                $newParams = [];
                foreach ($params as $key => $param) {
                    $type = Type::getType($types[$key]);
                    $newParams[] = $type->convertToDatabaseValue($param, $this->dbPlatform);
                }

                $params = $newParams;
            }

//            if (strpos($sql, 'INSERT INTO queue_user3') !== false) {
//                var_dump($params);
//            }

            $this->queries[] = array('sql' => $sql, 'params' => $params);
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
//        return true;

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
}
