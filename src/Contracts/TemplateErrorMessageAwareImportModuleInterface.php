<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

interface TemplateErrorMessageAwareImportModuleInterface extends ImportModuleInterface
{
    public function invalidTemplateMessage(): string;
}
