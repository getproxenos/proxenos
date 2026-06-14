<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * v0 role vocabulary. Only `owner` is meaningful right now (ADR-005 / ADR-020:
 * model permissions even if always-owner). Future roles append; storage stays
 * a varchar so additions are data, not schema, migrations.
 */
enum MembershipRole: string
{
    case OWNER = 'owner';
}
