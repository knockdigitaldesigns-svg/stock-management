import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Download, CheckCircle } from 'lucide-react';
import api from '../../../services/api';
import { parseDate, isFutureDate } from '../../../utils/date';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import { showGlobalError } from '../../../context/ErrorContext';

export const DEALER_COLUMNS = [
    { key: 'dealer_name', header: 'Dealer Name', required: true },
    { key: 'mobile_no', header: 'Mobile No', required: true },
    { key: 'location', header: 'Location', required: true },
    { key: 'enrolled_date', header: 'Enrolled Date', required: true },
    { key: 'installation_status', header: 'Installation Status', required: true },
    { key: 'notes', header: 'Notes', required: false },
    { key: 'software', header: 'Software', required: false }
];

const normalizeHeader = (str) =>
    String(str || '')
        .toLowerCase()
        .trim()
        .replace(/\s+/g, ' ');

const normalizeStatus = (val) => {
    const clean = String(val || '').toLowerCase().trim().replace(/\s+/g, ' ');
    if (clean === 'onsite') return 'Onsite';
    if (clean === 'offsite') return 'Offsite';
    if (clean === 'not willing') return 'Not Willing';
    return null;
};

const DealerExcelUploadModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [file, setFile] = useState(null);
    const [errors, setErrors] = useState([]);
    const [loading, setLoading] = useState(false);
    const [successMsg, setSuccessMsg] = useState('');

    const downloadTemplate = () => {
        const headers = DEALER_COLUMNS.map((c) => c.header);
        const sampleData = [
            {
                'Dealer Name': 'ABC Motors',
                'Mobile No': '9876543210',
                'Location': 'Coimbatore',
                'Enrolled Date': '03-09-2026',
                'Installation Status': 'Onsite',
                'Notes': 'Test dealer',
                'Software': 'Eagle India'
            },
            {
                'Dealer Name': 'Sri Auto',
                'Mobile No': '9876543211',
                'Location': 'Madurai',
                'Enrolled Date': '02-09-2026',
                'Installation Status': 'Offsite',
                'Notes': 'Test dealer',
                'Software': 'Tracoo'
            },
            {
                'Dealer Name': 'Kumar Motors',
                'Mobile No': '9876543212',
                'Location': 'Chennai',
                'Enrolled Date': '01-09-2026',
                'Installation Status': 'Not Willing',
                'Notes': 'Test dealer',
                'Software': 'Navilap'
            }
        ];

        const ws = XLSX.utils.json_to_sheet(sampleData, { header: headers });
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Dealers');
        XLSX.writeFile(wb, 'Dealer_Upload_Template.xlsx');
    };

    const handleFileChange = (e) => {
        const selectedFile = e.target.files[0];
        if (!selectedFile) return;

        if (!selectedFile.name.toLowerCase().endsWith('.xlsx')) {
            const msg = 'Only .xlsx files are allowed.';
            setFile(null);
            setErrors([msg]);
            showGlobalError(msg);
            setSuccessMsg('');
            return;
        }

        if (selectedFile.size === 0) {
            const msg = 'Excel file is empty.';
            setFile(null);
            setErrors([msg]);
            showGlobalError(msg);
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
            showGlobalError(msg);
            return;
        }

        if (!file.name.toLowerCase().endsWith('.xlsx')) {
            const msg = 'Only .xlsx files are allowed.';
            setErrors([msg]);
            showGlobalError(msg);
            return;
        }

        if (file.size === 0) {
            const msg = 'Excel file is empty.';
            setErrors([msg]);
            showGlobalError(msg);
            return;
        }

        setLoading(true);
        setErrors([]);
        setSuccessMsg('');

        let workbook;
        try {
            const arrayBuffer = await file.arrayBuffer();
            workbook = XLSX.read(arrayBuffer, {
                type: 'array',
                cellDates: true
            });
        } catch (err) {
            const msg = 'Invalid or corrupted Excel file.';
            setErrors([msg]);
            showGlobalError(msg);
            setLoading(false);
            return;
        }

        if (!workbook.SheetNames || workbook.SheetNames.length === 0) {
            const msg = 'Excel workbook contains no worksheets.';
            setErrors([msg]);
            showGlobalError(msg);
            setLoading(false);
            return;
        }

        const firstSheetName = workbook.SheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];

        if (!worksheet) {
            const msg = 'Unable to read the first Excel worksheet.';
            setErrors([msg]);
            showGlobalError(msg);
            setLoading(false);
            return;
        }

        const rawRows = XLSX.utils.sheet_to_json(worksheet, {
            defval: '',
            raw: false
        });

        if (!rawRows || rawRows.length === 0) {
            const msg = 'Excel file is empty.';
            setErrors([msg]);
            showGlobalError(msg);
            setLoading(false);
            return;
        }

        const rawKeys = Object.keys(rawRows[0] || {});
        const headerMap = {};
        rawKeys.forEach((k) => {
            headerMap[normalizeHeader(k)] = k;
        });

        const missingRequired = DEALER_COLUMNS.filter(
            (c) => c.required && !headerMap[normalizeHeader(c.header)]
        );
        if (missingRequired.length > 0) {
            const msg = `Required column '${missingRequired[0].header}' is missing.`;
            setErrors([msg]);
            showGlobalError(msg);
            setLoading(false);
            return;
        }

        const clientErrors = [];
        const seenNames = new Set();
        const seenMobiles = new Set();

        rawRows.forEach((row, idx) => {
            const rowNum = idx + 2;

            const dealerName = String(row[headerMap[normalizeHeader('Dealer Name')]] || '').trim();
            const mobileNo = String(row[headerMap[normalizeHeader('Mobile No')]] || '').trim();
            const location = String(row[headerMap[normalizeHeader('Location')]] || '').trim();
            const enrolledDateRaw = String(row[headerMap[normalizeHeader('Enrolled Date')]] || '').trim();
            const statusRaw = String(row[headerMap[normalizeHeader('Installation Status')]] || '').trim();

            if (!dealerName) {
                clientErrors.push(`Row ${rowNum}: Dealer Name is required.`);
            }

            const normalizedMobile = mobileNo.replace(/\D/g, '');
            if (!mobileNo) {
                clientErrors.push(`Row ${rowNum}: Mobile No is required.`);
            } else if (
                !/^[0-9+\-\s()]{10,15}$/.test(mobileNo) ||
                normalizedMobile.length < 10 ||
                normalizedMobile.length > 15
            ) {
                clientErrors.push(`Row ${rowNum}: Mobile No is invalid.`);
            }

            if (!location) {
                clientErrors.push(`Row ${rowNum}: Location is required.`);
            }

            if (!enrolledDateRaw) {
                clientErrors.push(`Row ${rowNum}: Enrolled Date is required.`);
            } else {
                const parsed = parseDate(enrolledDateRaw);
                if (!parsed) {
                    clientErrors.push(`Row ${rowNum}: Invalid Enrolled Date.`);
                } else if (isFutureDate(parsed)) {
                    clientErrors.push(`Row ${rowNum}: Future dates are not allowed.`);
                }
            }

            if (!statusRaw) {
                clientErrors.push(`Row ${rowNum}: Installation Status is required.`);
            } else if (!normalizeStatus(statusRaw)) {
                clientErrors.push(`Row ${rowNum}: Invalid Installation Status.`);
            }

            const normName = dealerName.toLowerCase().replace(/\s+/g, ' ');
            if (normName) {
                if (seenNames.has(normName)) {
                    clientErrors.push(`Row ${rowNum}: Duplicate Dealer Name in uploaded Excel.`);
                }
                seenNames.add(normName);
            }

            if (normalizedMobile) {
                if (seenMobiles.has(normalizedMobile)) {
                    clientErrors.push(`Row ${rowNum}: Duplicate Mobile No in uploaded Excel.`);
                }
                seenMobiles.add(normalizedMobile);
            }
        });

        if (clientErrors.length > 0) {
            const uniqueErrors = Array.from(new Set(clientErrors));
            setErrors(uniqueErrors);
            showGlobalError(uniqueErrors, 'Import Validation Errors');
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
                setSuccessMsg(response.data.message || 'Dealers uploaded successfully.');
                setTimeout(() => {
                    onSuccess();
                }, 1000);
            } else {
                const backendErrors =
                    response.data?.data?.errors ||
                    response.data?.errors ||
                    [response.data.message || 'Failed to upload dealers'];
                const errList = Array.isArray(backendErrors) ? backendErrors : [backendErrors];
                setErrors(errList);
                showGlobalError(errList, 'Upload Failed');
            }
        } catch (error) {
            const backendErrors =
                error.response?.data?.data?.errors ||
                error.response?.data?.errors ||
                [error.response?.data?.message || error.message || 'Error uploading dealers'];
            const errList = Array.isArray(backendErrors) ? backendErrors : [backendErrors];
            setErrors(errList);
            showGlobalError(errList, 'Upload Error');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '620px' }}>
                <div className="modal-header">
                    <h3>Upload Dealers via Excel</h3>
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
                                Required columns:{' '}
                                <strong>Dealer Name | Mobile No | Location | Enrolled Date | Installation Status</strong>
                            </li>
                            <li>
                                <strong>Notes</strong> is optional.
                            </li>
                            <li>
                                <strong>Software</strong> is optional and must match a supported software value when provided.
                            </li>
                            <li>
                                Installation Status must be: <strong>Onsite / Offsite / Not Willing</strong>
                            </li>
                            <li>Dates must not be future dates.</li>
                        </ul>
                        <button
                            className="btn btn-outline"
                            onClick={downloadTemplate}
                            style={{ marginTop: '1rem', fontSize: '0.875rem' }}
                        >
                            <Download size={14} /> Download Sample Template
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
