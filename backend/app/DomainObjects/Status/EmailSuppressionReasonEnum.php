<?php

namespace HiEvents\DomainObjects\Status;

enum EmailSuppressionReasonEnum: string
{
    case BOUNCE = 'bounce';
    case COMPLAINT = 'complaint';
    case DO_NOT_CONTACT = 'do_not_contact';
}
