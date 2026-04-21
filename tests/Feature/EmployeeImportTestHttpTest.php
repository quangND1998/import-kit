<?php

declare(strict_types=1);

namespace Vendor\ImportKit\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Vendor\ImportKit\Jobs\RunImportJob;
use Vendor\ImportKit\Tests\LaravelPackageTestCase;

final class EmployeeImportTestHttpTest extends LaravelPackageTestCase
{
    use DatabaseMigrations;

    private function createTempEmployeeSpreadsheet(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'ikt_emp_');
        if ($base === false) {
            self::fail('tempnam failed');
        }

        $path = $base . '.xlsx';
        if (!@rename($base, $path)) {
            @unlink($base);
            self::fail('Could not create temp xlsx path');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A6', 'Họ và tên*');
        $sheet->setCellValue('B6', 'Mã nhân viên');
        $sheet->setCellValue('C6', 'Email công ty');
        $sheet->setCellValue('D6', 'Thông tin tùy chỉnh 1');
        $sheet->setCellValue('E6', 'Thông tin tùy chỉnh 2');
        $sheet->setCellValue('A7', 'Vũ Minh Hiếu');
        $sheet->setCellValue('B7', 'TCV1383');
        $sheet->setCellValue('C7', 'hieuvm@topcv.vn');
        $sheet->setCellValue('D7', 'Hello');
        $sheet->setCellValue('E7', 'Hi');

        $sheet->setCellValue('A8', 'Nguyễn Mạnh Đức');
        $sheet->setCellValue('B8', 'TCV243243');
        $sheet->setCellValue('C8', 'haha@gmail.com');
        $sheet->setCellValue('D8', 'Hehehehe');
        $sheet->setCellValue('E8', 'Hahahah');

        // $sheet->setCellValue('A6', 'Họ và tên*');
        // $sheet->setCellValue('B6', 'Mã nhân viên');
        // $sheet->setCellValue('C6', 'Thông tin tùy chỉnh 1');
        // $sheet->setCellValue('A7', 'Vũ Minh Hiếu');
        // $sheet->setCellValue('B7', 'TCV1383');
        // $sheet->setCellValue('C7', 'Hello');

        // $sheet->setCellValue('A8', 'Nguyễn Mạnh Đức');
        // $sheet->setCellValue('B8', 'TCV243243');
        // $sheet->setCellValue('C8', 'Hehehehe');

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function testPostEmployeePreviewReturnsSuccessPayload(): void
    {
        $path = $this->createTempEmployeeSpreadsheet();

        try {
            $uploaded = new UploadedFile(
                $path,
                'employee.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $response = $this->post('/import-kit/test/employee/preview', [
                'file' => $uploaded,
            ], [
                'Accept' => 'application/json',
            ]);

            $response->assertOk();
            $response->assertJsonPath('ok', true);
            $response->assertJsonStructure([
                'ok',
                'message',
                'data' => [
                    'mode',
                    'import_session_id',
                    'total_row_ok',
                    'rows',
                ],
            ]);
            self::assertGreaterThanOrEqual(1, (int) $response->json('data.total_row_ok'));
        } finally {
            @unlink($path);
        }
    }

    public function testPostEmployeeSubmitReturnsJobIdAndQueuesRunJob(): void
    {
        Queue::fake();

        $path = $this->createTempEmployeeSpreadsheet();

        try {
            $uploaded = new UploadedFile(
                $path,
                'employee.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $preview = $this->post('/import-kit/test/employee/preview', [
                'file' => $uploaded,
            ], [
                'Accept' => 'application/json',
            ]);

            $preview->assertOk();
            $sessionId = (string) $preview->json('data.import_session_id');
            self::assertNotSame('', $sessionId);

            $submit = $this->postJson('/import-kit/test/employee/submit', [
                'session_id' => $sessionId,
            ]);

            if ($submit->getStatusCode() !== 200) {
                self::fail((string) $submit->getContent());
            }

            $submit->assertOk();
            $submit->assertJsonPath('ok', true);
            $submit->assertJsonStructure([
                'ok',
                'message',
                'data' => [
                    'import_job_id',
                    'kind',
                    'session_id',
                    'status',
                ],
            ]);

            Queue::assertPushed(RunImportJob::class);
        } finally {
            @unlink($path);
        }
    }

    public function testGetPreviewFixtureReturns404WhenPathNotSet(): void
    {
        $this->app['config']->set('import.test.employee_fixture_absolute_path', '');

        $this->getJson('/import-kit/test/employee/preview-fixture')
            ->assertStatus(404)
            ->assertJsonPath('ok', false);
    }
}
