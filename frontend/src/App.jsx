import {
    BrowserRouter as Router,
    Routes,
    Route,
    Navigate
} from 'react-router-dom';

import Login from './pages/Login/Login';
import AdminLayout from './layouts/AdminLayout/AdminLayout';

import Dashboard from './pages/Dashboard/Dashboard';

import DeviceMaintenance
    from './pages/Inward/DeviceMaintenance/DeviceMaintenance';

import SimMaintenance
    from './pages/Inward/SimMaintenance/SimMaintenance';

import InwardReports
    from './pages/Inward/InwardReports/InwardReports';

import Dealers
    from './pages/Outward/Dealers/Dealers';

import DealerSimActivation
    from './pages/Outward/DealerSimActivation/DealerSimActivation';

import Technicians
    from './pages/Outward/Technicians/Technicians';

import DealerReports
    from './pages/Outward/DealerReports/DealerReports';

import StockManagement
    from './pages/StockManagement/StockManagement';

import StockTransferPage
    from './pages/StockTransfer/StockTransferPage';

import ProtectedRoute
    from './components/ProtectedRoute/ProtectedRoute';

import RolesPermissionsPage
    from './pages/Settings/RolesPermissionsPage';

import UsersPage
    from './pages/Settings/UsersPage';

import DeviceAlertPage
    from './pages/DeviceAlert/DeviceAlertPage';

import ChangePasswordPage
    from './pages/Settings/ChangePasswordPage';

import DeviceTypesPage
    from './pages/DeviceTypes/DeviceTypesPage';

import SimValidityPage
    from './pages/Settings/SimValidityPage';

import PlatformPage
    from './pages/platforms/platformpage';

import VehicleTypesPage
    from './pages/vehicletypes/vehicletypespage';

import LeadClosuresPage
    from './pages/leadclosures/leadclosurespage';

import SaleAmountsPage
    from './pages/saleamounts/saleamountspage';

import PendingPayments from './pages/Payments/PendingPayments';
import HistoryPage from './pages/History/HistoryPage';
import SupportPage from './pages/Support/SupportPage';


// ============================================================
// CUSTOMER MANAGEMENT
// ============================================================

// Customer LIST page
import CustomerDetailsPage
    from './pages/customers/customerdetailspage';

// Customer VIEW page
import CustomerViewPage
    from './pages/customers/customerviewpage';

import CustomerReportsPage
    from './pages/customers/CustomerReportsPage';

import RenewalsPage
    from './pages/renewals/renewalspage';

import SimLifecyclePage
    from './pages/simlifecycle/simlifecyclepage';

// Customer CREATE / STEP 1 page
import CustomerCreatePage
    from './pages/customers/customercreatepage';

// Customer VEHICLE / STEP 2 page
import CustomerVehicleDetailsPage
    from './pages/customers/customervehicledetailspage';

// Customer INSTALLATION / STEP 3 page
import InstallationDetailsPage
    from './pages/customers/installationdetailspage';

// Customer PAYMENT PART 1 / STEP 4 page
import PaymentDetailsPart1Page
    from './pages/customers/paymentdetailspart1page';

// Customer PAYMENT PART 2 / STEP 5 page
import PaymentDetailsPart2Page
    from './pages/customers/paymentdetailspart2page';


function App() {

    return (

        <Router>

            <Routes>

                {/* =====================================================
                    LOGIN
                ===================================================== */}

                <Route
                    path="/login"
                    element={<Login />}
                />


                {/* =====================================================
                    ADMIN LAYOUT
                ===================================================== */}

                <Route
                    path="/"
                    element={
                        <ProtectedRoute>
                            <AdminLayout />
                        </ProtectedRoute>
                    }
                >

                    {/* =================================================
                        DEFAULT
                    ================================================= */}

                    <Route
                        index
                        element={
                            <Navigate
                                to="/dashboard"
                                replace
                            />
                        }
                    />


                    {/* =================================================
                        DASHBOARD
                    ================================================= */}

                    <Route
                        path="dashboard"
                        element={
                            <ProtectedRoute
                                permission="dashboard.view"
                            >
                                <Dashboard />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="history"
                        element={
                            <ProtectedRoute permission="history.view">
                                <HistoryPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="support"
                        element={
                            <ProtectedRoute permission="support.view">
                                <SupportPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        INWARD
                    ================================================= */}

                    <Route
                        path="inward/devices"
                        element={
                            <ProtectedRoute
                                permission="devices.view"
                            >
                                <DeviceMaintenance />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="inward/sims"
                        element={
                            <ProtectedRoute
                                permission="sims.view"
                            >
                                <SimMaintenance />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="inward/reports"
                        element={
                            <ProtectedRoute
                                permission="inward_reports.view"
                            >
                                <InwardReports />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        OUTWARD
                    ================================================= */}

                    <Route
                        path="outward/dealers"
                        element={
                            <ProtectedRoute
                                permission="dealers.view"
                            >
                                <Dealers />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="outward/dealer-sim-activation"
                        element={
                            <ProtectedRoute
                                permission="dealers.view"
                            >
                                <DealerSimActivation />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="outward/technicians"
                        element={
                            <ProtectedRoute
                                permission="technicians.view"
                            >
                                <Technicians />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="outward/reports"
                        element={
                            <ProtectedRoute
                                permission="outward_reports.view"
                            >
                                <DealerReports />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        STOCK MANAGEMENT
                    ================================================= */}

                    <Route
                        path="stock"
                        element={
                            <ProtectedRoute
                                permission="stock.view"
                            >
                                <StockManagement />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="stock-transfer"
                        element={
                            <ProtectedRoute
                                permission="stock_transfer.view"
                            >
                                <StockTransferPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        DEVICE ALERT
                    ================================================= */}

                    <Route
                        path="device-alert"
                        element={
                            <ProtectedRoute
                                permission="device_alert.view"
                            >
                                <DeviceAlertPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        DEVICE TYPES
                    ================================================= */}

                    <Route
                        path="device-types"
                        element={
                            <ProtectedRoute
                                permission="device_types.view"
                            >
                                <DeviceTypesPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        SETTINGS
                    ================================================= */}

                    <Route
                        path="settings/roles-permissions"
                        element={
                            <ProtectedRoute
                                permission="roles.view"
                            >
                                <RolesPermissionsPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="settings/users"
                        element={
                            <ProtectedRoute
                                permission="users.view"
                            >
                                <UsersPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="settings/change-password"
                        element={
                            <ProtectedRoute
                                permission="password.change"
                            >
                                <ChangePasswordPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="settings/sim-validity"
                        element={
                            <ProtectedRoute
                                permission="sim_validity.view"
                            >
                                <SimValidityPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        MASTER SETTINGS
                    ================================================= */}

                    <Route
                        path="/platforms"
                        element={
                            <ProtectedRoute
                                permission="platforms.view"
                            >
                                <PlatformPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="/vehicle-types"
                        element={
                            <ProtectedRoute
                                permission="vehicle_types.view"
                            >
                                <VehicleTypesPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="/lead-closures"
                        element={
                            <ProtectedRoute
                                permission="lead_closures.view"
                            >
                                <LeadClosuresPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="/sale-amounts"
                        element={
                            <ProtectedRoute
                                permission="sale_amounts.view"
                            >
                                <SaleAmountsPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* =================================================
                        CUSTOMER MANAGEMENT
                    ================================================= */}


                    {/* -------------------------------------------------
                        CUSTOMER LIST

                        Sidebar -> Customer Details

                        URL:
                        /customer-management/details
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details"
                        element={
                            <ProtectedRoute
                                permission="customers.view"
                            >
                                <CustomerDetailsPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="/customer-management/reports"
                        element={
                            <ProtectedRoute
                                permission="customer_reports.view"
                            >
                                <CustomerReportsPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="/customer-management/renewals"
                        element={
                            <ProtectedRoute permission="customer_renewals.view">
                                <RenewalsPage />
                            </ProtectedRoute>
                        }
                    />

                    <Route
                        path="/customer-management/sim-lifecycle"
                        element={
                            <ProtectedRoute permission="sim_lifecycle.view">
                                <SimLifecyclePage />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        ADD CUSTOMER - STEP 1

                        Add Customer button

                        URL:
                        /customer-management/details/add
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/add"
                        element={
                            <ProtectedRoute
                                permission="customers.add"
                            >
                                <CustomerCreatePage />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        EXISTING CUSTOMER - STEP 1

                        Used when coming BACK from Vehicle Details.

                        URL:
                        /customer-management/details/3

                        Customer ID = 3
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/:customerId"
                        element={
                            <ProtectedRoute
                                permission="customers.view"
                            >
                                <CustomerCreatePage />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        EXISTING CUSTOMER - VIEW MODE

                        Used when clicking View from customer list.

                        URL:
                        /customer-management/details/11/view
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/:customerId/view"
                        element={
                            <ProtectedRoute
                                permission="customers.view"
                            >
                                <CustomerViewPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        EXISTING CUSTOMER - EDIT MODE

                        Used when clicking Edit from the structured view page.

                        URL:
                        /customer-management/details/11/edit
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/:customerId/edit"
                        element={
                            <ProtectedRoute
                                permission="customers.edit"
                            >
                                <CustomerCreatePage />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        VEHICLE DETAILS - STEP 2

                        URL:
                        /customer-management/details/vehicle/3
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/vehicle/:customerId"
                        element={
                            <ProtectedRoute
                                permission="customers.view"
                            >
                                <CustomerVehicleDetailsPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        INSTALLATION DETAILS - STEP 3

                        URL:
                        /customer-management/details/installation/:customerId
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/installation/:customerId"
                        element={
                            <ProtectedRoute
                                permission="customers.view"
                            >
                                <InstallationDetailsPage />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        PAYMENT DETAILS - STEP 4

                        URL:
                        /customer-management/details/payment/:customerId
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/payment/:customerId"
                        element={
                            <ProtectedRoute
                                permission="customers.view"
                            >
                                <PaymentDetailsPart1Page />
                            </ProtectedRoute>
                        }
                    />


                    {/* -------------------------------------------------
                        PAYMENT DETAILS - STEP 5

                        URL:
                        /customer-management/details/payment-details/:customerId
                    ------------------------------------------------- */}

                    <Route
                        path="/customer-management/details/payment-details/:customerId"
                        element={
                            <ProtectedRoute
                                permission="customers.view"
                            >
                                <PaymentDetailsPart2Page />
                            </ProtectedRoute>
                        }
                    />
                    <Route
    path="/payments/pending"
    element={
        <ProtectedRoute permission="dashboard.view">
            <PendingPayments />
        </ProtectedRoute>
    }
/>


                </Route>

            </Routes>

        </Router>
    );
}

export default App;