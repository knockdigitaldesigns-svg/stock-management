import { useMemo } from 'react';
import './TableFilterBar.css';

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

const unique = (values) => [...new Set(values.filter(Boolean).map(String))].sort((a, b) => a.localeCompare(b));

export const getAvailableYears = (items, dateKeys = []) => {
    const years = new Set();
    items.forEach((item) => dateKeys.forEach((key) => {
        const value = item[key];
        const match = typeof value === 'string' && value.match(/^(\d{4})-/);
        if (match) years.add(match[1]);
    }));
    return [...years].sort((a, b) => Number(b) - Number(a));
};

export const filterTableRows = (items, filters, { dateKeys = [], deviceKey, simKey, ownerKey, softwareKey, searchKeys = [] } = {}) => {
    const query = filters.search.trim().toLowerCase();
    return items.filter((item) => {
        const matchesSearch = !query || searchKeys.some((key) => String(item[key] ?? '').toLowerCase().includes(query));
        const matchesDate = !filters.year && !filters.month || dateKeys.some((key) => {
            const value = item[key];
            if (!value) return false;
            const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
            return (!filters.year || date.getFullYear() === Number(filters.year)) && (!filters.month || date.getMonth() + 1 === Number(filters.month));
        });
        const matchesDevice = !deviceKey || !filters.deviceType || String(item[deviceKey]) === filters.deviceType;
        const matchesSim = !simKey || !filters.simType || String(item[simKey]) === filters.simType;
        const matchesOwner = !ownerKey || !filters.ownerType || String(item[ownerKey]).toLowerCase() === filters.ownerType;
        const matchesSoftware = !softwareKey || !filters.software || String(item[softwareKey]) === filters.software;
        return matchesSearch && matchesDate && matchesDevice && matchesSim && matchesOwner && matchesSoftware;
    });
};

const TableFilterBar = ({ filters, onChange, onReset, items = [], dateKeys = [], deviceKey, simKey, ownerKey, softwareKey, deviceOptions, simOptions, searchPlaceholder = 'Search...' }) => {
    const years = useMemo(() => getAvailableYears(items, dateKeys), [items, dateKeys]);
    const deviceTypes = useMemo(() => deviceOptions || unique(items.map((item) => item[deviceKey])), [items, deviceKey, deviceOptions]);
    const simTypes = useMemo(() => simOptions || unique(items.map((item) => item[simKey])), [items, simKey, simOptions]);
    const softwareOptions = useMemo(() => unique(items.map((item) => item[softwareKey])), [items, softwareKey]);
    const set = (key) => (event) => onChange({ ...filters, [key]: event.target.value });
    return <div className="table-filter-bar">
        <input className="form-control table-filter-search" value={filters.search} onChange={set('search')} placeholder={searchPlaceholder} />
        {dateKeys.length > 0 && <select className="form-control" value={filters.year} onChange={set('year')}><option value="">All Years</option>{years.map((year) => <option key={year} value={year}>{year}</option>)}</select>}
        {dateKeys.length > 0 && <select className="form-control" value={filters.month} onChange={set('month')}><option value="">All Months</option>{MONTHS.map((month, index) => <option key={month} value={index + 1}>{month}</option>)}</select>}
        {deviceKey && <select className="form-control" value={filters.deviceType} onChange={set('deviceType')}><option value="">All Device Types</option>{deviceTypes.map((type) => <option key={type} value={type}>{type}</option>)}</select>}
        {simKey && <select className="form-control" value={filters.simType} onChange={set('simType')}><option value="">All SIM Types</option>{simTypes.map((type) => <option key={type} value={type}>{type}</option>)}</select>}
        {ownerKey && <select className="form-control" value={filters.ownerType} onChange={set('ownerType')}><option value="">All Owners</option><option value="dealer">Dealer</option><option value="technician">Technician</option></select>}
        {softwareKey && <select className="form-control" value={filters.software || ''} onChange={set('software')}><option value="">All Software</option>{softwareOptions.map((software) => <option key={software} value={software}>{software}</option>)}</select>}
        <button type="button" className="btn btn-outline table-filter-reset" onClick={onReset}>Reset Filters</button>
    </div>;
};

export const emptyTableFilters = () => ({ search: '', year: '', month: '', deviceType: '', simType: '', ownerType: '', software: '' });
export default TableFilterBar;
