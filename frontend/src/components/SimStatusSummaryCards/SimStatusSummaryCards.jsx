import { Activity, Archive, Ban, Clock3 } from 'lucide-react';
import './SimStatusSummaryCards.css';

const SIM_STATUSES = [
    { key: 'active_sims', label: 'Active SIMs', status: 'Active', icon: Activity, color: 'active' },
    { key: 'safe_custody_sims', label: 'Safe Custody SIMs', status: 'Safe Custody', icon: Archive, color: 'custody' },
    { key: 'expired_sims', label: 'Expired SIMs', status: 'Expired', icon: Clock3, color: 'expired' },
    { key: 'deactivated_sims', label: 'Deactivated SIMs', status: 'Deactive', icon: Ban, color: 'deactivated' }
];

const SimStatusSummaryCards = ({ counts, loading, error, onSelectStatus, selectedStatus = '' }) => (
    <section className="sim-status-summary-grid" aria-label="SIM status summary">
        {SIM_STATUSES.map(({ key, label, status, icon: Icon, color }) => {
            const selected = selectedStatus === status;
            const className = `sim-status-summary-card${onSelectStatus ? ' is-selectable' : ''}${selected ? ' is-selected' : ''}`;
            const contents = (
                <>
                    <div className={`sim-status-summary-icon ${color}`}>
                        <Icon size={21} />
                    </div>
                    <div>
                        <p className="sim-status-summary-label">{label}</p>
                        <p className="sim-status-summary-value" aria-live="polite">
                            {loading ? '…' : error ? '—' : Number(counts?.[key] ?? 0).toLocaleString()}
                        </p>
                    </div>
                </>
            );
            return onSelectStatus ? (
                <button
                    className={className}
                    key={key}
                    type="button"
                    onClick={() => onSelectStatus(selected ? '' : status)}
                    aria-pressed={selected}
                    title={`Filter renewals by ${status}${selected ? ' (click to clear)' : ''}`}
                >
                    {contents}
                </button>
            ) : (
                <article className={className} key={key}>{contents}</article>
            );
        })}
        {error && <p className="sim-status-summary-error" role="alert">{error}</p>}
    </section>
);

export default SimStatusSummaryCards;
