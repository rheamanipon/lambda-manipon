<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Dompdf\Dompdf;
use Dompdf\Options;

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readCsv(string $filename): array
{
    $path = dirname(__DIR__) . '/data/' . $filename;

    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("CSV file is missing or unreadable: {$filename}");
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("Unable to open CSV file: {$filename}");
    }

    try {
        $headers = fgetcsv($handle);
        if ($headers === false) {
            return [];
        }

        $headers = array_map(
            static function ($header): string {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);
                return strtolower(trim($header));
            },
            $headers
        );

        $records = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }
            if (count($values) !== count($headers)) {
                error_log("Skipping malformed row in {$filename}");
                continue;
            }

            $record = array_combine($headers, $values);
            if ($record !== false) {
                $records[] = $record;
            }
        }

        return $records;
    } finally {
        fclose($handle);
    }
}

function money($value): float
{
    return (float) str_replace([',', '$', '₱', ' '], '', (string) $value);
}

function findEmployee(array $employees, $employeeId): ?array
{
    foreach ($employees as $employee) {
        if ((string) ($employee['id'] ?? '') === (string) $employeeId) {
            return $employee;
        }
    }

    return null;
}

function getPayrollDetails(array $payrollRecords, array $employees, string $payrollId): ?array
{
    foreach ($payrollRecords as $record) {
        if ((string) ($record['id'] ?? '') !== $payrollId) {
            continue;
        }

        $employeeId = $record['employee_id'] ?? '';
        $employee = findEmployee($employees, $employeeId);
        $gross = money($record['gross_pay'] ?? 0);
        $net = money($record['net_pay'] ?? 0);

        // The supplied payroll CSV has no deductions column.
        // The assignment specifies deductions = gross pay - net pay.
        $deductions = $gross - $net;

        return [
            'payroll_id' => $record['id'],
            'employee_id' => $employeeId,
            'employee_name' => $employee['full_name'] ?? null,
            'pay_period_start' => $record['pay_period_start'] ?? null,
            'pay_period_end' => $record['pay_period_end'] ?? null,
            'gross_pay' => round($gross, 2),
            'deductions' => round($deductions, 2),
            'net_pay' => round($net, 2),
        ];
    }

    return null;
}

function generatePayslipPdf(array $details): string
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);

    $escape = static fn($value): string =>
        htmlspecialchars((string) ($value ?? 'Not available'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = '
    <!doctype html>
    <html>
    <head>
      <meta charset="UTF-8">
      <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        .muted { color: #666; }
        .row { padding: 8px 0; border-bottom: 1px solid #ddd; }
        .label { display: inline-block; width: 180px; }
        .net { font-size: 16px; font-weight: bold; margin-top: 12px; }
      </style>
    </head>
    <body>
      <h1>Employee Payslip</h1>
      <p class="muted">Payroll record summary</p>
      <div class="row"><span class="label">Payroll ID</span>' . $escape($details['payroll_id']) . '</div>
      <div class="row"><span class="label">Employee ID</span>' . $escape($details['employee_id']) . '</div>
      <div class="row"><span class="label">Employee Name</span>' . $escape($details['employee_name']) . '</div>
      <div class="row"><span class="label">Pay Period Start</span>' . $escape($details['pay_period_start']) . '</div>
      <div class="row"><span class="label">Pay Period End</span>' . $escape($details['pay_period_end']) . '</div>
      <div class="row"><span class="label">Gross Pay</span>' . number_format((float) $details['gross_pay'], 2) . '</div>
      <div class="row"><span class="label">Deductions</span>' . number_format((float) $details['deductions'], 2) . '</div>
      <div class="net"><span class="label">Net Pay</span>' . number_format((float) $details['net_pay'], 2) . '</div>
    </body>
    </html>';

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

function uploadPdfToS3(string $key, string $pdfContents): void
{
    $bucket = getenv('S3_BUCKET');
    $region = getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION');

    if (!$bucket || !$region) {
        throw new RuntimeException('S3_BUCKET or AWS region environment variable is missing.');
    }

    $s3 = new S3Client([
        'version' => 'latest',
        'region' => $region,
    ]);

    $s3->putObject([
        'Bucket' => $bucket,
        'Key' => $key,
        'Body' => $pdfContents,
        'ContentType' => 'application/pdf',
    ]);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    if ($method === 'GET' && $path === '/api/employees') {
        respond([
            'success' => true,
            'data' => readCsv('employees.csv'),
        ]);
    }

    if ($method === 'GET' && $path === '/api/reports/payroll') {
        $payrollRecords = readCsv('payroll.csv');
        $grossTotal = 0.0;
        $netTotal = 0.0;
        $deductionsTotal = 0.0;

        foreach ($payrollRecords as $record) {
            $gross = money($record['gross_pay'] ?? 0);
            $net = money($record['net_pay'] ?? 0);
            $grossTotal += $gross;
            $netTotal += $net;
            $deductionsTotal += ($gross - $net);
        }

        respond([
            'success' => true,
            'data' => [
                'total_payroll_records' => count($payrollRecords),
                'total_gross_pay' => round($grossTotal, 2),
                'total_deductions' => round($deductionsTotal, 2),
                'total_net_pay' => round($netTotal, 2),
            ],
        ]);
    }

    if (preg_match('#^/api/payslips/([^/]+)(/generate)?$#', $path, $matches) === 1) {
        $payrollId = rawurldecode($matches[1]);
        $isGenerateRequest = isset($matches[2]) && $matches[2] === '/generate';

        if ($isGenerateRequest && $method !== 'POST') {
            respond(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
        }
        if (!$isGenerateRequest && $method !== 'GET') {
            respond(['success' => false, 'message' => 'Method not allowed. Use GET.'], 405);
        }

        $payrollRecords = readCsv('payroll.csv');
        $employees = readCsv('employees.csv');
        $details = getPayrollDetails($payrollRecords, $employees, $payrollId);

        if ($details === null) {
            respond(['success' => false, 'message' => 'Payroll record not found.'], 404);
        }

        if (!$isGenerateRequest) {
            respond(['success' => true, 'data' => $details]);
        }

        $pdfContents = generatePayslipPdf($details);
        $key = "payslips/{$payrollId}.pdf";
        uploadPdfToS3($key, $pdfContents);

        
        $bucket = getenv('S3_BUCKET') ?: 'payroll-payslips-rhea-2026';

        respond([
            'success' => true,
            'message' => 'Payslip generated and uploaded.',
            'data' => [
                'payroll_id' => $payrollId,
                's3_key' => $key,
                'bucket' => $bucket,
                's3_url' => "https://{$bucket}.s3.ap-southeast-2.amazonaws.com/{$key}",
            ],
        ]);
    }

    respond(['success' => false, 'message' => 'Endpoint not found.'], 404);
} catch (Throwable $exception) {
    error_log((string) $exception);
    respond(['success' => false, 'message' => 'Internal server error.'], 500);
}
