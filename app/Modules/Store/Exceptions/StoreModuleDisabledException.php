<?php

namespace App\Modules\Store\Exceptions;

use Exception;

class StoreModuleDisabledException extends Exception
{
    protected $message = 'Store module is currently disabled';
    protected $code = 503;
}
