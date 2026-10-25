<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
if (!$isAdmin) { http_response_code(403); exit('Akses ditolak.'); }
require_once __DIR__ . '/../packages/AttendanceReport/AttendanceReport.php';
use App\Reports\AttendanceReport;
use App\Reports\ReportExport;
[$from, $to] = (new AttendanceReport(db()))->normalizeRange($_GET['from'] ?? null, $_GET['to'] ?? null);
$classId = isset($_GET['kelas']) && ctype_digit((string)$_GET['kelas']) ? (int)$_GET['kelas'] : null;
$format = strtolower((string) ($_GET['format'] ?? 'xlsx'));
$service = new AttendanceReport(db());
$report = $service->build($from, $to, $classId);
if ($classId === null) {
    $report['byClass'] = [];
    foreach ($service->classes() as $kelas) {
        $report['byClass'][$kelas['nama_kelas']] = $service->build($from, $to, (int) $kelas['id']);
    }
    if ($report['byClass'] === []) $report['byClass']['Semua Kelas'] = $report;
} else {
    $report['byClass'] = [$report['rows'][0]['nama_kelas'] ?? 'Kelas terpilih' => $report];
}
audit_log($adminId, 'admin.report.download', 'info', $format . ' ' . $from . ' - ' . $to);
try {
    if ($format === 'pdf') ReportExport::pdf($report);
    if (in_array($format, ['xlsx', 'excel'], true)) ReportExport::excel($report);
} catch (Throwable $e) {
    error_log('[REPORT_EXPORT] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    exit('Export laporan gagal: ' . $e->getMessage());
}
http_response_code(400); exit('Format laporan tidak didukung.');
