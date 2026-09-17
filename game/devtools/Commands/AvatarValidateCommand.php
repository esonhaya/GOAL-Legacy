<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\Avatar\AvatarCatalog;
use Goal\Legacy\Modules\Player\Avatar\AvatarCatalogValidator;

final class AvatarValidateCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'avatar:validate'; }

    public function description(): string { return 'Validate Avatar V1 catalog, palettes, presets and SVG components.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $errors = (new AvatarCatalogValidator())->validate(new AvatarCatalog());
        if ($errors !== []) {
            foreach ($errors as $error) { $output->error($error); }
            return 1;
        }
        $output->write('AVATAR CATALOG — valid.');
        return 0;
    }
}
