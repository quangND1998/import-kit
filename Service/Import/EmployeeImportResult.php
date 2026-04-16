<?php

namespace Happytime\Application\Employee\Service\Import;

use Happytime\Application\Employee\Service\Export\Employee;
use Happytime\Domain\Common\Status\ImportStatus;
use Happytime\Domain\Employee\Excel\MessageError;
use Happytime\Domain\Workspace\Workspace;

class EmployeeImportResult
{
    /**
     * @param Employee[] $data
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ImportStatus $status,
        private readonly ?array $data,
        private readonly ?MessageError $messageError,
        private readonly ?array $previewData = [],
        private readonly ?array $previewErrors = [],
    )
    {

    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    /**
     * @return ?Employee[]
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    public function getMessageError(): ?MessageError
    {
        return $this->messageError;
    }

    public function getPreviewData(): ?array
    {
        return $this->previewData;
    }

    public function getPreviewErrors(): ?array
    {
        return $this->previewErrors;
    }
}
