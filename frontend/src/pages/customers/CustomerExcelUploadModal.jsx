import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Download, AlertCircle, CheckCircle, Upload } from 'lucide-react';
import api from '../../services/api';
import useModalScrollLock from '../../hooks/useModalScrollLock';
import { showGlobalError } from '../../context/ErrorContext';

export const CUSTOMER_COLUMNS = [
    { key: 'platform', header: 'Platform', required: true },
    { key: 'username', header: 'Username', required: true },
    { key: 'primary_mobile_no', header: 'Primary Mobile No', required: true },
    { key: 'secondary_mobile_no', header: 'Secondary Mobile No', required: false },
    { key: 'email', header: 'Email', required: false },
    { key: 'location', header: 'Location', required: true },
    { key: 'pincode', header: 'Pincode', required: true },
    { key: 'customer_status', header: 'Customer Status', required: false },
    { key: 'vehicle_no', header: 'Vehicle No', required: true },
    { key: 'vehicle_type', header: 'Vehicle Type', required: true },
    { key: 'imei_no', header: 'IMEI No', required: true },
    { key: 'sim_no_1', header: 'SIM No 1', required: true },
    { key: 'sim_no_2', header: 'SIM No 2', required: false },
    { key: 'device_model', header: 'Device Model', required: true },
    { key: 'sim_validity', header: 'SIM Validity', required: true },
    { key: 'installation_person', header: 'Installation Person', required: true },
    { key: 'installation_person_type', header: 'Installation Person Type', required: true },
    { key: 'lead_closure_by', header: 'Lead Closure By', required: true },
    { key: 'installation_date', header: 'Installation Date', required: true },
    { key: 'total_sale_amount', header: 'Total Sale Amount', required: true },
    { key: 'transaction_id', header: 'Transaction ID', required: false },
    { key: 'payment_mode', header: 'Payment Mode', required: false },
    { key: 'device_charge', header: 'Device Charge', required: false },
    { key: 'software_charge', header: 'Software Charge', required: false },
    { key: 'technician_charge', header: 'Technician Charge', required: false },
    { key: 'sim_charge', header: 'SIM Charge', required: false },
    { key: 'courier_charge', header: 'Courier Charge', required: false },
    { key: 'total_amount', header: 'Total Amount', required: false },
    { key: 'amount_paid', header: 'Amount Paid', required: false },
    { key: 'amount_pending', header: 'Amount Pending', required: false },
    { key: 'payment_status', header: 'Payment Status', required: false }
];

const normalizeHeader = (str) =>
    String(str || '')
        .toLowerCase()
        .trim()
        .replace(/[\s_\-.]+/g, '');

const CustomerExcelUploadModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [file, setFile] = useState(null);
    const [errors, setErrors] = useState([]);
    const [loading, setLoading] = useState(false);
    const [successMsg, setSuccessMsg] = useState('');

    const triggerErrors = (errs) => {
        const errList = Array.isArray(errs) ? errs : [errs];
        setErrors(errList);
        showGlobalError(errList, 'Customer Upload Validation Error');
    };

    const downloadTemplate = () => {
        const headers = CUSTOMER_COLUMNS.map((c) => c.header);
        const sampleData = [
            {
                'Platform': 'Fleet Track',
                'Username': 'rahul_sharma',
                'Primary Mobile No': '9876543210',
                'Secondary Mobile No': '9876543211',
                'Email': 'rahul@example.com',
                'Location': 'Chennai',
                'Pincode': '600001',
                'Customer Status': 'Active',
                'Vehicle No': 'TN01AB1234',
                'Vehicle Type': 'Truck',
                'IMEI No': '860123456789012',
                'SIM No 1': '9876543210',
                'SIM No 2': '',
                'Device Model': 'VT02',
                'SIM Validity': '12 Months',
                'Installation Person': 'Ramesh Kumar',
                'Installation Person Type': 'Technician',
                'Lead Closure By': 'Direct',
                'Installation Date': '10-09-2026',
                'Total Sale Amount': 5000,
                'Transaction ID': 'TXN123',
                'Payment Mode': 'UPI',
                'Device Charge': 3000,
                'Software Charge': 1000,
                'Technician Charge': 500,
                'SIM Charge': 300,
                'Courier Charge': 200,
                'Total Amount': 5000,
                'Amount Paid': 5000,
                'Amount Pending': 0,
                'Payment Status': 'Paid'
            },
            {
                'Platform': 'Fleet Track',
                'Username': 'alex_smith',
                'Primary Mobile No': '9876501234',
                'Secondary Mobile No': '',
                'Email': 'alex@example.com',
                'Location': 'Coimbatore',
                'Pincode': '641001',
                'Customer Status': 'Active',
                'Vehicle No': 'TN38CD5678',
                'Vehicle Type': 'Car',
                'IMEI No': '860123456789013',
                'SIM No 1': '9876501234',
                'SIM No 2': '',
                'Device Model': 'VT02',
                'SIM Validity': '12 Months',
                'Installation Person': 'Suresh Onsite Dealer',
                'Installation Person Type': 'Onsite Dealer',
                'Lead Closure By': 'Direct',
                'Installation Date': '10-09-2026',
                'Total Sale Amount': 4500,
                'Transaction ID': '',
                'Payment Mode': '',
                'Device Charge': '',
                'Software Charge': '',
                'Technician Charge': '',
                'SIM Charge': '',
                'Courier Charge': '',
                'Total Amount': '',
                'Amount Paid': '',
                'Amount Pending': '',
                'Payment Status': 'Pending'
            }
        ];

        const ws = XLSX.utils.json_to_sheet(sampleData, { header: headers });
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Customers');
        XLSX.writeFile(wb, 'Customer_Upload_Template.xlsx');
    };

    const handleFileChange = (e) => {
        const selectedFile = e.target.files[0];
        if (!selectedFile) return;

        if (!selectedFile.name.toLowerCase().endsWith('.xlsx')) {
            setFile(null);
            triggerErrors(['Only .xlsx files are allowed.']);
            setSuccessMsg('');
            return;
        }

        if (selectedFile.size === 0) {
            setFile(null);
            triggerErrors(['Excel file is empty.']);
            setSuccessMsg('');
            return;
        }

        setFile(selectedFile);
        setErrors([]);
        setSuccessMsg('');
    };

    const processExcel = async () => {
        if (!file) {
            triggerErrors(['Please select an Excel file.']);
            return;
        }

        if (!file.name.toLowerCase().endsWith('.xlsx')) {
            triggerErrors(['Only .xlsx files are allowed.']);
            return;
        }

        if (file.size === 0) {
            triggerErrors(['Excel file is empty.']);
            return;
        }

        setLoading(true);
        setErrors([]);
        setSuccessMsg('');

        try {
            // Optional: Quick client check for empty sheet or corrupted file before sending
            const arrayBuffer = await file.arrayBuffer();
            const workbook = XLSX.read(arrayBuffer, { type: 'array' });
            if (!workbook.SheetNames || workbook.SheetNames.length === 0) {
                triggerErrors(['Excel workbook contains no worksheets.']);
                setLoading(false);
                return;
            }

            const formData = new FormData();
            formData.append('file', file);
            const response = await api.post('/customers/import.php', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            if (response.data.success) {
                setSuccessMsg(response.data.message || 'Customers uploaded successfully.');
                setErrors([]);
                setTimeout(() => {
                    onSuccess();
                }, 1200);
            } else {
                const backendErrors =
                    response.data?.data?.errors ||
                    response.data?.errors ||
                    [response.data?.message || 'Failed to upload customers.'];
                triggerErrors(Array.isArray(backendErrors) ? backendErrors : [backendErrors]);
            }
        } catch (err) {
            const serverData = err.response?.data;
            const backendErrors =
                serverData?.data?.errors ||
                serverData?.errors ||
                [serverData?.message || 'Network error occurred while uploading.'];
            triggerErrors(Array.isArray(backendErrors) ? backendErrors : [backendErrors]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '640px' }}>
                <div className="modal-header">
                    <h3>Upload Customers (Excel)</h3>
                    <button className="close-btn" onClick={onClose} disabled={loading}>
                        &times;
                    </button>
                </div>

                <div className="modal-body">
                    {successMsg ? (
                        <div className="alert alert-success" style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                            <CheckCircle size={18} />
                            <span>{successMsg}</span>
                        </div>
                    ) : null}

                    {errors.length > 0 && (
                        <div className="alert alert-danger" style={{ maxHeight: '220px', overflowY: 'auto' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px', fontWeight: 'bold' }}>
                                <AlertCircle size={18} />
                                <span>Validation Errors:</span>
                            </div>
                            <ul style={{ margin: 0, paddingLeft: '20px', fontSize: '0.85rem' }}>
                                {errors.map((err, index) => (
                                    <li key={index} style={{ marginBottom: '3px' }}>{err}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div style={{ marginBottom: '1.5rem', background: '#f8fafc', padding: '1rem', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
                            <span style={{ fontWeight: 600, fontSize: '0.9rem', color: '#334155' }}>Step 1: Download Template</span>
                            <button
                                type="button"
                                className="btn btn-outline btn-sm"
                                onClick={downloadTemplate}
                                style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '0.8rem', padding: '4px 8px' }}
                            >
                                <Download size={14} /> Download Template
                            </button>
                        </div>
                        <p style={{ margin: 0, fontSize: '0.8rem', color: '#64748b' }}>
                            Download the official 31-column template supporting Customer, Vehicle Details, Installation, and Payment breakdowns.
                        </p>
                    </div>

                    <div style={{ marginBottom: '1rem' }}>
                        <span style={{ display: 'block', fontWeight: 600, fontSize: '0.9rem', marginBottom: '0.5rem', color: '#334155' }}>
                            Step 2: Choose Excel File (.xlsx)
                        </span>
                        <input
                            type="file"
                            accept=".xlsx"
                            onChange={handleFileChange}
                            className="form-control"
                            disabled={loading}
                        />
                    </div>
                </div>

                <div className="modal-footer">
                    <button className="btn btn-outline" onClick={onClose} disabled={loading}>
                        Cancel
                    </button>
                    <button
                        className="btn btn-primary"
                        onClick={processExcel}
                        disabled={loading || !file}
                        style={{ display: 'flex', alignItems: 'center', gap: '6px' }}
                    >
                        <Upload size={16} />
                        {loading ? 'Validating & Uploading...' : 'Upload Customers'}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default CustomerExcelUploadModal;
