import { useState } from 'react';
import { formatDate, getTodayDate, isFutureDate, parseDate } from '../utils/date';

const DateInput = ({ value, onChange, ...props }) => {
    const [focused, setFocused] = useState(false);
    const [error, setError] = useState('');
    const displayValue = focused ? (parseDate(value) || '') : (value ? formatDate(value) : '');

    const handleChange = (event) => {
        const nextValue = parseDate(event.target.value);
        if (isFutureDate(nextValue)) {
            setError('Future dates are not allowed.');
            return;
        }
        setError('');
        onChange(nextValue);
    };

    return (
        <>
            <input
                {...props}
                type={focused ? 'date' : 'text'}
                max={getTodayDate()}
                value={displayValue}
                placeholder="dd-mm-yyyy"
                aria-invalid={Boolean(error)}
                onFocus={() => setFocused(true)}
                onBlur={() => setFocused(false)}
                onChange={handleChange}
            />
            {error && <div className="text-danger">{error}</div>}
        </>
    );
};

export default DateInput;
