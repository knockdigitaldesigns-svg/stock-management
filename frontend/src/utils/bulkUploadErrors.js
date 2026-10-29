export const normalizeBulkUploadErrors = (payload, fallback = 'Upload failed.') => {
    let response = payload;
    if (typeof response === 'string') {
        try {
            response = JSON.parse(response);
        } catch {
            return response.trim() ? response.trim().split('\n').filter(Boolean) : [fallback];
        }
    }
    if (Array.isArray(response)) {
        return response.map((error) => String(error?.message ?? error ?? '')).filter(Boolean);
    }

    const data = response?.data ?? response;
    const structured = data?.validation_error_details;
    if (Array.isArray(structured) && structured.length) {
        return structured.map((error) => {
            if (!error || typeof error !== 'object') return String(error ?? '');
            let message = String(error.message ?? '');
            if (error.row_number != null && !message.startsWith(`Row ${error.row_number}:`)) {
                message = `Row ${error.row_number}: ${message}`;
            }
            if (error.field && !message.toLowerCase().includes(String(error.field).toLowerCase())) {
                const rowPrefix = message.match(/^Row \d+:\s*/i)?.[0] ?? '';
                message = `${rowPrefix}${error.field} - ${message.slice(rowPrefix.length)}`;
            }
            if (error.value != null && !message.includes(String(error.value))) {
                message = `${message} Value: '${error.value}'.`;
            }
            return message;
        }).filter(Boolean);
    }

    for (const candidate of [data?.validation_errors, data?.errors, response?.errors]) {
        if (Array.isArray(candidate)) {
            const messages = candidate.map((error) => String(error?.message ?? error ?? '')).filter(Boolean);
            if (messages.length) return messages;
        }
        if (Array.isArray(data?.row_errors)) {
            const messages = data.row_errors.map((error) => String(error?.message ?? error ?? '')).filter(Boolean);
            if (messages.length) return messages;
        }
        if (typeof candidate === 'string' && candidate.trim()) {
            return candidate.split('\n').map((error) => error.trim()).filter(Boolean);
        }
    }

    const message = data?.message ?? response?.message ?? response?.error;
    if (Array.isArray(message)) return message.map(String).filter(Boolean);
    if (typeof message === 'string' && message.trim()) {
        return message.split('\n').map((error) => error.trim()).filter(Boolean);
    }
    return [fallback];
};
