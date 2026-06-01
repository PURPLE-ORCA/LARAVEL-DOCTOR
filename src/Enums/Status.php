<?php

declare(strict_types=1);

namespace Sahraoui\Doctor\Enums;

enum Status: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
