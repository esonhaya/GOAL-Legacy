<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

final class MatchNotFoundException extends MatchException
{
    public function __construct(string $id) { parent::__construct(sprintf('Match "%s" does not exist.', $id)); }
}
