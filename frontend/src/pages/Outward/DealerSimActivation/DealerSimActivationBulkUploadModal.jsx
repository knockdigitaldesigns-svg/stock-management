import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Download } from 'lucide-react';
import api from '../../../services/api';
import Modal from '../../../components/Modal/Modal';
import { parseDate } from '../../../utils/date';

const expectedColumns = ['SIM No', 'Dealer Name', 'Activation Date', 'Validity'];
const normalizeHeader = (value) => String(value ?? '').trim().toLowerCase();

const activationDateToIso = (value) => {
    if (value === '' || value === null || value === undefined) return '';
    if (value instanceof Date || typeof value === 'number') {
        return parseDate(value);
    }

    const text = String(value).trim();
    if (!/^\d{2}-\d{2}-\d{4}$/.test(text)) return text;
    return parseDate(text);
};

const DealerSimActivationBulkUploadModal = ({ onClose, onSuccess }) => {
    const [file, setFile] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const downloadTemplate = () => {
        const worksheet = XLSX.utils.json_to_sheet([
            { 'SIM No': '9876501234', 'Dealer Name': 'Anisha', 'Activation Date': '06-10-2026', Validity: '3 Months' },
            { 'SIM No': '9876501235', 'Dealer Name': 'Raja', 'Activation Date': '06-10-2026', Validity: '6 Months' }
        ], { header: expectedColumns });
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Dealer SIM Activation');
        XLSX.writeFile(workbook, 'Bulk_Dealer_SIM_Activation_Template.xlsx');
    };

    const handleFileChange = (event) => {
        const selectedFile = event.target.files?.[0] || null;
        if (selectedFile && !/\.(xlsx|xls)$/i.test(selectedFile.name)) {
            setFile(null);
            setError('Choose an .xlsx or .xls file.');
            event.target.value = '';
            return;
        }
        setFile(selectedFile);
        setError('');
    };

    const handleUpload = async () => {
        if (!file) {
            setError('Choose an Excel file to upload.');
            return;
        }

        setLoading(true);
        setError('');
        try {
            const buffer = await file.arrayBuffer();
            const workbook = XLSX.read(buffer, { type: 'array', cellDates: false });
            const sheetName = workbook.SheetNames[0];
            if (!sheetName) {
                setError('Excel file is empty.');
                return;
            }

            const worksheet = workbook.Sheets[sheetName];
            const sheetRows = XLSX.utils.sheet_to_json(worksheet, {
                header: 1,
                defval: '',
                raw: true,
                blankrows: true
            });
            if (!sheetRows.length) {
                setError('Excel file is empty.');
                return;
            }

            const headers = sheetRows[0].map(normalizeHeader);
            const columnIndexes = expectedColumns.map((column) => headers.indexOf(normalizeHeader(column)));
            const missingColumns = expectedColumns.filter((_, index) => columnIndexes[index] < 0);
            if (missingColumns.length) {
                setError(`Missing required columns: ${missingColumns.join(', ')}.`);
                return;
            }

            const rows = sheetRows.slice(1).flatMap((cells, index) => {
                if (!cells.some((cell) => String(cell ?? '').trim() !== '')) return [];
                const cellValue = (columnIndex) => cells[columnIndex] ?? '';
                const rawSimNo = cellValue(columnIndexes[0]);
                const simNo = typeof rawSimNo === 'number' && Number.isInteger(rawSimNo)
                    ? String(rawSimNo)
                    : String(rawSimNo).trim();
                const rawActivationDate = cellValue(columnIndexes[2]);
                const activationDate = activationDateToIso(rawActivationDate);

                return [{
                    row_number: index + 2,
                    sim_no: simNo,
                    dealer_name: String(cellValue(columnIndexes[1])).trim(),
                    activation_date: activationDate,
                    validity: String(cellValue(columnIndexes[3])).trim()
                }];
            });

            if (!rows.length) {
                setError('Excel file contains no data rows.');
                return;
            }

            const response = await api.post('/dealers/sim_activation_bulk.php', { rows });
            if (!response.data.success) {
                setError(response.data.message || 'Bulk upload failed.');
                return;
            }
            onSuccess(response.data.data?.activated_count || rows.length);
        } catch (uploadError) {
            setError(uploadError.response?.data?.message || 'Unable to read or upload the Excel file.');
        } finally {
            setLoading(false);
        }
    };

    const closeModal = () => {
        if (!loading) onClose();
    };

    return (
        <Modal
            isOpen
            title="Bulk Dealer SIM Activation"
            onClose={closeModal}
            maxWidth="600px"
            footer={
                <>
                    <button type="button" className="btn btn-outline" onClick={closeModal} disabled={loading}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-primary" onClick={handleUpload} disabled={loading}>
                        {loading ? 'Uploading...' : 'Upload Data'}
                    </button>
                </>
            }
        >
            <div className="info-box" style={{ backgroundColor: '#f8fafc', padding: '1rem', borderRadius: '0.5rem', marginBottom: '1.5rem' }}>
                <p style={{ margin: '0 0 0.5rem 0', fontWeight: '500' }}>Instructions</p>
                <ul style={{ margin: 0, paddingLeft: '1.5rem', fontSize: '0.875rem', color: 'var(--text-secondary)' }}>
                    <li>Use the columns: <strong>SIM No, Dealer Name, Activation Date, Validity</strong>.</li>
                    <li>Enter Activation Date as <strong>DD-MM-YYYY</strong> and choose an existing active validity.</li>
                    <li>Only .xlsx and .xls files are accepted.</li>
                </ul>
                <button
                    type="button"
                    className="btn btn-outline"
                    onClick={downloadTemplate}
                    style={{ marginTop: '1rem', fontSize: '0.875rem' }}
                >
                    <Download size={14} /> Download Sample Template
                </button>
            </div>

            <div className="form-group">
                <label className="form-label" htmlFor="dealer-sim-activation-excel">Choose Excel File</label>
                <input
                    id="dealer-sim-activation-excel"
                    type="file"
                    accept=".xlsx,.xls"
                    className="form-control"
                    onChange={handleFileChange}
                    disabled={loading}
                />
                {file && <p className="text-muted" style={{ marginTop: '0.5rem' }}>Selected file: {file.name}</p>}
            </div>
            {error && <pre className="alert alert-danger" style={{ whiteSpace: 'pre-wrap', marginTop: '1rem' }}>{error}</pre>}
        </Modal>
    );
};

export default DealerSimActivationBulkUploadModal;
