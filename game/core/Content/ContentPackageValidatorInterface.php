<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

interface ContentPackageValidatorInterface
{
    public function validate(ContentPackage $package): void;
}
