<?php
declare(strict_types=1);
namespace App\Reports;
use PDO;
use RuntimeException;

final class AttendanceReport
{
    public function __construct(private PDO $db) {}
    public function normalizeRange(?string $from, ?string $to): array
    {
        $ok = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $from = $ok($from) ? $from : date('Y-m-01'); $to = $ok($to) ? $to : date('Y-m-d');
        if ($from > $to) [$from,$to]=[$to,$from];
        $max=date('Y-m-d',strtotime($from.' +24 months')); if($to>$max)$to=$max;
        return [$from,$to];
    }
    public function build(string $from,string $to,?int $classId=null): array
    {
        $where='a.tanggal BETWEEN ? AND ?'; $args=[$from,$to];
        if($classId!==null&&$classId>0){$where.=' AND u.kelas_id = ?';$args[]=$classId;}
        $sql="SELECT a.tanggal,u.nama_lengkap,u.username,u.nis,k.nama_kelas,
        MAX(CASE WHEN a.scan_type='masuk' THEN a.waktu END) waktu_masuk,
        MAX(CASE WHEN a.scan_type='pulang' THEN a.waktu END) waktu_pulang,
        MAX(CASE WHEN a.scan_type='masuk' THEN a.status END) status_masuk,
        MAX(CASE WHEN a.scan_type='masuk' THEN a.is_late END) is_late,
        MAX(CASE WHEN a.scan_type='masuk' THEN a.late_minutes END) late_minutes,
        MAX(CASE WHEN a.scan_type='pulang' THEN a.gps_verdict END) gps_pulang
        FROM attendance a JOIN users u ON u.id=a.user_id AND u.role='siswa'
        LEFT JOIN kelas k ON k.id=u.kelas_id WHERE $where
        GROUP BY a.tanggal,u.id,u.nama_lengkap,u.username,u.nis,k.nama_kelas
        ORDER BY a.tanggal,k.nama_kelas,u.nama_lengkap";
        $st=$this->db->prepare($sql);$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);$izin=[];
        try{$st=$this->db->prepare("SELECT i.user_id,u.nama_lengkap,k.nama_kelas,i.jenis,i.tanggal_mulai,i.tanggal_selesai,i.status,i.alasan FROM izin_requests i JOIN users u ON u.id=i.user_id LEFT JOIN kelas k ON k.id=u.kelas_id WHERE i.tanggal_mulai<=? AND i.tanggal_selesai>=? ORDER BY i.tanggal_mulai,u.nama_lengkap");$st->execute([$to,$from]);$izin=$st->fetchAll(PDO::FETCH_ASSOC);}catch(\Throwable){}
        $summary=['baris'=>count($rows),'hadir'=>0,'pulang'=>0,'telat'=>0,'izin'=>count($izin),'range'=>[$from,$to]];
        foreach($rows as $r){$summary['hadir']+=(bool)$r['waktu_masuk'];$summary['pulang']+=(bool)$r['waktu_pulang'];$summary['telat']+=(int)$r['is_late']===1;}
        return compact('from','to','summary','rows','izin');
    }
    public function classes():array{return $this->db->query('SELECT id,nama_kelas FROM kelas WHERE is_active=1 ORDER BY nama_kelas')->fetchAll(PDO::FETCH_ASSOC);}
    public static function rows(array $r):array{$out=[['Tanggal','Nama Lengkap','Username','NIS','Kelas','Scan Masuk','Scan Pulang','Status','Terlambat (menit)','GPS Pulang']];foreach($r['rows'] as $x)$out[]=[$x['tanggal'],$x['nama_lengkap'],$x['username'],$x['nis']??'', $x['nama_kelas']??'Tanpa kelas',$x['waktu_masuk']??'', $x['waktu_pulang']??'', $x['status_masuk']??'hadir',(int)($x['late_minutes']??0),$x['gps_pulang']??''];return $out;}
}

final class ReportExport
{
    private static function out(string $data,string $type,string $name):void{while(ob_get_level())ob_end_clean();header('Content-Type: '.$type);header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.strlen($data));header('Cache-Control: no-store');echo $data;exit;}
    private static function cell($v):string{return htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8');}
    public static function excel(array $report): void
    {
        self::xlsx($report);
    }

    private static function xlsxCell(string $value, int $style = 0): string
    {
        return '<c t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . self::cell($value) . '</t></is></c>';
    }

    private static function xlsxSheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="14"/><col min="2" max="2" width="28"/><col min="3" max="3" width="18"/><col min="4" max="4" width="16"/><col min="5" max="5" width="18"/><col min="6" max="7" width="16"/><col min="8" max="8" width="18"/><col min="9" max="10" width="18"/></cols><sheetData>';
        $lastRow = max(3, count($rows));
        foreach ($rows as $ri => $row) {
            $xml .= '<row r="' . ($ri + 1) . '"' . ($ri === 0 ? ' ht="28" customHeight="1"' : '') . '>';
            foreach ($row as $ci => $value) {
                $explicit = is_array($value) ? (int) ($value[1] ?? 0) : 0;
                $style = $explicit ?: ($ri === 0 ? 3 : ($ri === 1 ? 5 : ($ri === 2 ? 1 : (($ri % 2) === 0 ? 2 : 0))));
                $text = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                $xml .= self::xlsxCell($text, $style);
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData><autoFilter ref="A3:J' . $lastRow . '"/></worksheet>';
    }

    private static function xlsx(array $report): void
    {
        $groups = $report['byClass'] ?? ['Semua Kelas' => $report];
        $sheets = [];
        $summary = [['LAPORAN PRESENSI'], ['Periode', $report['from'] . ' s/d ' . $report['to']], ['Kelas', 'Hadir', 'Pulang', 'Terlambat', 'Izin/Sakit']];
        foreach ($groups as $name => $r) {
            $summary[] = [$name, (string) $r['summary']['hadir'], (string) $r['summary']['pulang'], (string) $r['summary']['telat'], (string) $r['summary']['izin']];
            $rows = [['KELAS ' . $name], ['Periode', $r['from'] . ' s/d ' . $r['to']], [['Tanggal', 1], ['Nama Lengkap', 1], ['Username', 1], ['NIS', 1], ['Kelas', 1], ['Scan Masuk', 1], ['Scan Pulang', 1], ['Status', 1], ['Terlambat (menit)', 1], ['GPS Pulang', 1]]];
            foreach (array_slice(AttendanceReport::rows($r), 1) as $row) $rows[] = $row;
            $sheets[] = ['name' => (string) $name, 'rows' => $rows];
        }
        $sheets = array_merge([['name' => 'Ringkasan', 'rows' => $summary]], $sheets);
        $izinRows = [[['Kelas', 1], ['Nama', 1], ['Jenis', 1], ['Mulai', 1], ['Selesai', 1], ['Status', 1], ['Alasan', 1]]];
        foreach ($groups as $name => $r) foreach ($r['izin'] as $z) $izinRows[] = [$name, $z['nama_lengkap'], $z['jenis'], $z['tanggal_mulai'], $z['tanggal_selesai'], $z['status'], $z['alasan']];
        $sheets[] = ['name' => 'Izin-Sakit', 'rows' => $izinRows];
        $entries = [];
        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheets as $i => $_) $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $contentTypes .= '</Types>';
        $entries['[Content_Types].xml'] = $contentTypes;
        $entries['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $sheet) {
            $id = $i + 1;
            $name = substr((string) $sheet['name'], 0, 31);
            $workbook .= '<sheet name="' . self::cell($name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        }
        $workbook .= '</sheets></workbook>'; $rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $entries['xl/workbook.xml'] = $workbook; $entries['xl/_rels/workbook.xml.rels'] = $rels;
        $entries['xl/styles.xml'] = '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="5"><font><sz val="10"/><name val="Aptos"/><color rgb="FF1E293B"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font><font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Aptos Display"/></font><font><b/><sz val="10"/><color rgb="FF166534"/><name val="Aptos"/></font><font><b/><sz val="10"/><color rgb="FF334155"/><name val="Aptos"/></font></fonts><fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor rgb="FF1E3A8A"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor rgb="FFF8FAFC"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor rgb="FF0F766E"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/><bgColor rgb="FFECFDF5"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor rgb="FFEFF6FF"/></patternFill></fill></fills><borders count="1"><border/></borders><cellXfs count="6"><xf fontId="0" fillId="0" borderId="0"/><xf fontId="1" fillId="1" borderId="0" applyFont="1" applyFill="1"/><xf fontId="0" fillId="5" borderId="0" applyFill="1"/><xf fontId="2" fillId="1" borderId="0" applyFont="1" applyFill="1"/><xf fontId="3" fillId="4" borderId="0" applyFont="1" applyFill="1"/><xf fontId="4" fillId="2" borderId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';
        foreach ($sheets as $i => $sheet) $entries['xl/worksheets/sheet' . ($i + 1) . '.xml'] = self::xlsxSheet($sheet['rows']);
        $data = self::zipStore($entries); self::out($data, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'laporan-presensi-' . $report['from'] . '-' . $report['to'] . '.xlsx');
    }

    private static function zipStore(array $entries): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Ekspor XLSX membutuhkan ekstensi PHP ZipArchive aktif.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'presensi-xlsx-');
        if ($tmp === false) throw new RuntimeException('Gagal membuat berkas sementara XLSX.');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Gagal membuat arsip XLSX.');
        }
        foreach ($entries as $name => $content) {
            $zip->addFromString((string) $name, (string) $content);
        }
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        if ($data === false || substr($data, 0, 2) !== 'PK') {
            throw new RuntimeException('Arsip XLSX yang dihasilkan tidak valid.');
        }
        return $data;
    }

    private static function excelLegacy(array $report): void
    {
        $groups=$report['byClass']??['Semua Kelas'=>$report];
        $xml='<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="t"><Font ss:Bold="1" ss:Size="16" ss:Color="#FFFFFF"/><Interior ss:Color="#1D4ED8" ss:Pattern="Solid"/></Style><Style ss:ID="h"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#334155" ss:Pattern="Solid"/></Style><Style ss:ID="a"><Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/></Style><Style ss:ID="k"><Font ss:Bold="1" ss:Color="#1D4ED8"/><Interior ss:Color="#DBEAFE" ss:Pattern="Solid"/></Style></Styles><Worksheet ss:Name="Ringkasan"><Table><Row ss:Height="30"><Cell ss:MergeAcross="3" ss:StyleID="t"><Data ss:Type="String">LAPORAN PRESENSI</Data></Cell></Row><Row><Cell><Data ss:Type="String">Periode</Data></Cell><Cell ss:MergeAcross="2"><Data ss:Type="String">'.$report['from'].' s/d '.$report['to'].'</Data></Cell></Row>';
        foreach($groups as $name=>$r)$xml.='<Row><Cell ss:StyleID="k"><Data ss:Type="String">Kelas '.self::cell($name).'</Data></Cell><Cell ss:StyleID="k"><Data ss:Type="Number">'.(int)$r['summary']['hadir'].' hadir</Data></Cell><Cell ss:StyleID="k"><Data ss:Type="Number">'.(int)$r['summary']['pulang'].' pulang</Data></Cell><Cell ss:StyleID="k"><Data ss:Type="Number">'.(int)$r['summary']['telat'].' telat / '.(int)$r['summary']['izin'].' izin</Data></Cell></Row>';
        $xml.='</Table></Worksheet>';
        foreach($groups as $name=>$r){$safe=substr(preg_replace('/[^A-Za-z0-9 _-]/','',(string)$name)?:'Kelas',0,28);$xml.='<Worksheet ss:Name="'.self::cell($safe).'"><Table><Column ss:Width="90"/><Column ss:Width="180"/><Column ss:Width="100"/><Column ss:Width="100"/><Column ss:Width="100"/><Column ss:Width="110"/><Column ss:Width="110"/><Row ss:Height="30"><Cell ss:MergeAcross="7" ss:StyleID="t"><Data ss:Type="String">KELAS '.self::cell($name).'</Data></Cell></Row><Row><Cell><Data ss:Type="String">Periode</Data></Cell><Cell ss:MergeAcross="6"><Data ss:Type="String">'.$r['from'].' s/d '.$r['to'].'</Data></Cell></Row>';
            foreach(AttendanceReport::rows($r) as $i=>$row){$xml.='<Row'.($i===0?' ss:StyleID="h"':($i%2===0?' ss:StyleID="a"':'')).'>';foreach($row as $v)$xml.='<Cell><Data ss:Type="String">'.self::cell($v).'</Data></Cell>';$xml.='</Row>';}$xml.='</Table></Worksheet>';}
        $xml.='<Worksheet ss:Name="Izin-Sakit"><Table><Row ss:StyleID="h"><Cell><Data ss:Type="String">Kelas</Data></Cell><Cell><Data ss:Type="String">Nama</Data></Cell><Cell><Data ss:Type="String">Jenis</Data></Cell><Cell><Data ss:Type="String">Mulai</Data></Cell><Cell><Data ss:Type="String">Selesai</Data></Cell><Cell><Data ss:Type="String">Status</Data></Cell><Cell><Data ss:Type="String">Alasan</Data></Cell></Row>';
        foreach($groups as $name=>$r)foreach($r['izin'] as $z){$xml.='<Row>';foreach([$name,$z['nama_lengkap'],$z['jenis'],$z['tanggal_mulai'],$z['tanggal_selesai'],$z['status'],$z['alasan']] as $v)$xml.='<Cell><Data ss:Type="String">'.self::cell($v).'</Data></Cell>';$xml.='</Row>';}$xml.='</Table></Worksheet></Workbook>';
        self::out($xml,'application/xml','laporan-presensi-'.$report['from'].'-'.$report['to'].'.xml');
    }
    private static function ascii(string $s):string{$s=function_exists('iconv')?(string)@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s):$s;return preg_replace('/[^\x20-\x7E\x80-\xFF]/','?',$s)??$s;}
    private static function esc(string $s):string{return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);}
    public static function pdf(array $r): void
    {
        require_once __DIR__ . '/fpdf/fpdf.php';
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetTitle('Laporan Presensi ' . $r['from'] . ' - ' . $r['to']);
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'LAPORAN PRESENSI', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, 'Periode: ' . $r['from'] . ' sampai ' . $r['to'], 0, 1, 'C');
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(29, 78, 216); $pdf->SetTextColor(255, 255, 255);
        foreach (['Tanggal'=>25,'Nama'=>55,'Kelas'=>35,'Masuk'=>27,'Pulang'=>27,'Status'=>30,'Telat'=>22] as $head=>$width) $pdf->Cell($width, 8, $head, 1, 0, 'C', true);
        $pdf->Ln(); $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(30, 41, 59);
        foreach ($r['rows'] as $i=>$row) {
            if ($i % 2 === 0) { $pdf->SetFillColor(239, 246, 255); $fill = true; } else { $fill = false; }
            $pdf->Cell(25, 7, (string)$row['tanggal'], 1, 0, 'L', $fill);
            $pdf->Cell(55, 7, substr((string)$row['nama_lengkap'], 0, 29), 1, 0, 'L', $fill);
            $pdf->Cell(35, 7, substr((string)($row['nama_kelas'] ?? '-'), 0, 18), 1, 0, 'L', $fill);
            $pdf->Cell(27, 7, $row['waktu_masuk'] ? date('H:i', strtotime((string)$row['waktu_masuk'])) : '-', 1, 0, 'C', $fill);
            $pdf->Cell(27, 7, $row['waktu_pulang'] ? date('H:i', strtotime((string)$row['waktu_pulang'])) : '-', 1, 0, 'C', $fill);
            $pdf->Cell(30, 7, (string)($row['status_masuk'] ?? 'hadir'), 1, 0, 'C', $fill);
            $pdf->Cell(22, 7, (string)((int)($row['late_minutes'] ?? 0)), 1, 0, 'C', $fill); $pdf->Ln();
        }
        $pdf->Ln(5); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(0, 7, 'Ringkasan: Hadir ' . $r['summary']['hadir'] . ' | Pulang ' . $r['summary']['pulang'] . ' | Terlambat ' . $r['summary']['telat'] . ' | Izin/Sakit ' . $r['summary']['izin'], 0, 1);
        $data = $pdf->Output('S'); self::out($data, 'application/pdf', 'laporan-presensi-' . $r['from'] . '-' . $r['to'] . '.pdf');
    }

    private static function legacyPdf(array $r): void
    {
        $lines = ['LAPORAN PRESENSI', 'Periode: ' . $r['from'] . ' sampai ' . $r['to'], '', 'RINGKASAN', 'Hadir: ' . $r['summary']['hadir'] . ' | Pulang: ' . $r['summary']['pulang'] . ' | Terlambat: ' . $r['summary']['telat'] . ' | Izin/Sakit: ' . $r['summary']['izin'], '', 'Tanggal | Nama | Kelas | Masuk | Pulang | Status'];
        foreach ($r['rows'] as $x) $lines[] = implode(' | ', [$x['tanggal'], $x['nama_lengkap'], $x['nama_kelas'] ?? '-', $x['waktu_masuk'] ? date('H:i', strtotime((string)$x['waktu_masuk'])) : '-', $x['waktu_pulang'] ? date('H:i', strtotime((string)$x['waktu_pulang'])) : '-', $x['status_masuk'] ?? 'hadir']);
        if (!$r['rows']) $lines[] = 'Tidak ada data.';
        $pages = array_chunk($lines, 45); $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']; $kids = []; $id = 4;
        foreach ($pages as $chunk) { $page = $id++; $streamId = $id++; $stream = "BT\n/F1 10 Tf\n50 790 Td\n"; foreach ($chunk as $i => $line) $stream .= '/F1 ' . ($i === 0 ? 16 : 9) . ' Tf\n(' . self::esc(self::ascii((string)$line)) . ") Tj\n0 -15 Td\n"; $stream .= "ET\n"; $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $streamId . ' 0 R >>'; $objects[$streamId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream"; $kids[] = $page . ' 0 R'; }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>'; ksort($objects, SORT_NUMERIC); $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0]; foreach ($objects as $n => $o) { $offsets[$n] = strlen($pdf); $pdf .= $n . " 0 obj\n" . $o . "\nendobj\n"; } $xref = strlen($pdf); $size = max(array_keys($objects)) + 1; $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n"; for ($i = 1; $i < $size; $i++) $pdf .= sprintf('%010d 00000 n \n', $offsets[$i] ?? 0); $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n"; self::out($pdf, 'application/pdf', 'laporan-presensi-' . $r['from'] . '-' . $r['to'] . '.pdf');
    }
}
