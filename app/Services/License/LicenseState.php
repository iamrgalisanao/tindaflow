<?php

namespace App\Services\License;

/** ADR-018 decision 2. LEGACY and NOT_ACTIVATED exist only around adoption; the other four are the certificate's life. */
enum LicenseState: string
{
    /** No vendor key in the build, or an ADR-014 license not yet adopted: nothing is enforced. */
    case Legacy = 'LEGACY';

    /** A vendor key is configured but no certificate has been installed. */
    case NotActivated = 'NOT_ACTIVATED';

    case Active = 'ACTIVE';

    /** The last 7 days before `exp`: a banner, no refusal. */
    case Warning = 'WARNING';

    case Expired = 'EXPIRED';

    case Locked = 'LOCKED';
}
