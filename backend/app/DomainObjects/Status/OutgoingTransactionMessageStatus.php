<?php

namespace HiEvents\DomainObjects\Status;

enum OutgoingTransactionMessageStatus: string
{
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case BOUNCED = 'BOUNCED';
    case SUPPRESSED = 'SUPPRESSED';
}
