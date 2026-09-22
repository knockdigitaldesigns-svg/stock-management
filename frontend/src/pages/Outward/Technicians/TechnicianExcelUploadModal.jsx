import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Download, AlertCircle, CheckCircle } from 'lucide-react';
import api from '../../../services/api';
import { parseDate, isFutureDate } from '../../../utils/date';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import { showGlobalError } from '../../../context/ErrorContext';

export const TECHNICIAN_ALLOCATION_COLUMNS = [
    'Technician Name', 'Allocation Type', 'Device Date', 'IMEI No',
    'Device Notes', 'SIM Date', 'SIM Number', 'SIM Notes', 'Software'
];

const normalizeHeader = (value) => String(value || '').toLowerCase().trim().replace(/\s+/g, ' ');

const TechnicianExcelUploadModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);
    const [file, setFile] = useState(null);
    const [errors, setErrors] = useState([]);
    const [loading, setLoading] = useState(false);
    const [successMsg, setSuccessMsg] = useState('');

    const triggerErrors = (messages) => {
        const list = Array.isArray(messages) ? messages : [messages];
        setErrors(list);
        showGlobalError(list, 'Excel Upload Validation Error');
    };

    const downloadTemplate = () => {
        const rows = [
            { 'Technician Name': 'Vignesh R', 'Allocation Type': 'device', 'Device Date': '22-09-2026', 'IMEI No': '123456789012345', 'Device Notes': 'Basic Device', 'SIM Date': '', 'SIM Number': '', 'SIM Notes': '', Software: 'Eagle India' },
            { 'Technician Name': 'Vignesh R', 'Allocation Type': 'sim', 'Device Date': '', 'IMEI No': '', 'Device Notes': '', 'SIM Date': '22-09-2026', 'SIM Number': '9876543210', 'SIM Notes': 'Voice SIM', Software: 'Eagle India' },
            { 'Technician Name': 'Vignesh R', 'Allocation Type': 'both', 'Device Date': '22-09-2026', 'IMEI No': '123456789012345', 'Device Notes': 'Basic Device', 'SIM Date': '22-09-2026', 'SIM Number': '9876543210', 'SIM Notes': 'Voice SIM', Software: 'Eagle India' }
        ];
        const ws = XLSX.utils.json_to_sheet(rows, { header: TECHNICIAN_ALLOCATION_COLUMNS });
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Technician Allocations');
        XLSX.writeFile(wb, 'Technician_Stock_Allocation_Template.xlsx');
    };

    const handleFileChange = (event) => {
        const selected = event.target.files[0];
        if (!selected) return;
        if (!selected.name.toLowerCase().endsWith('.xlsx')) return triggerErrors(['Only .xlsx files are allowed.']);
        if (!selected.size) return triggerErrors(['Excel file is empty.']);
        setFile(selected); setErrors([]); setSuccessMsg('');
    };

    const processExcel = async () => {
        if (!file) return triggerErrors(['Please select an Excel file.']);
        setLoading(true); setErrors([]); setSuccessMsg('');
        try {
            const workbook = XLSX.read(new Uint8Array(await file.arrayBuffer()), { type: 'array', cellDates: true });
            const worksheet = workbook.Sheets[workbook.SheetNames[0]];
            const headerRows = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '', raw: true });
            const headerRow = headerRows[0] || [];
            const rows = XLSX.utils.sheet_to_json(worksheet, { defval: '', raw: true });
            if (!rows.length) return triggerErrors(['Excel file is empty.']);
            const headers = headerRow.map(normalizeHeader).filter(Boolean);
            const missing = TECHNICIAN_ALLOCATION_COLUMNS.filter((header) => !headers.includes(normalizeHeader(header)));
            if (missing.length) return triggerErrors([`Required column '${missing[0]}' is missing.`]);

            const validationErrors = [];
            const seen = new Set();
            rows.forEach((row, index) => {
                const rowNum = index + 2;
                const value = (header) => row[Object.keys(row).find((key) => normalizeHeader(key) === normalizeHeader(header))];
                const technician = String(value('Technician Name') || '').trim();
                const type = String(value('Allocation Type') || '').trim().toLowerCase();
                const deviceDate = value('Device Date');
                const simDate = value('SIM Date');
                const imei = String(value('IMEI No') || '').replace(/\D/g, '');
                const simNo = String(value('SIM Number') || '').trim();
                if (!technician) validationErrors.push(`Row ${rowNum}: Technician Name is required.`);
                if (!['device', 'sim', 'both'].includes(type)) validationErrors.push(`Row ${rowNum}: Allocation Type must be device, sim, or both.`);
                if (type === 'device' || type === 'both') {
                    const parsed = parseDate(deviceDate);
                    if (deviceDate === '' || deviceDate === undefined) validationErrors.push(`Row ${rowNum}: Device Date is required.`);
                    else if (!parsed || isFutureDate(parsed)) validationErrors.push(`Row ${rowNum}: Invalid or future Device Date.`);
                    if (!/^\d{15}$/.test(imei)) validationErrors.push(`Row ${rowNum}: IMEI No must contain exactly 15 digits.`);
                }
                if (type === 'sim' || type === 'both') {
                    const parsed = parseDate(simDate);
                    if (simDate === '' || simDate === undefined) validationErrors.push(`Row ${rowNum}: SIM Date is required.`);
                    else if (!parsed || isFutureDate(parsed)) validationErrors.push(`Row ${rowNum}: Invalid or future SIM Date.`);
                    if (!simNo) validationErrors.push(`Row ${rowNum}: SIM Number is required.`);
                }
                [imei ? `device:${imei}` : '', simNo ? `sim:${simNo}` : ''].filter(Boolean).forEach((key) => {
                    if (seen.has(key)) validationErrors.push(`Row ${rowNum}: Duplicate stock item in uploaded Excel.`);
                    seen.add(key);
                });
            });
            if (validationErrors.length) return triggerErrors([...new Set(validationErrors)]);

            const formData = new FormData();
            formData.append('file', file);
            const response = await api.post('/technicians/import.php', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
            if (!response.data.success) throw new Error(response.data.message || 'Failed to upload allocations.');
            setSuccessMsg(response.data.message || 'Stock allocation completed successfully.');
            setTimeout(onSuccess, 1000);
        } catch (error) {
            const data = error.response?.data;
            triggerErrors(data?.data?.errors || data?.errors || [data?.message || error.message || 'Error uploading allocations.']);
        } finally { setLoading(false); }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '620px' }}>
                <div className="modal-header"><h3>Bulk Device / SIM Stock Allocation via Excel</h3><button className="close-btn" onClick={onClose} disabled={loading}>&times;</button></div>
                <div className="modal-body">
                    <div className="info-box" style={{ backgroundColor: '#f8fafc', padding: '1rem', borderRadius: '0.5rem', marginBottom: '1.5rem' }}>
                        <p style={{ margin: '0 0 0.5rem', fontWeight: 500 }}>Instructions:</p>
                        <ul style={{ margin: 0, paddingLeft: '1.5rem', fontSize: '0.875rem', color: 'var(--text-secondary)' }}>
                            <li>Existing technicians only; technicians are not created by this upload.</li>
                            <li>Allocation Type must be <strong>device</strong>, <strong>sim</strong>, or <strong>both</strong>.</li>
                            <li>Device rows require Device Date + IMEI No. SIM rows require SIM Date + SIM Number.</li>
                            <li>Use one common <strong>Software</strong> column. No payment fields are used.</li>
                            <li>Any validation failure rejects the entire upload.</li>
                        </ul>
                        <button className="btn btn-outline" onClick={downloadTemplate} style={{ marginTop: '1rem', fontSize: '0.875rem' }}><Download size={14} /> Download Sample Allocation Template</button>
                    </div>
                    <div className="form-group"><label className="form-label">Select Excel File (.xlsx)</label><input type="file" accept=".xlsx" className="form-control" onChange={handleFileChange} disabled={loading} /></div>
                    {errors.length > 0 && <div className="alert alert-danger" style={{ maxHeight: 240, overflowY: 'auto' }}><div style={{ display: 'flex', gap: '0.5rem', marginBottom: '0.5rem' }}><AlertCircle size={16} /><strong>Validation Errors:</strong></div><ul style={{ margin: 0, paddingLeft: '1.5rem' }}>{errors.map((error, index) => <li key={index}>{error}</li>)}</ul></div>}
                    {successMsg && <div className="alert badge-success" style={{ padding: '1rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}><CheckCircle size={16} /> {successMsg}</div>}
                </div>
                <div className="modal-footer"><button className="btn btn-outline" onClick={onClose} disabled={loading}>Cancel</button><button className="btn btn-primary" onClick={processExcel} disabled={!file || loading}>{loading ? 'Processing...' : 'Upload Data'}</button></div>
            </div>
        </div>
    );
};

export default TechnicianExcelUploadModal;
