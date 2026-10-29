import { useState } from 'react';
import * as XLSX from 'xlsx';
import { Download, CheckCircle } from 'lucide-react';
import api from '../../../services/api';
import useModalScrollLock from '../../../hooks/useModalScrollLock';
import BulkUploadValidationErrors from '../../../components/BulkUploadValidationErrors/BulkUploadValidationErrors';
import { normalizeBulkUploadErrors } from '../../../utils/bulkUploadErrors';

const SimExcelUploadModal = ({ onClose, onSuccess }) => {
    useModalScrollLock(true);

    const [file, setFile] = useState(null);
    const [errors, setErrors] = useState([]);
    const [loading, setLoading] = useState(false);
    const [successMsg, setSuccessMsg] = useState('');
    // const [validities, setValidities] = useState([]);

    // useEffect(() => {
    //     api.get('/sim_validities/list.php')
    //         .then((response) => setValidities(response.data.data?.validities || []))
    //         .catch(() => {
    //             const msg = 'Unable to load SIM validities.';
    //             setErrors([msg]);
    //             showGlobalError(msg);
    //         });
    // }, []);

    const downloadTemplate = () => {
        const ws = XLSX.utils.json_to_sheet([
            { "Purchase Date": "01-10-2023", "SIM No": "9876543210", "SIM Type": "Voice", "Notes": "" },
            { "Purchase Date": "02-10-2023", "SIM No": "1234567890123", "SIM Type": "Non Voice", "Notes": "" }
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
                const sheetRows = XLSX.utils.sheet_to_json(worksheet, {
                    header: 1,
                    defval: '',
                    raw: true,
                    blankrows: true
                });

                if (sheetRows.length <= 1) {
                    const msg = "Excel file is empty.";
                    setErrors([msg]);
                    setLoading(false);
                    return;
                }

                const headers = sheetRows[0].map((header) => String(header ?? '').trim().toLowerCase());
                const requiredColumns = ['Purchase Date', 'SIM No', 'SIM Type'];
                const missingColumns = requiredColumns.filter((name) => !headers.includes(name.toLowerCase()));
                if (missingColumns.length) {
                    setErrors(missingColumns.map((name) => `Required column '${name}' is missing.`));
                    setLoading(false);
                    return;
                }
                const columns = ['purchase date', 'sim no', 'sim type', 'notes'].map((name) => headers.indexOf(name));
                const rows = sheetRows.slice(1)
                    .map((cells, index) => ({ cells, rowNum: index + 2 }))
                    .filter(({ cells }) => cells.some((cell) => String(cell ?? '').trim() !== ''));
                if (!rows.length) {
                    setErrors(['Excel file contains no data rows.']);
                    setLoading(false);
                    return;
                }
                const sims = rows.map(({ cells, rowNum }) => ({
                    row_number: rowNum,
                    purchase_date: cells[columns[0]],
                    sim_no: String(cells[columns[1]] ?? '').trim(),
                    sim_type: String(cells[columns[2]] ?? '').trim(),
                    notes: columns[3] < 0 ? '' : String(cells[columns[3]] ?? '').trim()
                }));

                const response = await api.post('/sims/create.php', { sims });
                if (response.data.success) {
                    setSuccessMsg("SIMs uploaded successfully!");
                    setTimeout(() => {
                        onSuccess();
                    }, 1500);
                } else {
                    const errMsg = response.data.message || 'Failed to upload SIMs';
                    setErrors(normalizeBulkUploadErrors(response.data, errMsg));
                }

            } catch (error) {
                console.error(error);
                const errMsg = error.response?.data?.message || 'Error parsing Excel file or network error';
                setErrors(normalizeBulkUploadErrors(error.response?.data, errMsg));
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
                            <li>Columns: <strong>Purchase Date | SIM No | SIM Type | Notes</strong> (Notes is optional)</li>
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

                    <BulkUploadValidationErrors errors={errors} />

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
