<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

final class ContentPackageValidator implements ContentPackageValidatorInterface
{
    public function validate(ContentPackage $package): void
    {
        foreach ($package->manifest()->files() as $file) {
            $package->resolvePath($file);
        }
    }
}
