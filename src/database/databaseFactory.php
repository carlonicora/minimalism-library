<?php
namespace carlonicora\minimalism\library\database;

use carlonicora\minimalism\library\interfaces\ConfigurationsInterface;
use mysqli;

class databaseFactory {
    /** @var ConfigurationsInterface */
    protected static $configurations;

    public static function initialise($configurations){
        self::$configurations = $configurations;
    }

    /**
     * @param string $dbReader
     * @return AbstractDatabaseManager
     */
    public static function create($dbReader){
        $response = null;

        if (!class_exists($dbReader)){
            return(null);
        }

        /** @var AbstractDatabaseManager $response */
        $response = new $dbReader();

        $databaseName = $response->getDbToUse();
        $connection = self::$configurations->getDatabase($databaseName);

        $dbConf = self::$configurations->getDatabaseConnectionString($databaseName);

        if (!isset($connection) && isset($dbConf)){
            $connection = new mysqli($dbConf['host'], $dbConf['username'], $dbConf['password'], $dbConf['dbName'], $dbConf['port']);
        }

        if (isset($connection) && !isset($connection->thread_id)) {
            $connection->connect($dbConf['host'], $dbConf['username'], $dbConf['password'], $dbConf['dbName'], $dbConf['port']);
        }

        if (!isset($connection) || $connection->connect_errno) return (null);

        $connection->set_charset("utf8");

        $response->setConnection($connection);

        return($response);
    }
}