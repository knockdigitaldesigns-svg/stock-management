import * as XLSX from 'xlsx';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import { formatDate, isDateKey } from './date';

const formatExportValue = (value, key) => isDateKey(key) ? formatDate(value) : (value ?? '-');

export const exportToExcel = (data, filename, sheetName = 'Sheet1', options = {}) => {
    const rawData = data || [];
    const exportData = rawData.map((item) => Object.fromEntries(Object.entries(item).map(([key, value]) => [key, formatExportValue(value, key)])));
    const ws = XLSX.utils.json_to_sheet(exportData);
    const wb = XLSX.utils.book_new();

    if (options.combinedSheet) {
        const rows = [[options.combinedSheet.title || filename], []];
        if (options.summary) {
            rows.push([options.summaryTitle || 'Summary'], ['Metric', 'Value']);
            options.summary.forEach((item) => rows.push([item.label, item.value ?? '-']));
            rows.push([]);
        }
        (options.sections || []).forEach((section) => {
            rows.push([section.title || section.name || 'Breakdown']);
            const sectionColumns = section.columns || (section.rows && section.rows[0] ? Object.keys(section.rows[0]).map((key) => ({ header: key, key })) : []);
            if (sectionColumns.length > 0) {
                rows.push(sectionColumns.map((column) => column.header));
                section.rows.forEach((row) => rows.push(sectionColumns.map((column) => row[column.key] ?? '-')));
            }
            rows.push([]);
        });
        rows.push([options.tableTitle || sheetName]);
        const keys = rawData.length > 0 ? Object.keys(rawData[0]) : (options.columns ? options.columns.map(c => typeof c === 'string' ? c : c.header) : []);
        if (keys.length > 0) {
            rows.push(keys);
            exportData.forEach((item) => rows.push(keys.map((key) => item[key] ?? '-')));
        }
        const combinedWorksheet = XLSX.utils.aoa_to_sheet(rows);
        combinedWorksheet['!cols'] = [{ wch: 24 }, { wch: 18 }, { wch: 18 }, { wch: 18 }, { wch: 18 }];
        XLSX.utils.book_append_sheet(wb, combinedWorksheet, sheetName);
        XLSX.writeFile(wb, `${filename}.xlsx`);
        return;
    }

    if (options.summary) {
        const summaryData = options.summary.map((item) => ({
            Metric: item.label,
            Value: item.value ?? '-'
        }));
        XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(summaryData), 'Summary');
    }

    (options.sections || []).forEach((section) => {
        if (section.rows && section.rows.length > 0) {
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(section.rows), section.name || 'Breakdown');
        }
    });

    XLSX.utils.book_append_sheet(wb, ws, sheetName);
    XLSX.writeFile(wb, `${filename}.xlsx`);
};

export const exportToPDF = (data, filename, title, columns, options = {}) => {
    const rawData = data || [];
    const doc = new jsPDF();
    doc.setFontSize(16);
    doc.text(title, 14, 20);
    doc.setFontSize(10);
    doc.text(`Generated on: ${formatDate(new Date())}`, 14, 28);

    let nextY = 35;

    if (options.summary) {
        if (options.summaryTitle) {
            doc.setFontSize(11);
            doc.setFont(undefined, 'bold');
            doc.text(options.summaryTitle, 14, nextY);
            doc.setFont(undefined, 'normal');
            nextY += 4;
        }
        autoTable(doc, {
            head: [options.summary.map((item) => item.label)],
            body: [options.summary.map((item) => item.value ?? '-')],
            startY: nextY,
            styles: { fontSize: 9, halign: 'center' },
            headStyles: { fillColor: [55, 48, 163] },
            bodyStyles: { fontStyle: 'bold', halign: 'center' }
        });
        nextY = doc.lastAutoTable.finalY + 10;
    }

    (options.sections || []).forEach((section) => {
        doc.setFontSize(11);
        doc.setFont(undefined, 'bold');
        doc.text(section.title || section.name || 'Breakdown', 14, nextY);
        doc.setFont(undefined, 'normal');
        nextY += 4;

        const secCols = section.columns || (section.rows && section.rows[0] ? Object.keys(section.rows[0]).map(k => ({ header: k, key: k })) : []);

        if (secCols.length > 0) {
            autoTable(doc, {
                head: [secCols.map((col) => col.header)],
                body: (section.rows || []).map((row) => secCols.map((col) => formatExportValue(row[col.key], col.key))),
                startY: nextY,
                styles: { fontSize: 9 },
                headStyles: { fillColor: [71, 85, 105] },
                margin: { top: 12, bottom: 12 }
            });
            nextY = doc.lastAutoTable.finalY + 10;
        }
    });

    if (options.tableTitle) {
        doc.setFontSize(11);
        doc.setFont(undefined, 'bold');
        doc.text(options.tableTitle, 14, nextY);
        doc.setFont(undefined, 'normal');
        nextY += 4;
    }

    const tableColumn = columns.map((col) => col.header);
    const tableRows = rawData.map((item) => columns.map((col) => formatExportValue(item[col.key], col.key)));

    autoTable(doc, {
        head: [tableColumn],
        body: tableRows,
        startY: nextY,
        styles: { fontSize: 8 },
        headStyles: { fillColor: [55, 48, 163] }
    });

    doc.save(`${filename}.pdf`);
};
