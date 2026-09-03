import { Navigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import AccessDenied from '../../pages/AccessDenied/AccessDenied';

const ProtectedRoute = ({ permission, children }) => {
    const { isAuthenticated, hasPermission } = useAuth();

    if (!isAuthenticated) {
        return <Navigate to="/login" replace />;
    }

    if (permission && !hasPermission(permission)) {
        return <AccessDenied />;
    }

    return children;
};

export default ProtectedRoute;
