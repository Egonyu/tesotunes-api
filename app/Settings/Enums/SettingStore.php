<?php

namespace App\Settings\Enums;

enum SettingStore: string
{
    case Db = 'db';
    case DbEncrypted = 'db_encrypted';
    case Env = 'env';
    case SaccoTable = 'sacco_table';
}
