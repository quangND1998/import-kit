<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Contracts;

use Vendor\ImportKit\DTO\TemplateValidationResult;

interface TemplateAwareSourceReaderInterface extends SourceReaderInterface
{
    public function templateValidation(): TemplateValidationResult;
}

