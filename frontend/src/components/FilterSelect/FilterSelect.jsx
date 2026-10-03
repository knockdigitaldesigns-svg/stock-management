import React, { useState, useRef, useEffect, useMemo } from 'react';
import './FilterSelect.css';

const FilterSelect = ({
  value,
  onChange,
  children,
  className = '',
  disabled = false,
  'aria-label': ariaLabel
}) => {
  const [open, setOpen] = useState(false);
  const containerRef = useRef(null);

  // Parse children options
  const options = useMemo(() => {
    const list = [];
    React.Children.forEach(children, (child) => {
      if (React.isValidElement(child) && child.type === 'option') {
        list.push({
          value: String(child.props.value ?? ''),
          label: child.props.children
        });
      }
    });
    return list;
  }, [children]);

  const selectedOption = useMemo(() => {
    const valStr = String(value ?? '');
    return options.find((opt) => opt.value === valStr) || options[0] || { value: '', label: '' };
  }, [options, value]);

  useEffect(() => {
    if (!open) return;
    const handleOutsideClick = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) {
        setOpen(false);
      }
    };
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };

    document.addEventListener('mousedown', handleOutsideClick);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handleOutsideClick);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open]);

  const handleSelect = (optVal) => {
    setOpen(false);
    if (onChange) {
      // Simulate standard change event so existing event.target.value handlers work seamlessly
      onChange({
        target: { value: optVal },
        currentTarget: { value: optVal }
      });
    }
  };

  const handleToggle = () => {
    if (disabled) return;
    setOpen((prev) => !prev);
  };

  return (
    <div
      ref={containerRef}
      className={`filter-select-wrapper ${open ? 'is-open' : ''} ${className}`}
    >
      <div
        className={`filter-select-trigger ${open ? 'is-open' : ''}`}
        onClick={handleToggle}
        role="combobox"
        aria-expanded={open}
        aria-label={ariaLabel}
        tabIndex={disabled ? -1 : 0}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handleToggle();
          }
        }}
      >
        <span className="filter-select-label" title={selectedOption.label ? String(selectedOption.label) : ''}>
          {selectedOption.label || ''}
        </span>
        <span className={`filter-select-arrow ${open ? 'open' : ''}`}>▼</span>
      </div>

      {open && (
        <div className="filter-select-menu" role="listbox">
          {options.map((option) => {
            const isSelected = String(option.value) === String(value ?? '');
            return (
              <div
                key={`${option.value}-${option.label}`}
                className={`filter-select-item ${isSelected ? 'is-selected' : ''}`}
                onClick={() => handleSelect(option.value)}
                role="option"
                aria-selected={isSelected}
                title={typeof option.label === 'string' ? option.label : ''}
              >
                {option.label}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
};

export default FilterSelect;
