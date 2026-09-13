<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Domain;

final class TransferNotFoundException extends TransferException
{
    public function __construct(string $id) { parent::__construct(sprintf('Transfer "%s" does not exist.', $id)); }
}
