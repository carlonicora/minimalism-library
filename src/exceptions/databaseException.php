<?php
namespace carlonicora\minimalism\library\exceptions;

use Exception;

class databaseException extends Exception {
    public const CREATION_FAILED = 1;
    public const CREATION_FAILED_MESSAGE = 'The creation of the user in the database failed.';
}