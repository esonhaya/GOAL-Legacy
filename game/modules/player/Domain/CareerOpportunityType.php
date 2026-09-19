<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum CareerOpportunityType: string
{
    case RoleIncrease = 'role_increase';
    case PlayingTime = 'playing_time';
    case TransferInterest = 'transfer_interest';
    case RoleReassessment = 'role_reassessment';
    case ContractRenewal = 'contract_renewal';
    case Retirement = 'retirement';
}
