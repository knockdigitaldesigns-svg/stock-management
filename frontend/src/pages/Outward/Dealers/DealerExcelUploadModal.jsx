import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Download, CheckCircle } from 'lucide-react';
import api from '../../../services/api';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import BulkUploadValidationErrors from '../../../components/BulkUploadValidationErrors/BulkUploadValidationErrors';
import { normalizeBulkUploadErrors } from '../../../utils/bulkUploadErrors';

export const BULK_ALLOCATION_COLUMNS = [
    { key: 'dealer_name', header: 'Dealer Name', required: true },
    { key: 'allocation_type', header: 'Allocation Type', required: true },
    { key: 'device_date', header: 'Device Date', required: false },
    { key: 'imei_no', header: 'IMEI No', required: false },
    { key: 'device_amount', header: 'Device Amount', required: false },
    { key: 'device_notes', header: 'Device Notes', required: false },
    { key: 'sim_date', header: 'SIM Date', required: false },
    { key: 'sim_number', header: 'SIM Number', required: false },
    { key: 'sim_amount', header: 'SIM Amount', required: false },
    { key: 'sim_notes', header: 'SIM Notes', required: false },
    { key: 'software', header: 'Software', required: false },
    { key: 'total_amount', header: 'Total Amount', required: false },
    { key: 'amount_paid', header: 'Amount Paid', required: false },
    { key: 'payment_mode', header: 'Payment Mode', required: false },
    { key: 'transaction_id', header: 'Transaction ID', required: false }
];

const normalizeHeader = (str) =>
    String(str || '')
        .toLowerCase()
        .trim()
        .replace(/\s+/g, ' ');

const DealerExcelUploadModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [file, setFile] = useState(null);
    const [errors, setErrors] = useState([]);
    const [loading, setLoading] = useState(false);
    const [successMsg, setSuccessMsg] = useState('');

    const downloadTemplate = () => {
        const headers = BULK_ALLOCATION_COLUMNS.map((c) => c.header);
        const sampleData = [
            {
                'Dealer Name': 'ABC Motors',
                'Allocation Type': 'device',
                'Device Date': '03-09-2026',
                'IMEI No': '864294050123456',
                'Device Amount': '2500',
                'Device Notes': 'GPS Device',
                'SIM Date': '',
                'SIM Number': '',
                'SIM Amount': '',
                'SIM Notes': '',
                'Software': 'Eagle India',
                'Total Amount': '2500',
                'Amount Paid': '2500',
                'Payment Mode': 'UPI',
                'Transaction ID': 'UPI98765432'
            },
            {
                'Dealer Name': 'Sri Auto',
                'Allocation Type': 'both',
                'Device Date': '02-09-2026',
                'IMEI No': '864294050123457',
                'Device Amount': '3000',
                'Device Notes': 'GPS Device',
                'SIM Date': '02-09-2026',
                'SIM Number': '8991101234567890123',
                'SIM Amount': '500',
                'SIM Notes': 'Airtel SIM',
                'Software': 'Tracoo',
                'Total Amount': '3500',
                'Amount Paid': '1000',
                'Payment Mode': 'Bank Transfer',
                'Transaction ID': 'TXN123456'
            },
            {
                'Dealer Name': 'Kumar Motors',
                'Allocation Type': 'sim',
                'Device Date': '',
                'IMEI No': '',
                'Device Amount': '',
                'Device Notes': '',
                'SIM Date': '01-09-2026',
                'SIM Number': '8991101234567890124',
                'SIM Amount': '600',
                'SIM Notes': 'Jio SIM',
                'Software': 'Navilap',
                'Total Amount': '600',
                'Amount Paid': '0',
                'Payment Mode': '',
                'Transaction ID': ''
            }
        ];

        const ws = XLSX.utils.json_to_sheet(sampleData, { header: headers });
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Stock Allocation');
        XLSX.writeFile(wb, 'Dealer_Bulk_Stock_Allocation_Template.xlsx');
    };

    const handleFileChange = (e) => {
        const selectedFile = e.target.files[0];
        if (!selectedFile) return;

        if (!selectedFile.name.toLowerCase().endsWith('.xlsx')) {
            const msg = 'Only .xlsx files are allowed.';
            setFile(null);
            setErrors([msg]);
            setSuccessMsg('');
            return;
        }

        if (selectedFile.size === 0) {
            const msg = 'Excel file is empty.';
            setFile(null);
            setErrors([msg]);
            setSuccessMsg('');
            return;
        }

        setFile(selectedFile);
        setErrors([]);
        setSuccessMsg('');
    };

    const processExcel = async () => {
        if (!file) {
            const msg = 'Please select an Excel file.';
            setErrors([msg]);
            return;
        }

        if (!file.name.toLowerCase().endsWith('.xlsx')) {
            const msg = 'Only .xlsx files are allowed.';
            setErrors([msg]);
            return;
        }

        if (file.size === 0) {
            const msg = 'Excel file is empty.';
            setErrors([msg]);
            return;
        }

        setLoading(true);
        setErrors([]);
        setSuccessMsg('');

        let workbook;
        try {
            const arrayBuffer = await file.arrayBuffer();
            workbook = XLSX.read(arrayBuffer, {
                type: 'array'
            });
        } catch (err) {
            const msg = err.message || 'Invalid or corrupted Excel file.';
            setErrors([msg]);
            setLoading(false);
            return;
        }

        if (!workbook.SheetNames || workbook.SheetNames.length === 0) {
            const msg = 'Excel workbook contains no worksheets.';
            setErrors([msg]);
            setLoading(false);
            return;
        }

        const firstSheetName = workbook.SheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];

        if (!worksheet) {
            const msg = 'Unable to read the first Excel worksheet.';
            setErrors([msg]);
            setLoading(false);
            return;
        }

        const rawRows = XLSX.utils.sheet_to_json(worksheet, {
            defval: '',
            raw: true
        });

        if (!rawRows || rawRows.length === 0) {
            const msg = 'Excel file is empty.';
            setErrors([msg]);
            setLoading(false);
            return;
        }

        const rawKeys = Object.keys(rawRows[0] || {});
        const headerMap = {};
        rawKeys.forEach((k) => {
            headerMap[normalizeHeader(k)] = k;
        });

        const missingRequired = BULK_ALLOCATION_COLUMNS.filter(
            (c) => c.required && !headerMap[normalizeHeader(c.header)]
        );
        if (missingRequired.length > 0) {
            setErrors(missingRequired.map((column) => `Required column '${column.header}' is missing.`));
            setLoading(false);
            return;
        }

        try {
            const formData = new FormData();
            formData.append('file', file);
            const response = await api.post('/dealers/import.php', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            if (response.data.success) {
                setSuccessMsg(response.data.message || 'Bulk stock allocation completed successfully.');
                setTimeout(() => {
                    onSuccess();
                }, 1000);
            } else {
                const errList = normalizeBulkUploadErrors(response.data, 'Failed to upload stock allocations.');
                setErrors(errList);
            }
        } catch (error) {
            const errList = normalizeBulkUploadErrors(error.response?.data, error.message || 'Error uploading stock allocations.');
            setErrors(errList);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '680px' }}>
                <div className="modal-header">
                    <h3>Bulk Device / SIM Stock Allocation via Excel</h3>
                    <button className="close-btn" onClick={onClose} disabled={loading}>
                        &times;
                    </button>
                </div>

                <div className="modal-body">
                    <div
                        className="info-box"
                        style={{
                            backgroundColor: '#f8fafc',
                            padding: '1rem',
                            borderRadius: '0.5rem',
                            marginBottom: '1.5rem'
                        }}
                    >
                        <p style={{ margin: '0 0 0.5rem 0', fontWeight: '500' }}>Instructions:</p>
                        <ul
                            style={{
                                margin: '0',
                                paddingLeft: '1.5rem',
                                fontSize: '0.875rem',
                                color: 'var(--text-secondary)'
                            }}
                        >
                            <li>
                                This upload is for <strong>bulk stock allocation to existing dealers</strong>. Dealers must already exist in Dealer Management.
                            </li>
                            <li>
                                Required header columns:{' '}
                                <strong>Dealer Name | Allocation Type</strong> (values: <em>device / sim / both</em>).
                            </li>
                            <li>
                                If allocating devices: <strong>Device Date</strong> and <strong>IMEI No</strong> are required.
                            </li>
                            <li>
                                If allocating SIMs: <strong>SIM Date</strong> and <strong>SIM Number</strong> are required.
                            </li>
                            <li>
                                Optional columns: <strong>Device Amount | Device Notes | SIM Amount | SIM Notes | Software | Total Amount | Amount Paid | Payment Mode | Transaction ID</strong>.
                            </li>
                            <li>
                                For <strong>Not Willing</strong> dealers, Device Amount and/or SIM Amount are mandatory and Total Amount must be &gt; 0.
                            </li>
                            <li>
                                <strong>Payment Mode</strong> (Cash, UPI, Bank Transfer, Card, Other) is required if payment is made. <strong>Transaction ID</strong> is required for non-cash payments.
                            </li>
                            <li>If any row fails validation, the entire Excel upload is rejected without partial allocations.</li>
                        </ul>
                        <button
                            className="btn btn-outline"
                            onClick={downloadTemplate}
                            style={{ marginTop: '1rem', fontSize: '0.875rem' }}
                        >
                            <Download size={14} /> Download Sample Allocation Template
                        </button>
                    </div>

                    <div className="form-group">
                        <label className="form-label">Select Excel File (.xlsx)</label>
                        <input
                            type="file"
                            accept=".xlsx"
                            className="form-control"
                            onChange={handleFileChange}
                            disabled={loading}
                        />
                    </div>

                    <BulkUploadValidationErrors errors={errors} />

                    {successMsg && (
                        <div
                            className="alert badge-success"
                            style={{
                                padding: '1rem',
                                display: 'flex',
                                alignItems: 'center',
                                gap: '0.5rem'
                            }}
                        >
                            <CheckCircle size={16} /> {successMsg}
                        </div>
                    )}
                </div>

                <div className="modal-footer">
                    <button className="btn btn-outline" onClick={onClose} disabled={loading}>
                        Cancel
                    </button>
                    <button
                        className="btn btn-primary"
                        onClick={processExcel}
                        disabled={!file || loading}
                    >
                        {loading ? 'Processing...' : 'Upload Data'}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default DealerExcelUploadModal;
