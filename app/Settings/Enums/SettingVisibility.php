<?php

namespace App\Settings\Enums;

enum SettingVisibility: string
{
    case Public = 'public';
    case Authenticated = 'authenticated';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';
}
