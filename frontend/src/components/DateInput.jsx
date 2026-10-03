import { useLayoutEffect, useRef, useState } from 'react';
import { formatDate, getTodayDate, isFutureDate, parseDate } from '../utils/date';

const DateInput = ({ value, onChange, ...props }) => {
    const [focused, setFocused] = useState(false);
    const [error, setError] = useState('');
    const handleChange = (event) => {
        const nextValue = parseDate(event.target.value);
        if (!nextValue) return;
        if (isFutureDate(nextValue)) {
            setError('Future dates are not allowed.');
            return;
        }
        setError('');
        onChange(nextValue);
    };
    const dateInputRef = useRef(null);

    useLayoutEffect(() => {
        if (focused) dateInputRef.current?.focus();
    }, [focused]);

    return (
        <>
            {focused ? (
                <input
                    {...props}
                    ref={dateInputRef}
                    type="date"
                    max={getTodayDate()}
                    defaultValue={parseDate(value) || ''}
                    aria-invalid={Boolean(error)}
                    onBlur={(event) => {
                        if (!event.target.value && value) onChange('');
                        setFocused(false);
                    }}
                    onChange={handleChange}
                />
            ) : (
                <input
                    {...props}
                    type="text"
                    value={value ? formatDate(value) : ''}
                    placeholder="dd-mm-yyyy"
                    aria-invalid={Boolean(error)}
                    onFocus={() => setFocused(true)}
                />
            )}
            {error && <div className="text-danger">{error}</div>}
        </>
    );
};

export default DateInput;
