<?php

declare(strict_types=1);

namespace App\Enums;

enum FieldCodesEnum: string
{
    case PHONE_CUSTOM_FIELD_CODE = 'PHONE';
    case EMAIL_CUSTOM_FIELD_CODE = 'EMAIL';
    case WORK_CUSTOM_FIELD_VALUE_CODE = 'WORK';
}
