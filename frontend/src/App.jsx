import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import Login from './pages/Login/Login';
import AdminLayout from './layouts/AdminLayout/AdminLayout';
import Dashboard from './pages/Dashboard/Dashboard';
import DeviceMaintenance from './pages/Inward/DeviceMaintenance/DeviceMaintenance';
import SimMaintenance from './pages/Inward/SimMaintenance/SimMaintenance';
import InwardReports from './pages/Inward/InwardReports/InwardReports';
import Dealers from './pages/Outward/Dealers/Dealers';
import Technicians from './pages/Outward/Technicians/Technicians';
import DealerReports from './pages/Outward/DealerReports/DealerReports';
import StockManagement from './pages/StockManagement/StockManagement';
import ProtectedRoute from './components/ProtectedRoute/ProtectedRoute';
import RolesPermissionsPage from './pages/Settings/RolesPermissionsPage';
import UsersPage from './pages/Settings/UsersPage';
import DeviceAlertPage from './pages/DeviceAlert/DeviceAlertPage';
import ChangePasswordPage from './pages/Settings/ChangePasswordPage';
import DeviceTypesPage from './pages/DeviceTypes/DeviceTypesPage';
import SimValidityPage from './pages/Settings/SimValidityPage';

function App() {
    return (
        <Router>
            <Routes>
                <Route path="/login" element={<Login />} />
                <Route path="/" element={
                    <ProtectedRoute>
                        <AdminLayout />
                    </ProtectedRoute>
                }>
                    <Route index element={<Navigate to="/dashboard" replace />} />
                    <Route path="dashboard" element={<ProtectedRoute permission="dashboard.view"><Dashboard /></ProtectedRoute>} />
                    <Route path="inward/devices" element={<ProtectedRoute permission="devices.view"><DeviceMaintenance /></ProtectedRoute>} />
                    <Route path="inward/sims" element={<ProtectedRoute permission="sims.view"><SimMaintenance /></ProtectedRoute>} />
                    <Route path="inward/reports" element={<ProtectedRoute permission="inward_reports.view"><InwardReports /></ProtectedRoute>} />
                    <Route path="outward/dealers" element={<ProtectedRoute permission="dealers.view"><Dealers /></ProtectedRoute>} />
                    <Route path="outward/technicians" element={<ProtectedRoute permission="technicians.view"><Technicians /></ProtectedRoute>} />
                    <Route path="outward/reports" element={<ProtectedRoute permission="outward_reports.view"><DealerReports /></ProtectedRoute>} />
                    <Route path="stock" element={<ProtectedRoute permission="stock.view"><StockManagement /></ProtectedRoute>} />
                    <Route path="settings/roles-permissions" element={<ProtectedRoute permission="roles.view"><RolesPermissionsPage /></ProtectedRoute>} />
                    <Route path="settings/users" element={<ProtectedRoute permission="users.view"><UsersPage /></ProtectedRoute>} />
                    <Route path="device-alert" element={<ProtectedRoute permission="device_alert.view"><DeviceAlertPage /></ProtectedRoute>} />
                    <Route path="device-types" element={<ProtectedRoute permission="device_types.view"><DeviceTypesPage /></ProtectedRoute>} />
                    <Route path="settings/change-password" element={<ProtectedRoute permission="password.change"><ChangePasswordPage /></ProtectedRoute>} />
                    <Route path="settings/sim-validity" element={<ProtectedRoute permission="sim_validity.view"><SimValidityPage /></ProtectedRoute>} />
                </Route>
            </Routes>
        </Router>
    );
}

export default App;
