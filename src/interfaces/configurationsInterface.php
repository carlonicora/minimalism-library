<?php
namespace carlonicora\minimalism\library\interfaces;

use mysqli;

interface configurationsInterface
{
    /**
     * @param string $databaseName
     * @return mysqli|null
     */
    public function getDatabase($databaseName): ?mysqli;

    /**
     * @param string $databaseName
     * @return array
     */
    public function getDatabaseConnectionString($databaseName): array;

    /**
     * @param string $databaseName
     * @param mysqli $database
     */
    public function setDatabase($databaseName, $database);
}