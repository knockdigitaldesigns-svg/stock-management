import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Upload, Download, AlertCircle, CheckCircle } from 'lucide-react';
import api from '../../../services/api';
import useDeviceModels from '../../../hooks/useDeviceModels';
import { isFutureDate, parseDate } from '../../../utils/date';
import useModalScrollLock from '../../../hooks/useModalScrollLock';

const DeviceExcelUploadModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const { models, loading: modelsLoading } = useDeviceModels();
    const [file, setFile] = useState(null);
    const [errors, setErrors] = useState([]);
    const [loading, setLoading] = useState(false);
    const [successMsg, setSuccessMsg] = useState('');

    const downloadTemplate = () => {
        const ws = XLSX.utils.json_to_sheet([
            { "Purchase Date": "01-10-2023", "Device Model": "Basic", "IMEI No": "123456789012345", "Notes": "" }
        ]);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Template");
        XLSX.writeFile(wb, "Device_Upload_Template.xlsx");
    };

    const handleFileChange = (e) => {
        const selectedFile = e.target.files[0];
        if (selectedFile) {
            setFile(selectedFile);
            setErrors([]);
            setSuccessMsg('');
        }
    };
    const processExcel = () => {
        if (!file) return;

        setLoading(true);
        setErrors([]);
        setSuccessMsg('');

        const reader = new FileReader();
        reader.onload = async (e) => {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                const jsonData = XLSX.utils.sheet_to_json(worksheet, { raw: false });

                if (jsonData.length === 0) {
                    setErrors(["Excel file is empty."]);
                    setLoading(false);
                    return;
                }

                const validationErrors = [];
                const validDevices = [];
                const seenImeis = new Set();
                const imeiRegex = /^[0-9]{15}$/;

                // Create a map for model name to ID for quick lookup
                const modelMap = {};
                models.forEach(m => {
                    modelMap[m.label.toLowerCase()] = m.value;
                });

                jsonData.forEach((row, index) => {
                    const rowNum = index + 2; // +2 because 1 is header and 0-indexed array
                    const purchaseDate = row["Purchase Date"];
                    const deviceModelName = row["Device Model"]?.toString().trim();
                    const imeiNo = row["IMEI No"]?.toString().replace(/\D/g, '');
                    const notes = row["Notes"]?.toString().trim() || '';
                    const parsedDate = parseDate(purchaseDate);

                    if (!purchaseDate) validationErrors.push(`Row ${rowNum}: Purchase Date is required.`);
                    else if (isFutureDate(parsedDate)) validationErrors.push(`Row ${rowNum}: Future dates are not allowed.`);
                    if (!deviceModelName) validationErrors.push(`Row ${rowNum}: Device Model is required.`);
                    else if (!modelMap[deviceModelName.toLowerCase()]) validationErrors.push(`Row ${rowNum}: Device model '${deviceModelName}' does not exist in Device Types.`);
                    if (!imeiNo) validationErrors.push(`Row ${rowNum}: IMEI No is required.`);
                    else if (seenImeis.has(imeiNo)) validationErrors.push(`Row ${rowNum}: Duplicate IMEI No inside Excel (${imeiNo}).`);
                    
                    if (imeiNo) seenImeis.add(imeiNo);

                    if (purchaseDate && deviceModelName && modelMap[deviceModelName.toLowerCase()] && imeiNo && imeiRegex.test(imeiNo)) {
                        // Attempt to parse date (assuming YYYY-MM-DD or MM/DD/YYYY from Excel)
                        let normalizedDate = parsedDate;
                        if (purchaseDate.includes('/')) {
                            const [m, d, y] = purchaseDate.split('/');
                            normalizedDate = parseDate(`${d}-${m}-${y}`);
                        }

                        validDevices.push({
                            purchase_date: normalizedDate,
                            device_model_id: modelMap[deviceModelName.toLowerCase()],
                            imei_no: imeiNo
                            , notes
                        });
                    }
                });

                if (validationErrors.length > 0) {
                    setErrors(validationErrors);
                    setLoading(false);
                    return;
                }

                // Call backend API
                const response = await api.post('/devices/create.php', { devices: validDevices });
                if (response.data.success) {
                    setSuccessMsg("Devices uploaded successfully!");
                    setTimeout(() => {
                        onSuccess();
                    }, 1500);
                } else {
                    setErrors([response.data.message || 'Failed to upload devices']);
                }

            } catch (error) {
                console.error(error);
                setErrors([error.response?.data?.message || 'Error parsing Excel file or network error']);
            } finally {
                setLoading(false);
            }
        };
        reader.readAsArrayBuffer(file);
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '600px' }}>
                <div className="modal-header">
                    <h3>Upload Devices via Excel</h3>
                    <button className="close-btn" onClick={onClose} disabled={loading}>&times;</button>
                </div>
                
                <div className="modal-body">
                    <div className="info-box" style={{ backgroundColor: '#f8fafc', padding: '1rem', borderRadius: '0.5rem', marginBottom: '1.5rem' }}>
                        <p style={{ margin: '0 0 0.5rem 0', fontWeight: '500' }}>Instructions:</p>
                        <ul style={{ margin: '0', paddingLeft: '1.5rem', fontSize: '0.875rem', color: 'var(--text-secondary)' }}>
                            <li>Columns: <strong>Purchase Date | Device Model | IMEI No | Notes</strong> (Notes is optional)</li>
                            <li>IMEI must be exactly 15 digits and unique.</li>
                            <li>Device Model must match existing master data exactly.</li>
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
                            accept=".xlsx, .xls"
                            className="form-control"
                            onChange={handleFileChange}
                            disabled={loading || modelsLoading}
                        />
                    </div>

                    {errors.length > 0 && (
                        <div className="alert alert-danger" style={{ maxHeight: '200px', overflowY: 'auto' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.5rem' }}>
                                <AlertCircle size={16} /> <strong>Validation Errors:</strong>
                            </div>
                            <ul style={{ margin: 0, paddingLeft: '1.5rem', fontSize: '0.875rem' }}>
                                {errors.map((err, i) => <li key={i}>{err}</li>)}
                            </ul>
                        </div>
                    )}

                    {successMsg && (
                        <div className="alert badge-success" style={{ padding: '1rem', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
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
                        disabled={!file || loading || modelsLoading}
                    >
                        {loading ? 'Processing...' : 'Upload Data'}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default DeviceExcelUploadModal;
