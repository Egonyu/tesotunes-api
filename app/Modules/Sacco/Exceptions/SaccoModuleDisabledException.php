<?php

namespace App\Modules\Sacco\Exceptions;

use Exception;

class SaccoModuleDisabledException extends Exception
{
    protected $message = 'SACCO module is currently disabled';
    protected $code = 503;
}
