export const sanitizeCustomerField = (field, value) => {
    if (field === 'primary_mobile_no' || field === 'secondary_mobile_no') {
        return value.replace(/\D/g, '').slice(0, 10);
    }
    if (field === 'pincode') {
        return value.replace(/\D/g, '').slice(0, 6);
    }
    if (field === 'username') {
        return value.replace(/\s/g, '').slice(0, 100);
    }
    if (field === 'email' || field === 'location') {
        return value.slice(0, 150);
    }
    return value;
};

export const getCustomerFieldErrors = (form, showRequired = false, includeStatus = false) => ({
    platform_id: showRequired && !form.platform_id ? 'Platform is required.' : '',
    username: !String(form.username || '').trim()
        ? (showRequired ? 'Username is required.' : '')
        : String(form.username).trim().length > 100
            ? 'Username must not exceed 100 characters.'
        : !/^[a-zA-Z0-9.@_-]+$/.test(String(form.username).trim())
            ? 'Username can contain only letters, numbers, @, dot, underscore and hyphen.'
            : '',
    primary_mobile_no: !form.primary_mobile_no
        ? (showRequired ? 'Primary mobile number is required.' : '')
        : !/^\d{10}$/.test(String(form.primary_mobile_no))
            ? 'Primary mobile number must contain exactly 10 digits.'
            : '',
    secondary_mobile_no: form.secondary_mobile_no && !/^\d{10}$/.test(String(form.secondary_mobile_no))
        ? 'Secondary mobile number must contain exactly 10 digits.'
        : form.secondary_mobile_no === form.primary_mobile_no
            ? 'Secondary mobile number cannot be the same as primary mobile number.'
            : '',
    email: form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(form.email))
        ? 'Please enter a valid email address.'
        : '',
    location: showRequired && !String(form.location || '').trim() ? 'Location is required.' : '',
    pincode: !form.pincode
        ? (showRequired ? 'Pincode is required.' : '')
        : !/^\d{6}$/.test(String(form.pincode))
            ? 'Pincode must contain exactly 6 digits.'
            : '',
    ...(includeStatus ? {
        status: ['Active', 'Inactive'].includes(form.status) ? '' : 'Invalid customer status.'
    } : {})
});

export const validateCustomerForm = (form, includeStatus = false) => {
    const errors = getCustomerFieldErrors(form, true, includeStatus);
    if (!form.platform_id) return 'Please select a platform.';
    if (errors.username === 'Username is required.') return errors.username;
    if (!form.primary_mobile_no) return 'Primary mobile number is required.';
    if (!String(form.location || '').trim()) return 'Location is required.';
    if (!form.pincode) return 'Pincode is required.';
    if (errors.platform_id) return errors.platform_id;
    if (errors.username) return errors.username;
    if (errors.primary_mobile_no) return errors.primary_mobile_no;
    if (errors.secondary_mobile_no) return errors.secondary_mobile_no;
    if (errors.email) return errors.email;
    if (errors.location) return errors.location;
    if (errors.pincode) return errors.pincode;
    if (errors.status) return errors.status;
    return '';
};
