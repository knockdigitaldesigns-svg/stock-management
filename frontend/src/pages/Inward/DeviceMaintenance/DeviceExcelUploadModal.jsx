import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Upload, Download, CheckCircle } from 'lucide-react';
import api from '../../../services/api';
import useDeviceModels from '../../../hooks/useDeviceModels';
import { isFutureDate, parseDate } from '../../../utils/date';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import { showGlobalError } from '../../../context/ErrorContext';

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
                const workbook = XLSX.read(data, { type: 'array', cellDates: true });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                const jsonData = XLSX.utils.sheet_to_json(worksheet, { raw: true });

                if (jsonData.length === 0) {
                    const msg = "Excel file is empty.";
                    setErrors([msg]);
                    showGlobalError(msg);
                    setLoading(false);
                    return;
                }

                const validationErrors = [];
                const validDevices = [];
                const seenImeis = new Set();
                const imeiRegex = /^[0-9]{15}$/;

                const modelMap = {};
                models.forEach(m => {
                    modelMap[m.label.toLowerCase()] = m.value;
                });

                jsonData.forEach((row, index) => {
                    const rowNum = index + 2;
                    const purchaseDate = row["Purchase Date"];
                    const deviceModelName = row["Device Model"]?.toString().trim();
                    const imeiNo = row["IMEI No"]?.toString().replace(/\D/g, '');
                    const notes = row["Notes"]?.toString().trim() || '';
                    const parsedDate = parseDate(purchaseDate);

                    if (purchaseDate === null || purchaseDate === undefined || purchaseDate === '') validationErrors.push(`Row ${rowNum}: Purchase Date is required.`);
                    else if (!parsedDate) validationErrors.push(`Row ${rowNum}: Purchase Date is invalid.`);
                    else if (isFutureDate(parsedDate)) validationErrors.push(`Row ${rowNum}: Future dates are not allowed.`);
                    if (!deviceModelName) validationErrors.push(`Row ${rowNum}: Device Model is required.`);
                    else if (!modelMap[deviceModelName.toLowerCase()]) validationErrors.push(`Row ${rowNum}: Device model '${deviceModelName}' does not exist in Device Types.`);
                    if (!imeiNo) validationErrors.push(`Row ${rowNum}: IMEI No is required.`);
                    else if (seenImeis.has(imeiNo)) validationErrors.push(`Row ${rowNum}: Duplicate IMEI No inside Excel (${imeiNo}).`);
                    
                    if (imeiNo) seenImeis.add(imeiNo);

                    if (parsedDate && deviceModelName && modelMap[deviceModelName.toLowerCase()] && imeiNo && imeiRegex.test(imeiNo)) {
                        validDevices.push({
                            purchase_date: parsedDate,
                            device_model_id: modelMap[deviceModelName.toLowerCase()],
                            imei_no: imeiNo,
                            notes
                        });
                    }
                });

                if (validationErrors.length > 0) {
                    setErrors(validationErrors);
                    showGlobalError(validationErrors, 'Import Validation Errors');
                    setLoading(false);
                    return;
                }

                const response = await api.post('/devices/create.php', { devices: validDevices });
                if (response.data.success) {
                    setSuccessMsg("Devices uploaded successfully!");
                    setTimeout(() => {
                        onSuccess();
                    }, 1500);
                } else {
                    const errMsg = response.data.message || 'Failed to upload devices';
                    setErrors([errMsg]);
                    showGlobalError(errMsg);
                }

            } catch (error) {
                console.error(error);
                const errMsg = error.response?.data?.message || 'Error parsing Excel file or network error';
                setErrors([errMsg]);
                showGlobalError(errMsg);
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
