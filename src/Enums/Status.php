<?php

declare(strict_types=1);

namespace PurpleOrca\Doctor\Enums;

enum Status: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
