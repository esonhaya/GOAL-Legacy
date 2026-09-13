<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Contract\Domain;

final class ContractNotFoundException extends ContractException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Contract "%s" does not exist.', $id));
    }
}
