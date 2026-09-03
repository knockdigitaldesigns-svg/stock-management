import { useState, useRef, useEffect } from 'react';
import './SearchableDropdown.css';

const SearchableDropdown = ({ options, value, onChange, placeholder = "Select an option...", disabled = false }) => {
    const safeOptions = Array.isArray(options) ? options : [];
    const [isOpen, setIsOpen] = useState(false);
    const [searchTerm, setSearchTerm] = useState("");
    const [menuStyle, setMenuStyle] = useState({ top: 0, left: 0, width: 0 });
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const dropdownRef = useRef(null);
    const listRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };

        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    useEffect(() => {
        if (!isOpen || !dropdownRef.current) return;

        const updateMenuPosition = () => {
            const rect = dropdownRef.current.getBoundingClientRect();
            const width = Math.max(rect.width, 220);
            const menuHeight = 280;
            const gap = 8;
            const spaceBelow = window.innerHeight - rect.bottom;
            const top = spaceBelow >= menuHeight ? rect.bottom + gap : Math.max(12, rect.top - menuHeight - gap);
            const left = Math.min(rect.left, window.innerWidth - width - 12);

            setMenuStyle({
                top: `${top}px`,
                left: `${left}px`,
                width: `${width}px`,
            });
        };

        updateMenuPosition();
        window.addEventListener('resize', updateMenuPosition);
        window.addEventListener('scroll', updateMenuPosition, true);
        return () => {
            window.removeEventListener('resize', updateMenuPosition);
            window.removeEventListener('scroll', updateMenuPosition, true);
        };
    }, [isOpen]);

    useEffect(() => {
        setHighlightedIndex(-1);
    }, [searchTerm]);

    const filteredOptions = safeOptions.filter(option =>
        String(option?.label ?? '').toLowerCase().includes(searchTerm.toLowerCase())
    );

    const selectedOption = safeOptions.find(opt => String(opt.value) === String(value));

    const handleSelect = (val) => {
        onChange(val);
        setIsOpen(false);
        setSearchTerm("");
    };

    const handleToggle = () => {
        if (disabled) return;
        setIsOpen(!isOpen);
    };

    const handleKeyDown = (e) => {
        if (!isOpen) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlightedIndex(prev => Math.min(prev + 1, filteredOptions.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlightedIndex(prev => Math.max(prev - 1, 0));
        } else if (e.key === 'Enter' && highlightedIndex >= 0 && highlightedIndex < filteredOptions.length) {
            e.preventDefault();
            handleSelect(filteredOptions[highlightedIndex].value);
        } else if (e.key === 'Escape') {
            setIsOpen(false);
        }
    };

    useEffect(() => {
        if (highlightedIndex >= 0 && listRef.current) {
            const items = listRef.current.querySelectorAll('.dropdown-item:not(.no-results)');
            if (items[highlightedIndex]) {
                items[highlightedIndex].scrollIntoView({ block: 'nearest' });
            }
        }
    }, [highlightedIndex]);

    return (
        <div className="searchable-dropdown" ref={dropdownRef}>
            <div 
                className={`dropdown-header ${isOpen ? 'open' : ''} ${disabled ? 'disabled' : ''}`} 
                onClick={handleToggle}
            >
                <span className={selectedOption ? 'selected-text' : 'placeholder-text'} title={selectedOption ? selectedOption.label : ''}>
                    {selectedOption ? selectedOption.label : placeholder}
                </span>
                <span className="arrow">{isOpen ? '▲' : '▼'}</span>
            </div>

            {isOpen && (
                <div className="dropdown-menu" style={menuStyle}>
                    <input 
                        type="text"
                        className="dropdown-search-input"
                        placeholder="Search..."
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        onKeyDown={handleKeyDown}
                        autoFocus
                    />
                    <ul className="dropdown-list" ref={listRef}>
                        {filteredOptions.length > 0 ? (
                            filteredOptions.map((option, index) => (
                                <li 
                                    key={String(option.value)} 
                                    className={`dropdown-item ${String(option.value) === String(value) ? 'selected' : ''} ${index === highlightedIndex ? 'highlighted' : ''}`}
                                    onClick={() => handleSelect(option.value)}
                                    title={option.label}
                                >
                                    {option.label}
                                </li>
                            ))
                        ) : (
                            <li className="dropdown-item no-results">No options found</li>
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
};

export default SearchableDropdown;
