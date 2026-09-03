import { useAuth } from '../../context/AuthContext';

const Can = ({ permission, children, fallback = null }) => {
    const { hasPermission } = useAuth();

    if (!permission || hasPermission(permission)) {
        return children;
    }

    return fallback;
};

export default Can;
