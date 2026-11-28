import { useState } from 'react';
import api from '../../services/api';

const TransactionIdLookupPage = () => {
    const [transactionId, setTransactionId] = useState('');
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const lookupTransactionId = async (event) => {
        event.preventDefault();
        setError('');
        setResult(null);

        if (!/^\d{6}$/.test(transactionId)) {
            setError('Enter a 6-digit transaction ID.');
            return;
        }

        setLoading(true);
        try {
            const response = await api.get('/customers/payments/lookup_transaction_id.php', {
                params: { transaction_id: transactionId }
            });
            if (!response.data?.success) {
                throw new Error(response.data?.message || 'Unable to look up this transaction ID.');
            }
            setResult(response.data.data);
        } catch (lookupError) {
            setError(
                lookupError.response?.data?.message ||
                lookupError.message ||
                'Unable to look up this transaction ID.'
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="page-container">
            <div className="page-header">
                <div>
                    <h1>Transaction ID Lookup</h1>
                    <p>Find which payment record is using a transaction ID.</p>
                </div>
            </div>

            <section className="card" style={{ maxWidth: '720px', padding: '1.25rem' }}>
                <form onSubmit={lookupTransactionId}>
                    <div className="form-group">
                        <label className="form-label" htmlFor="transaction-id-lookup">
                            Transaction ID
                        </label>
                        <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
                            <input
                                id="transaction-id-lookup"
                                className="form-control"
                                style={{ flex: '1 1 220px' }}
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={6}
                                value={transactionId}
                                onChange={(event) => {
                                    setTransactionId(event.target.value.replace(/\D/g, '').slice(0, 6));
                                    setResult(null);
                                    setError('');
                                }}
                                placeholder="Enter 6 digits"
                                aria-describedby="transaction-id-help"
                            />
                            <button
                                className="btn btn-primary"
                                type="submit"
                                disabled={loading || transactionId.length !== 6}
                            >
                                {loading ? 'Searching...' : 'Search'}
                            </button>
                        </div>
                        <small id="transaction-id-help">Searches registered payments and pending customer installments.</small>
                    </div>
                </form>

                {error && <div className="alert alert-danger" role="alert">{error}</div>}

                {result && !result.used && (
                    <div className="alert alert-success" role="status">
                        Transaction ID {result.transaction_id} is not recorded as used.
                    </div>
                )}

                {result?.used && (
                    <div aria-live="polite">
                        <div className="alert alert-warning">
                            Transaction ID {result.transaction_id} is already in use.
                        </div>
                        <div style={{ display: 'grid', gap: '0.75rem' }}>
                            {result.matches.map((match, index) => (
                                <article
                                    className="card"
                                    key={`${match.source_type}-${match.status}-${match.created_at}-${index}`}
                                    style={{ padding: '1rem' }}
                                >
                                    <h3 style={{ marginTop: 0 }}>{match.source_label}</h3>
                                    <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem', margin: 0 }}>
                                        <div>
                                            <dt>{match.subject_type || 'Customer / Owner'}</dt>
                                            <dd>{match.subject_name || 'Name not available'}</dd>
                                        </div>
                                        {match.amount !== undefined && (
                                            <div>
                                                <dt>Amount</dt>
                                                <dd>₹{Number(match.amount).toFixed(2)}</dd>
                                            </div>
                                        )}
                                        <div>
                                            <dt>Status</dt>
                                            <dd>{match.status}</dd>
                                        </div>
                                        <div>
                                            <dt>Recorded At</dt>
                                            <dd>{match.created_at || 'Not available'}</dd>
                                        </div>
                                    </dl>
                                </article>
                            ))}
                        </div>
                    </div>
                )}
            </section>
        </div>
    );
};

export default TransactionIdLookupPage;
