import { useState, useEffect } from 'react';
import { useAuth } from '../../../context/AuthContext';
import api from '../../../services/api';
import { showGlobalError } from '../../../context/ErrorContext';
import { formatDate } from '../../../utils/date';

import TableFilterBar from '../../../components/TableFilterBar/TableFilterBar';
import Pagination from '../../../components/Pagination/Pagination';
import Can from '../../../components/Can/Can';
import { Edit2 } from 'lucide-react';
import DealerSimActivationEditModal from './DealerSimActivationEditModal';

const DealerSimActivation = () => {
    const { hasPermission } = useAuth();
    
    const [sims, setSims] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    
    const [masters, setMasters] = useState({ dealers: [], simTypes: [], simValidities: [] });
    
    const [filters, setFilters] = useState({
        search: '',
        dealer_id: '',
        simType: '',
        status: '',
        given_date: '',
        given_date_operator: 'exact',
        activation_date: '',
        activation_date_operator: 'exact',
        simValidity: ''
    });

    const [editingSim, setEditingSim] = useState(null);

    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [totalPages, setTotalPages] = useState(1);
    const [totalRecords, setTotalRecords] = useState(0);

    const fetchMasters = async () => {
        try {
            const [dealersRes, simTypesRes, simValiditiesRes] = await Promise.all([
                api.get('/dealers/list.php'),
                api.get('/sim_types/list.php'),
                api.get('/sim_validities/list.php')
            ]);
            setMasters({
                dealers: dealersRes.data.data?.dealers || [],
                simTypes: simTypesRes.data.data?.sim_types || [],
                simValidities: simValiditiesRes.data.data?.validities || []
            });
        } catch (err) {
            console.error('Failed to fetch filter masters', err);
        }
    };

    const fetchSims = async () => {
        setLoading(true);
        setError(null);
        try {
            let validity_id = '';
            if (filters.simValidity) {
                const months = String(filters.simValidity).replace(/[^0-9]/g, '');
                const validity = masters.simValidities.find(v => String(v.months) === months);
                if (validity) validity_id = validity.id;
            }

            const params = new URLSearchParams({
                page,
                limit: pageSize,
                search: filters.search || '',
                dealer_id: filters.dealer_id || '',
                sim_type: filters.simType || '',
                status: filters.status || '',
                given_date: filters.given_date || '',
                activation_date: filters.activation_date || '',
                validity_id
            });
            const response = await api.get(`/dealers/sim_activation_list.php?${params.toString()}`);
            if (response.data.success) {
                setSims(response.data.data?.sims || []);
                setTotalPages(response.data.data?.pagination?.total_pages || 1);
                setTotalRecords(response.data.data?.pagination?.total_records || 0);
            } else {
                setError(response.data.message || 'Failed to load SIMs');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Error fetching SIMs');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchMasters();
    }, []);

    useEffect(() => {
        fetchSims();
    }, [page, pageSize, filters, masters.simValidities]);

    const handleFilterChange = (newFilters) => {
        setFilters(newFilters);
        setPage(1);
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <h1>Dealer SIM Activation</h1>
            </div>

            <TableFilterBar
                filters={filters}
                onChange={handleFilterChange}
                onReset={() => {
                    setFilters({
                        search: '',
                        dealer_id: '',
                        simType: '',
                        status: '',
                        given_date: '',
                        given_date_operator: 'exact',
                        activation_date: '',
                        activation_date_operator: 'exact',
                        simValidity: ''
                    });
                    setPage(1);
                }}
                searchPlaceholder="Search SIM Number..."
                dealerOptions={masters.dealers.map(d => ({ value: d.id, label: d.dealer_name }))}
                simTypeOptions={masters.simTypes.map(t => t.sim_type)}
                statusOptions={['Available', 'Active', 'Deactive']}
                simValidityOptions={masters.simValidities.map(v => `${v.months} Months`)}
                customDateFilters={[
                    { key: 'given_date', label: 'Given Date' },
                    { key: 'activation_date', label: 'Activation Date' }
                ]}
            />

            {error && <div className="alert alert-danger">{error}</div>}

            <div className="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Dealer Name</th>
                            <th>SIM Number</th>
                            <th>Given Date</th>
                            <th>Activation Date</th>
                            <th>Expiry Date</th>
                            <th>SIM Type</th>
                            <th>Validity</th>
                            <th>SIM Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="10" className="text-center">Loading...</td>
                            </tr>
                        ) : sims.length === 0 ? (
                            <tr>
                                <td colSpan="10" className="text-center">No SIMs found</td>
                            </tr>
                        ) : (
                            sims.map((sim, index) => (
                                <tr key={sim.allocation_id}>
                                    <td>{(page - 1) * pageSize + index + 1}</td>
                                    <td>{sim.dealer_name}</td>
                                    <td>{sim.sim_no}</td>
                                    <td>{formatDate(sim.given_date)}</td>
                                    <td>{formatDate(sim.activation_date)}</td>
                                    <td>{formatDate(sim.expiry_date)}</td>
                                    <td>{sim.sim_type || '-'}</td>
                                    <td>{sim.validity_months ? `${sim.validity_months} Months` : '-'}</td>
                                    <td>
                                        <span className={`status-badge status-${(sim.sim_status || 'Available').toLowerCase()}`}>
                                            {sim.sim_status || 'Available'}
                                        </span>
                                    </td>
                                    <td>
                                        <div className="action-buttons">
                                            <Can permission="dealers.edit">
                                                <button
                                                    className="icon-btn edit"
                                                    onClick={() => setEditingSim(sim)}
                                                    title="Edit SIM Activation"
                                                >
                                                    <Edit2 size={16} />
                                                </button>
                                            </Can>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination
                currentPage={page}
                pageSize={pageSize}
                totalItems={totalRecords}
                onPageChange={setPage}
                onPageSizeChange={setPageSize}
            />

            {editingSim && (
                <DealerSimActivationEditModal
                    allocation={editingSim}
                    onClose={() => setEditingSim(null)}
                    onSuccess={() => {
                        setEditingSim(null);
                        fetchSims();
                    }}
                />
            )}
        </div>
    );
};

export default DealerSimActivation;
