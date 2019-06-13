<?php
namespace carlonicora\minimalism\library\interfaces;

use mysqli;

interface InterfaceConfigurations
{
    /**
     * @param string $databaseName
     * @return mysqli|null
     */
    function getDatabase($databaseName);

    /**
     * @param string $databaseName
     * @return array
     */
    function getDatabaseConnectionString($databaseName): array;

    /**
     * @param string $databaseName
     * @param mysqli $database
     */
    function setDatabase($databaseName, $database);
}