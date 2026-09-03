import { useEffect, useState } from 'react';
import * as XLSX from 'xlsx';
import { Download, AlertCircle, CheckCircle } from 'lucide-react';
import api from '../../../services/api';
import { isFutureDate, parseDate } from '../../../utils/date';
import useModalScrollLock from '../../../hooks/useModalScrollLock';

const SimExcelUploadModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [file, setFile] = useState(null);
    const [errors, setErrors] = useState([]);
    const [loading, setLoading] = useState(false);
    const [successMsg, setSuccessMsg] = useState('');
    const [validities, setValidities] = useState([]);

    useEffect(() => { api.get('/sim_validities/list.php').then((response) => setValidities(response.data.data?.validities || [])).catch(() => setErrors(['Unable to load SIM validities.'])); }, []);

    const downloadTemplate = () => {
        const ws = XLSX.utils.json_to_sheet([
            { "Purchase Date": "01-10-2023", "SIM No": "9876543210", "SIM Type": "Voice", "SIM Validity": 12, "Notes": "" },
            { "Purchase Date": "02-10-2023", "SIM No": "1234567890123", "SIM Type": "Non Voice", "SIM Validity": 24, "Notes": "" }
        ]);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Template");
        XLSX.writeFile(wb, "SIM_Upload_Template.xlsx");
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
                    const validSims = [];
                const simRegex = /^(?:[0-9]{10}|[0-9]{13})$/;
                    const seenSims = new Set();
                jsonData.forEach((row, index) => {
                    const rowNum = index + 2; 
                    const purchaseDate = row["Purchase Date"];
                    const simNo = row["SIM No"]?.toString().replace(/\D/g, '');
                    const simType = row["SIM Type"]?.toString().trim();
                    const validityMonths = row["SIM Validity"]?.toString().trim();
                    const validity = validities.find((item) => String(item.months) === validityMonths);
                    const notes = row["Notes"]?.toString().trim() || '';
                    const parsedDate = parseDate(purchaseDate);

                    if (!purchaseDate) validationErrors.push(`Row ${rowNum}: Purchase Date is required.`);
                    else if (isFutureDate(parsedDate)) validationErrors.push(`Row ${rowNum}: Future dates are not allowed.`);
                    
                    if (!simNo) validationErrors.push(`Row ${rowNum}: SIM No is required.`);
                    else if (!simRegex.test(simNo)) validationErrors.push(`Row ${rowNum}: SIM No must contain exactly 10 or 13 digits.`);
                    else if (seenSims.has(simNo)) validationErrors.push(`Row ${rowNum}: Duplicate SIM No inside Excel (${simNo}).`);
                    if (!['Voice', 'Non Voice'].includes(simType)) validationErrors.push(`Row ${rowNum}: SIM Type must be Voice or Non Voice.`);
                    if (!validity) validationErrors.push(`Row ${rowNum}: SIM validity ${validityMonths || ''} months does not exist.`);
                    
                    if (simNo) seenSims.add(simNo);

                    if (purchaseDate && simNo && simRegex.test(simNo) && validity && ['Voice', 'Non Voice'].includes(simType)) {
                        let normalizedDate = parsedDate;
                        if (purchaseDate.includes('/')) {
                            const [m, d, y] = purchaseDate.split('/');
                            normalizedDate = parseDate(`${d}-${m}-${y}`);
                        }

                        validSims.push({
                            purchase_date: normalizedDate,
                            sim_no: simNo
                            , sim_type: simType, sim_validity_id: validity.id
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
                const response = await api.post('/sims/create.php', { sims: validSims });
                if (response.data.success) {
                    setSuccessMsg("SIMs uploaded successfully!");
                    setTimeout(() => {
                        onSuccess();
                    }, 1500);
                } else {
                    setErrors([response.data.message || 'Failed to upload SIMs']);
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
                    <h3>Upload SIMs via Excel</h3>
                    <button className="close-btn" onClick={onClose} disabled={loading}>&times;</button>
                </div>
                
                <div className="modal-body">
                    <div className="info-box" style={{ backgroundColor: '#f8fafc', padding: '1rem', borderRadius: '0.5rem', marginBottom: '1.5rem' }}>
                        <p style={{ margin: '0 0 0.5rem 0', fontWeight: '500' }}>Instructions:</p>
                        <ul style={{ margin: '0', paddingLeft: '1.5rem', fontSize: '0.875rem', color: 'var(--text-secondary)' }}>
                            <li>Columns: <strong>Purchase Date | SIM No | SIM Type | SIM Validity | Notes</strong> (Notes is optional)</li>
                            <li>SIM No must be exactly 10 OR 13 digits and unique.</li>
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
                            disabled={loading}
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
                        disabled={!file || loading}
                    >
                        {loading ? 'Processing...' : 'Upload Data'}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default SimExcelUploadModal;
