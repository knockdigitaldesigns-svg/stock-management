<?php
function normalizeExcelHeader($value) { return strtolower(trim(preg_replace('/\s+/', ' ', (string) $value))); }
function columnIndexFromReference($reference) { preg_match('/^[A-Z]+/i', (string) $reference, $m); $letters = strtoupper($m[0] ?? ''); $index = 0; for ($i = 0; $i < strlen($letters); $i++) $index = $index * 26 + ord($letters[$i]) - 64; return $index - 1; }
function excelNodes($node, $name) { return $node->xpath('.//*[local-name()="' . $name . '"]') ?: []; }
function convertCellValue($cell, $sharedStrings) {
    $type = (string) ($cell['t'] ?? '');
    if ($type === 'inlineStr') return trim(implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]') ?: [])));
    if ($type === 's') {
        $sharedIndex = (int) ($cell->v ?? 0);
        return trim((string) ($sharedStrings[$sharedIndex] ?? ''));
    }
    if ($type === 'b') {
        return ((string) ($cell->v ?? '')) === '1' ? 'TRUE' : 'FALSE';
    }
    $val = trim((string) ($cell->v ?? ''));
    if ($val === '') {
        $tNodes = $cell->xpath('.//*[local-name()="t"]');
        if (!empty($tNodes)) {
            return trim(implode('', array_map('strval', $tNodes)));
        }
    }
    return $val;
}
function excelSerialDateToIso($value) { if (!is_numeric($value) || (float) $value < 1) return ''; $date = DateTimeImmutable::createFromFormat('!Y-m-d', '1899-12-30'); return $date ? $date->modify('+' . (int) floor((float) $value) . ' days')->format('Y-m-d') : ''; }
function parseXlsxRows($filePath, $requiredHeaders, $optionalHeaders = []) {
    if (!class_exists('ZipArchive')) return ['error' => 'XLSX support is not available on the server.'];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return ['error' => 'Invalid or corrupted Excel file.'];
    $load = function ($path) use ($zip) { $xml = $zip->getFromName($path); return $xml === false ? false : simplexml_load_string($xml); };
    $sharedStrings = [];
    $shared = $load('xl/sharedStrings.xml');
    if ($shared !== false) foreach ($shared->xpath('//*[local-name()="si"]') ?: [] as $item) $sharedStrings[] = trim(implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]') ?: [])));
    $workbook = $load('xl/workbook.xml'); $rels = $load('xl/_rels/workbook.xml.rels');
    if ($workbook === false || $rels === false) { $zip->close(); return ['error' => 'Invalid or corrupted Excel workbook.']; }
    $relationships = [];
    $worksheetTargets = [];
    foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
        $id = (string) $relationship['Id'];
        $target = (string) $relationship['Target'];
        $type = (string) $relationship['Type'];
        $relationships[$id] = $target;
        if (substr($type, -9) === 'worksheet' || strpos($type, '/worksheet') !== false) {
            $worksheetTargets[] = $target;
        }
    }
    $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [];
    if (!$sheets) { $zip->close(); return ['error' => 'Excel workbook contains no worksheets.']; }
    $firstSheet = $sheets[0];
    $relId = (string) ($firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
    if (!$relId) $relId = (string) ($firstSheet->attributes('http://purl.oclc.org/ooxml/officeDocument/relationships')['id'] ?? '');
    if (!$relId) {
        foreach ($firstSheet->attributes() as $attrName => $attrVal) {
            if (strtolower($attrName) === 'id' || strtolower($attrName) === 'rid') {
                $relId = (string) $attrVal;
                break;
            }
        }
    }
    $target = $relationships[$relId] ?? ($worksheetTargets[0] ?? '');
    if (!$target) { $zip->close(); return ['error' => 'Invalid or corrupted Excel workbook: first worksheet relationship is missing.']; }
    $cleanTarget = ltrim(str_replace('\\', '/', $target), '/');
    $candidatePaths = [];
    if (strpos($cleanTarget, 'xl/') === 0) {
        $candidatePaths[] = $cleanTarget;
    } else {
        $candidatePaths[] = 'xl/' . $cleanTarget;
    }
    $candidatePaths[] = $cleanTarget;
    $resolvedCandidates = [];
    foreach ($candidatePaths as $p) {
        $parts = [];
        foreach (explode('/', $p) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($parts);
            else $parts[] = $part;
        }
        $resolved = implode('/', $parts);
        if ($resolved !== '') $resolvedCandidates[] = $resolved;
    }
    $candidatePaths = array_unique(array_merge($candidatePaths, $resolvedCandidates));
    $worksheet = false;
    foreach ($candidatePaths as $candidatePath) {
        $worksheet = $load($candidatePath);
        if ($worksheet !== false) break;
    }
    if ($worksheet === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $entryName)) {
                $worksheet = $load($entryName);
                if ($worksheet !== false) break;
            }
        }
    }
    if ($worksheet === false) { $zip->close(); return ['error' => 'Unable to read the first Excel worksheet.']; }
    $rows = [];
    foreach ($worksheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
        $values = [];
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            $index = $ref ? columnIndexFromReference($ref) : count($values);
            while (count($values) < $index) $values[] = '';
            $values[$index] = convertCellValue($cell, $sharedStrings);
        }
        $hasContent = false;
        foreach ($values as $v) {
            if (trim((string) $v) !== '') {
                $hasContent = true;
                break;
            }
        }
        if ($hasContent) {
            $rows[] = $values;
        }
    }
    $zip->close();
    if (empty($rows)) return ['error' => 'Excel file is empty.'];
    $headers = [];
    foreach ($rows[0] as $index => $header) {
        $norm = normalizeExcelHeader($header);
        if ($norm !== '') $headers[$norm] = $index;
    }
    foreach ($requiredHeaders as $header) {
        if (!array_key_exists(normalizeExcelHeader($header), $headers)) {
            return ['error' => "Required column '$header' is missing."];
        }
    }
    $dataRows = array_slice($rows, 1);
    if (empty($dataRows)) return ['error' => 'Excel file contains no data rows.', 'rows' => []];
    $mappedRows = [];
    foreach ($dataRows as $row) {
        $mapped = [];
        foreach (array_merge($requiredHeaders, $optionalHeaders) as $header) {
            $index = $headers[normalizeExcelHeader($header)] ?? null;
            $mapped[$header] = ($index === null || !isset($row[$index])) ? '' : trim((string) $row[$index]);
        }
        $mappedRows[] = $mapped;
    }
    return ['rows' => $mappedRows];
}
?>
