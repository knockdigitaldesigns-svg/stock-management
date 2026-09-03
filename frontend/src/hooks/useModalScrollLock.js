import { useEffect } from 'react';

let activeModalCount = 0;
let previousBodyOverflow = '';

const useModalScrollLock = (isOpen) => {
    useEffect(() => {
        if (!isOpen) return undefined;

        if (activeModalCount === 0) {
            previousBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        }
        activeModalCount += 1;

        return () => {
            activeModalCount = Math.max(0, activeModalCount - 1);
            if (activeModalCount === 0) {
                document.body.style.overflow = previousBodyOverflow;
                previousBodyOverflow = '';
            }
        };
    }, [isOpen]);
};

export default useModalScrollLock;
