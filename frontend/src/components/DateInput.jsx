import { useRef, useState } from 'react';
import { CalendarDays } from 'lucide-react';
import { formatDate, getTodayDate, isFutureDate, parseDate } from '../utils/date';

const DateInput = ({ value, onChange, onBlur, name, allowFuture = false, min, max, ...props }) => {
    const sourceValue = value instanceof Date ? value.getTime() : String(value ?? '');
    const [draft, setDraft] = useState(() => ({
        sourceValue,
        text: value ? formatDate(value) : ''
    }));
    const text = draft.sourceValue === sourceValue ? draft.text : value ? formatDate(value) : '';
    const [error, setError] = useState('');
    const dateInputRef = useRef(null);
    const isoValue = parseDate(value) || '';
    const maxDate = max ?? (allowFuture ? undefined : getTodayDate());
    const isDisabled = Boolean(props.disabled || props.readOnly);

    const updateText = (nextText) => setDraft({ sourceValue, text: nextText });

    const commitDate = (nextValue) => {
        if (!nextValue) {
            setError('');
            updateText('');
            onChange('');
            return true;
        }
        const parsed = parseDate(nextValue);
        if (!parsed) return false;
        if (min && parsed < min) {
            setError(`Date must be on or after ${formatDate(min)}.`);
            return false;
        }
        if (max && parsed > max) {
            setError(`Date must be on or before ${formatDate(max)}.`);
            return false;
        }
        if (!allowFuture && isFutureDate(parsed)) {
            setError('Future dates are not allowed.');
            return false;
        }
        setError('');
        updateText(formatDate(parsed));
        onChange(parsed);
        return true;
    };

    return (
        <>
            <div className="date-input-control" style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                <input
                    {...props}
                    type="text"
                    style={{ ...props.style, flex: '1 1 auto', minWidth: 0 }}
                    inputMode="numeric"
                    value={text}
                    placeholder="dd-mm-yyyy"
                    aria-invalid={Boolean(error)}
                    onBlur={(event) => {
                        if (!text) {
                            if (value) onChange('');
                            updateText('');
                            setError('');
                        } else if (!commitDate(text)) {
                            setError('Enter a valid date in DD-MM-YYYY format.');
                        }
                        onBlur?.(event);
                    }}
                    onChange={(event) => {
                        const nextText = event.target.value;
                        updateText(nextText);
                        setError('');
                        if (parseDate(nextText)) commitDate(nextText);
                    }}
                />
                <button
                    type="button"
                    className="btn btn-outline"
                    aria-label="Choose date"
                    disabled={isDisabled}
                    onClick={() => {
                        const picker = dateInputRef.current;
                        if (typeof picker?.showPicker === 'function') picker.showPicker();
                        else picker?.click();
                    }}
                    style={{ flex: '0 0 auto', padding: '0.45rem' }}
                >
                    <CalendarDays size={16} />
                </button>
                <input
                    ref={dateInputRef}
                    type="date"
                    name={name}
                    value={isoValue}
                    min={min}
                    max={maxDate}
                    disabled={props.disabled}
                    aria-hidden="true"
                    tabIndex={-1}
                    onChange={(event) => commitDate(event.target.value)}
                    style={{ position: 'fixed', width: 1, height: 1, opacity: 0, pointerEvents: 'none' }}
                />
            </div>
            {error && <div className="text-danger">{error}</div>}
        </>
    );
};

export default DateInput;
