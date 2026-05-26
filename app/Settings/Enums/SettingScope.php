<?php

namespace App\Settings\Enums;

enum SettingScope: string
{
    case Global = 'global';
    case Env = 'env';
    case Secret = 'secret';
}
