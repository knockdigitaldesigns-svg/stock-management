import { useState, useEffect } from 'react';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { Package, Smartphone, CheckCircle, Clock, Truck } from 'lucide-react';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { formatDate } from '../../utils/date';
import './Dashboard.css';

const Dashboard = () => {
    const { user } = useAuth();
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchStats = async () => {
            try {
                const response = await api.get('/dashboard/stats.php');
                if (response.data.success) {
                    setStats(response.data.data);
                }
            } catch (error) {
                console.error("Failed to fetch dashboard stats", error);
            } finally {
                setLoading(false);
            }
        };
        fetchStats();
    }, []);

    if (loading) {
        return <div className="page-container text-center" style={{ padding: '3rem' }}>Loading Dashboard...</div>;
    }

    if (!stats) {
        return <div className="page-container text-center text-danger" style={{ padding: '3rem' }}>Failed to load dashboard data.</div>;
    }

    const { metrics, recent_allocations = [], stock_distribution = [], monthly_allocations = [] } = stats;

    return (
        <div className="page-container">
            <div className="page-header">
                <h2>Dashboard Overview</h2>
                <p style={{ color: 'var(--text-secondary)', margin: 0 }}>Welcome back, {user?.employee_name || user?.username || 'User'}</p>
            </div>

            {/* Metrics Cards */}
            <div className="metrics-grid">
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#e0e7ff', color: '#3730a3' }}><Smartphone size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Total Devices</p>
                        <h3 className="metric-value">{metrics.total_devices}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#dbeafe', color: '#1e40af' }}><Package size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Available Devices</p>
                        <h3 className="metric-value">{metrics.available_devices}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#fef3c7', color: '#b45309' }}><Clock size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Allocated Devices</p>
                        <h3 className="metric-value">{metrics.allocated_devices}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#e0f2fe', color: '#0369a1' }}><Truck size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Used Devices</p>
                        <h3 className="metric-value">{metrics.used_devices}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#dcfce7', color: '#166534' }}><CheckCircle size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Total SIMs</p>
                        <h3 className="metric-value">{metrics.total_sims}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#ccfbf1', color: '#0f766e' }}><CheckCircle size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Available SIMs</p>
                        <h3 className="metric-value">{metrics.available_sims}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#ffe4e6', color: '#be123c' }}><Clock size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Allocated SIMs</p>
                        <h3 className="metric-value">{metrics.allocated_sims}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#ede9fe', color: '#6d28d9' }}><Truck size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Used SIMs</p>
                        <h3 className="metric-value">{metrics.used_sims}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#dbeafe', color: '#1d4ed8' }}><Package size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Total Dealers</p>
                        <h3 className="metric-value">{metrics.total_dealers}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#f3e8ff', color: '#7c3aed' }}><Package size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Total Technicians</p>
                        <h3 className="metric-value">{metrics.total_technicians}</h3>
                    </div>
                </div>
                <div className="metric-card">
                    <div className="metric-icon" style={{ backgroundColor: '#fef2f2', color: '#b91c1c' }}><Clock size={24} /></div>
                    <div className="metric-content">
                        <p className="metric-title">Pending Payments</p>
                        <h3 className="metric-value">{metrics.pending_payments}</h3>
                    </div>
                </div>
            </div>

            <div className="dashboard-grid">
                {/* Charts */}
                <div className="card dashboard-card">
                    <h3>Stock Distribution</h3>
                    <div className="chart-container">
                        {stock_distribution.length === 0 ? <div className="text-center">No stock data available.</div> : <ResponsiveContainer width="100%" height={300}>
                            <PieChart>
                                <Pie
                                    data={stock_distribution}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={60}
                                    outerRadius={100}
                                    paddingAngle={5}
                                    dataKey="value"
                                >
                                    {stock_distribution.map((entry, index) => (
                                        <Cell key={`cell-${index}`} fill={entry.fill} />
                                    ))}
                                </Pie>
                                <Tooltip formatter={(value) => [`${value} Items`, 'Count']} />
                                <Legend />
                            </PieChart>
                        </ResponsiveContainer>}
                    </div>
                </div>

                <div className="card dashboard-card">
                    <h3>Monthly Allocations</h3>
                    <div className="chart-container">
                        {monthly_allocations.length === 0 ? <div className="text-center">No allocation data available.</div> : <ResponsiveContainer width="100%" height={300}>
                            <BarChart data={monthly_allocations}>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                <XAxis dataKey="name" />
                                <YAxis />
                                <Tooltip />
                                <Legend />
                                <Bar dataKey="devices" name="Devices" fill="#3730a3" radius={[4, 4, 0, 0]} />
                                <Bar dataKey="sims" name="SIMs" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>}
                    </div>
                </div>

                {/* Recent Allocations Table */}
                <div className="card dashboard-card full-width">
                    <h3>Recent Allocations</h3>
                    <div className="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Owner</th>
                                    <th>Item Number</th>
                                    <th>Status / Owner Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent_allocations.length === 0 ? (
                                    <tr>
                                        <td colSpan="4" className="text-center">No recent allocations</td>
                                    </tr>
                                ) : (
                                    recent_allocations.map((alloc, i) => (
                                        <tr key={i}>
                                            <td>{formatDate(alloc.date)}</td>
                                            <td>{alloc.owner || 'Unknown'}</td>
                                            <td>{alloc.item || 'N/A'}</td>
                                            <td>
                                                <span className={`badge ${alloc.status === 'dealer' ? 'badge-primary' : 'badge-info'}`}>
                                                    {alloc.status ? alloc.status.toUpperCase() : 'N/A'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default Dashboard;
