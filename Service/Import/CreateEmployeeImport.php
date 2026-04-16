<?php

namespace Happytime\Application\Employee\Service\Import;

use App\Jobs\Employee\ValidateImportCreateEmployeeJob;
use Happytime\Domain\Employee\Repository\EmployeeImportResultRepository;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class CreateEmployeeImport implements ToCollection
{
    public function __construct(
        private readonly \Happytime\Domain\Employee\Excel\EmployeeImportResult $result,
    ) {
    }

    public function collection(Collection $collection): void
    {
        /**
         * @var EmployeeImportResultRepository $repo
         */
        $repo = app(EmployeeImportResultRepository::class);
        $repo->save($this->result);

        ValidateImportCreateEmployeeJob::dispatch($collection, $this->result);
    }
}
