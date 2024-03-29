<?php
namespace carlonicora\minimalism\library\database;

use carlonicora\minimalism\library\exceptions\dbRecordNotFoundException;
use carlonicora\minimalism\library\exceptions\dbUpdateException;
use mysqli;
use Exception;

abstract class abstractDatabaseManager {
    public const PARAM_TYPE_INTEGER = 'i';
    public const PARAM_TYPE_DOUBLE = 'd';
    public const PARAM_TYPE_STRING = 's';
    public const PARAM_TYPE_BLOB = 'b';

    public const RECORD_STATUS_NEW = 1;
    public const RECORD_STATUS_UNCHANGED = 2;
    public const RECORD_STATUS_UPDATED = 3;
    public const RECORD_STATUS_DELETED = 4;

    /** @var mysqli */
    private $connection;

    /** @var string */
    protected $dbToUse;

    /** @var string */
    protected $autoIncrementField;

    /** @var array */
    protected $fields;

    /** @var array */
    protected $primaryKey;

    /** @var string */
    protected $tableName;

    /**
     * abstractDatabaseManager constructor.
     */
    public function __construct() {
        if (!isset($this->tableName)){
            $fullName = get_class($this);
            $fullNameParts = explode('\\', $fullName);
            $this->tableName = end($fullNameParts);
        }
    }

    /**
     * @return string
     */
    public function getDbToUse(): string {
        return $this->dbToUse;
    }

    /**
     * @param mysqli $connection
     */
    public function setConnection(mysqli $connection): void {
        $this->connection = $connection;
    }

    /**
     * @param array $records
     * @return bool
     * @throws dbUpdateException
     */
    public function delete($records): bool {
        return $this->update($records, true);
    }

    /**
     * @param string $sql
     * @param array $parameters
     * @return bool
     */
    public function runSql($sql, $parameters): bool {
        $response = true;

        try{
            $this->connection->autocommit(false);

            $statement = $this->connection->prepare($sql);

            if ($statement) {
                call_user_func_array(array($statement, 'bind_param'), $this->refValues($parameters));
                if (!$statement->execute()) {
                    $this->connection->rollback();
                    $response = false;
                }
            }

            $this->connection->autocommit(true);
        } catch (Exception $e){
            $this->connection->rollback();
            $response = false;
        }

        return $response;
    }

    /**
     * @param array $records
     * @param bool $delete
     * @return bool
     * @throws dbUpdateException
     */
    public function update(&$records, $delete=false): bool {
        $response = array();

        $isSingle = false;

        if (isset($records) && count($records) > 0){
            if (!array_key_exists(0, $records)){
                $isSingle = true;
                $records= [$records];
            }

            foreach ($records as $recordKey=>$record) {
                if ($delete){
                    $status = self::RECORD_STATUS_DELETED;
                } else {
                    $status = $this->status($record);
                }

                if ($status !== self::RECORD_STATUS_UNCHANGED) {
                    $records[$recordKey]['sql'] = array();
                    $records[$recordKey]['sql']['status'] = $status;

                    $parameters = [];
                    $parametersToUse = null;

                    switch ($status) {
                        case self::RECORD_STATUS_NEW:
                            $records[$recordKey]['sql']['statement'] = $this->generateInsertStatement();
                            $parametersToUse = $this->generateInsertParameters();
                            break;
                        case self::RECORD_STATUS_UPDATED:
                            $records[$recordKey]['sql']['statement'] = $this->generateUpdateStatement();
                            $parametersToUse = $this->generateUpdateParameters();
                            break;
                        case self::RECORD_STATUS_DELETED:
                            $records[$recordKey]['sql']['statement'] = $this->generateDeleteStatement();
                            $parametersToUse = $this->generateDeleteParameters();
                            break;

                    }

                    foreach ($parametersToUse as $parameter){
                        if (count($parameters) === 0){
                            $parameters[] = $parameter;
                        } else if (array_key_exists($parameter, $record)){
                            $parameters[] = $record[$parameter];
                        } else {
                            $parameters[] = null;
                        }
                    }
                    $records[$recordKey]['sql']['parameters'] = $parameters;
                }
            }

            $response = $this->runUpdate($records);
        }

        if ($isSingle){
            $records = $records[0];
        }

        return $response;
    }

    /**
     * @param string $sql
     * @param array $parameters
     * @return array
     * @throws dbRecordNotFoundException
     */
    protected function runRead($sql, $parameters=null): array {
        $response = null;

        $statement = $this->connection->prepare($sql);
        if (isset($parameters)) {
            call_user_func_array(array($statement, 'bind_param'), $this->refValues($parameters));
        }

        $statement->execute();

        $results = $statement->get_result();

        if (!empty($results) && $results->num_rows > 0){
            $response = array();

            while ($record = $results->fetch_assoc()){
                $this->addOriginalValues($record);

                $response[] = $record;
            }
        } else {
            throw new dbRecordNotFoundException('No records found');
        }

        $statement->close();

        return $response;
    }

    /**
     * @param array $objects
     * @return bool
     * @throws dbUpdateException
     */
    protected function runUpdate(&$objects): bool {
        $response = true;

        $this->connection->autocommit(false);

        foreach ($objects as $objectKey=>$object){
            if (array_key_exists('sql', $object)) {
                $statement = $this->connection->prepare($object['sql']['statement']);

                if ($statement) {
                    $parameters = $object['sql']['parameters'];
                    call_user_func_array(array($statement, 'bind_param'), $this->refValues($parameters));
                    if (!$statement->execute()) {
                        $this->connection->rollback();
                        throw new dbUpdateException('Statement Execution failed: ' .
                            $object['sql']['statement'] .
                            ' with parameters ' . json_encode($object['sql']['parameters']));
                    }
                } else {
                    $this->connection->rollback();
                    throw new dbUpdateException('Statement creation failed: ' .
                        $objects[$objectKey]['sql']['statement']);
                }

                if (isset($this->autoIncrementField) && $object['sql']['status'] === self::RECORD_STATUS_NEW) {
                    $objects[$objectKey][$this->autoIncrementField] = $this->connection->insert_id;
                }

                unset($objects[$objectKey]['sql']);

                $this->addOriginalValues($objects[$objectKey]);
            }
        }

        $this->connection->autocommit(true);

        return $response;
    }

    /**
     * @param string $sql
     * @param string $parameters
     * @return array|null
     * @throws dbRecordNotFoundException
     */
    protected function runReadSingle($sql, $parameters=null): ?array {
        $response = $this->runRead($sql, $parameters);

        if (isset($response)) {
            if (count($response) === 0){
                throw new dbRecordNotFoundException('Record not found');
            }

            if (count($response) === 1){
                $response = $response[0];
            } else {
                throw new dbRecordNotFoundException('Multiple records found');
            }
        } else {
            throw new dbRecordNotFoundException('Record not found!');
        }


        return $response;
    }

    /**
     * @param $record
     * @return int
     */
    protected function status($record): int {
        if (array_key_exists('originalValues', $record)){
            $response = self::RECORD_STATUS_UNCHANGED;
            foreach ($record['originalValues'] as $fieldName=>$originalValue){
                if ($originalValue !== $record[$fieldName]){
                    $response = self::RECORD_STATUS_UPDATED;
                    break;
                }
            }
        } else {
            $response = self::RECORD_STATUS_NEW;
        }

        return $response;
    }

    /**
     * @param array $record
     */
    private function addOriginalValues(&$record): void {
        $originalValues = array();
        foreach($record as $fieldName=>$fieldValue){
            $originalValues[$fieldName] = $fieldValue;
        }
        $record['originalValues'] = $originalValues;
    }

    /**
     * @param $arr
     * @return array
     */
    private function refValues($arr): array {
        $refs = [];

        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }

        return $refs;
    }

    /**
     * @return string
     */
    private function generateSelectStatement(): string {
        $response = 'SELECT * FROM ' . $this->tableName . ' WHERE ';

        foreach ($this->primaryKey as $fieldName=>$fieldType){
            $response .= $fieldName . '=? AND ';
        }

        $response = substr($response, 0, -5);

        $response .= ';';

        return $response;
    }

    /**
     * @return array
     */
    private function generateSelectParameters(): array {
        $response = array();

        $response[] = '';

        foreach ($this->primaryKey as $fieldName=>$fieldType){
            $response[0] .= $fieldType;
            $response[] = $fieldName;
        }

        return $response;
    }

    /**
     * @return string
     */
    private function generateInsertStatement(): string {
        $response = 'INSERT INTO ' . $this->tableName . ' (';

        $parameterList = '';
        foreach ($this->fields as $fieldName=>$fieldType){
            $response .= $fieldName . ', ';
            $parameterList .= '?, ';
        }

        $response = substr($response, 0, -2);
        $parameterList = substr($parameterList, 0, -2);

        $response .= ') VALUES (' . $parameterList . ');';

        return $response;
    }

    /**
     * @return array
     */
    private function generateInsertParameters(): array {
        $response = array();

        $response[] = '';

        foreach ($this->fields as $fieldName=>$fieldType){
            $response[0] .= $fieldType;
            $response[] = $fieldName;
        }

        return $response;
    }

    /**
     * @return string
     */
    private function generateDeleteStatement(): string {
        $response = 'DELETE FROM ' . $this->tableName . ' WHERE ';

        foreach ($this->primaryKey as $fieldName=>$fieldType){
            $response .= $fieldName . '=? AND ';
        }

        $response = substr($response, 0, -5);

        $response .= ';';

        return $response;
    }

    /**
     * @return array
     */
    private function generateDeleteParameters(): array {
        $response = array();

        $response[] = '';

        foreach ($this->primaryKey as $fieldName=>$fieldType){
            $response[0] .= $fieldType;
            $response[] = $fieldName;
        }

        return $response;
    }

    /**
     * @return string
     */
    private function generateUpdateStatement(): string {
        $response = 'UPDATE ' . $this->tableName . ' SET ';

        foreach ($this->fields as $fieldName=>$fieldType){
            if (!array_key_exists($fieldName, $this->primaryKey)){
                $response .= $fieldName . '=?, ';
            }
        }

        $response = substr($response, 0, -2);

        $response .= ' WHERE ';

        foreach ($this->primaryKey as $fieldName=>$fieldType){
            $response .= $fieldName . '=? AND ';
        }

        $response = substr($response, 0, -5);

        $response .= ';';

        return $response;
    }

    /**
     * @return array
     */
    private function generateUpdateParameters(): array {
        $response = array();

        $response[] = '';

        foreach ($this->fields as $fieldName=>$fieldType){
            if (!array_key_exists($fieldName, $this->primaryKey)) {
                $response[0] .= $fieldType;
                $response[] = $fieldName;
            }
        }

        foreach ($this->primaryKey as $fieldName=>$fieldType){
            $response[0] .= $fieldType;
            $response[] = $fieldName;
        }

        return $response;
    }

    /**
     * @param $id
     * @return array|null
     * @throws dbRecordNotFoundException
     */
    public function loadFromId($id): ?array {
        $sql = $this->generateSelectStatement();
        $parameters = $this->generateSelectParameters();

        $parameters[1] = $id;

        return $this->runReadSingle($sql, $parameters);
    }

    /**
     * @return array|null
     * @throws dbRecordNotFoundException
     */
    public function loadAll(): ?array {
        $sql = 'SELECT * FROM ' . $this->tableName . ';';

        return $this->runRead($sql);
    }
}