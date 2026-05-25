<?php

namespace HiEvents\DomainObjects\Status;

enum EmailSuppressionSourceEnum: string
{
    case SES_NOTIFICATION = 'ses_notification';
    case MANUAL = 'manual';
    case MANUAL_RESOLVE = 'manual_resolve';
}
