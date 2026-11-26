import { useEffect, useMemo, useState } from 'react';
import { Search, RefreshCw, UserRound } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Modal from '../../components/Modal/Modal';
import { fetchAllPaginated } from '../../utils/fetchAllPaginated';
import { useAuth } from '../../context/AuthContext';
import './SwitchAccountPage.css';

const accountName = (account) => account.employee_name || account.username || 'Unnamed account';
const accountRole = (account) => account.role_name || account.role || 'No Role';

const SwitchAccountPage = () => {
    const navigate = useNavigate();
    const { switchAccount } = useAuth();
    const [accounts, setAccounts] = useState([]);
    const [search, setSearch] = useState('');
    const [selectedAccount, setSelectedAccount] = useState(null);
    const [loading, setLoading] = useState(true);
    const [switching, setSwitching] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    const [error, setError] = useState('');
    const [switchError, setSwitchError] = useState('');

    useEffect(() => {
        let active = true;
        const loadAccounts = async () => {
            setLoading(true);
            setError('');
            try {
                const list = await fetchAllPaginated(
                    (page, pageSize) => api.get('/users/list.php', {
                        params: { scope: 'switch_accounts', page, page_size: pageSize }
                    }),
                    'users'
                );
                if (active) setAccounts(list);
            } catch (requestError) {
                if (active) {
                    setError(requestError.response?.data?.message || requestError.message || 'Unable to load employee accounts.');
                }
            } finally {
                if (active) setLoading(false);
            }
        };
        loadAccounts();
        return () => {
            active = false;
        };
    }, [reloadKey]);

    const filteredAccounts = useMemo(() => {
        const query = search.trim().toLowerCase();
        if (!query) return accounts;
        return accounts.filter((account) => [
            account.employee_name,
            account.username,
            account.role_name,
            account.role,
            account.mobile_no
        ].some((value) => String(value || '').toLowerCase().includes(query)));
    }, [accounts, search]);

    const confirmSwitch = async () => {
        if (!selectedAccount || switching) return;
        setSwitching(true);
        setSwitchError('');
        try {
            const result = await switchAccount(selectedAccount.id);
            const permissionSet = new Set(result.permissions || []);
            if (permissionSet.has('crm_leads.my_view') || permissionSet.has('crm_leads.view')) {
                navigate('/crm/my-leads', { replace: true });
            } else if (permissionSet.has('crm_followups.view')) {
                navigate('/crm/follow-ups', { replace: true });
            } else {
                navigate('/dashboard', { replace: true });
            }
        } catch (requestError) {
            setSwitchError(requestError.response?.data?.message || requestError.message || 'Unable to switch accounts.');
            setSwitching(false);
        }
    };

    return (
        <div className="page-container switch-account-page">
            <div className="page-header">
                <div>
                    <h2>Switch Employee Account</h2>
                    <p className="page-subtitle">Choose an active account to view the CRM with its permissions.</p>
                </div>
                <button
                    type="button"
                    className="btn btn-outline"
                    onClick={() => setReloadKey((current) => current + 1)}
                    disabled={loading}
                >
                    <RefreshCw size={16} /> Refresh
                </button>
            </div>

            {error && <div className="alert alert-danger" role="alert">{error}</div>}

            <div className="card switch-account-card">
                <div className="switch-account-search">
                    <label htmlFor="switch-account-search">Search employees</label>
                    <div className="search-input-wrapper">
                        <Search size={16} className="search-icon" />
                        <input
                            id="switch-account-search"
                            className="form-control has-icon"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name, username, role, or mobile"
                        />
                    </div>
                </div>

                <div className="table-container switch-account-table-wrap">
                    <table className="switch-account-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Mobile</th>
                                <th aria-label="Actions" />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan="5" className="switch-account-empty">Loading active accounts...</td></tr>
                            ) : filteredAccounts.length === 0 ? (
                                <tr><td colSpan="5" className="switch-account-empty">
                                    {accounts.length ? 'No accounts match your search.' : 'No eligible active accounts found.'}
                                </td></tr>
                            ) : filteredAccounts.map((account) => (
                                <tr key={account.id}>
                                    <td>
                                        <span className="switch-account-name">
                                            <UserRound size={17} />
                                            {accountName(account)}
                                        </span>
                                    </td>
                                    <td>{account.username || '-'}</td>
                                    <td>{accountRole(account)}</td>
                                    <td>{account.mobile_no || '-'}</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="btn btn-primary switch-account-select"
                                            onClick={() => {
                                                setSelectedAccount(account);
                                                setSwitchError('');
                                            }}
                                        >
                                            Select
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal
                isOpen={Boolean(selectedAccount)}
                onClose={() => {
                    if (!switching) setSelectedAccount(null);
                }}
                title="Confirm account switch"
                maxWidth="480px"
                footer={(
                    <>
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => setSelectedAccount(null)}
                            disabled={switching}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={confirmSwitch}
                            disabled={switching}
                        >
                            {switching ? 'Switching...' : 'Switch Account'}
                        </button>
                    </>
                )}
            >
                <p>Are you sure you want to switch to this employee account?</p>
                {selectedAccount && (
                    <div className="switch-account-confirm-user">
                        <strong>{accountName(selectedAccount)}</strong>
                        <span>{selectedAccount.username} · {accountRole(selectedAccount)}</span>
                    </div>
                )}
                {switchError && <div className="alert alert-danger" role="alert">{switchError}</div>}
            </Modal>
        </div>
    );
};

export default SwitchAccountPage;
