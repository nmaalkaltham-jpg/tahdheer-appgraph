<?php

namespace App\Domain\Entitlements;

/**
 * The five refund revocation options (Digital Product OS).
 */
enum RevocationPolicy: string
{
    case Immediate = 'immediate';
    case Grace3d = 'grace_3d';       // default: revoke after a 3-day grace window
    case Keep = 'keep';
    case UpdatesOnly = 'updates_only';
    case LicenseOnly = 'license_only';
}
