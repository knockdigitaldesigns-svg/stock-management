import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import './Login.css';

const OTP_LENGTH = 6;

const OtpVerify = () => {
    const navigate = useNavigate();
    const { login } = useAuth();
    const [digits, setDigits] = useState(Array(OTP_LENGTH).fill(''));
    const [pendingToken, setPendingToken] = useState(() => sessionStorage.getItem('otpPendingToken') || '');
    const [remaining, setRemaining] = useState(() => Number(sessionStorage.getItem('otpExpiresIn') || 300));
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [resending, setResending] = useState(false);
    const inputs = useRef([]);

    useEffect(() => {
        if (!pendingToken) {
            navigate('/login', { replace: true });
            return undefined;
        }
        const timer = window.setInterval(() => {
            setRemaining((seconds) => Math.max(0, seconds - 1));
        }, 1000);
        return () => window.clearInterval(timer);
    }, [pendingToken, navigate]);

    const otp = digits.join('');
    const complete = otp.length === OTP_LENGTH;

    const handleDigit = (index, value) => {
        const cleaned = value.replace(/[^0-9]/g, '');
        if (!cleaned) {
            setDigits((current) => current.map((digit, i) => i === index ? '' : digit));
            return;
        }
        if (cleaned.length > 1) {
            const pasted = cleaned.slice(0, OTP_LENGTH - index).split('');
            setDigits((current) => current.map((digit, i) => i >= index && i < index + pasted.length ? pasted[i - index] : digit));
            inputs.current[Math.min(index + pasted.length, OTP_LENGTH - 1)]?.focus();
            return;
        }
        setDigits((current) => current.map((digit, i) => i === index ? cleaned : digit));
        if (index < OTP_LENGTH - 1) inputs.current[index + 1]?.focus();
    };

    const handleKeyDown = (index, event) => {
        if (event.key === 'Backspace' && !digits[index] && index > 0) {
            inputs.current[index - 1]?.focus();
        }
    };

    const handleVerify = async (event) => {
        event.preventDefault();
        if (!complete || remaining <= 0) return;
        setError('');
        setLoading(true);
        try {
            const response = await api.post('/auth/otp_verify.php', {
                pending_token: pendingToken,
                otp
            });
            if (!response.data.success) {
                setError(response.data.message || 'OTP verification failed');
                return;
            }
            const data = response.data.data;
            localStorage.setItem('token', data.token);
            login(data.user, data.permissions || data.user?.permissions || []);
            sessionStorage.removeItem('otpPendingToken');
            sessionStorage.removeItem('otpExpiresIn');
            navigate('/dashboard', { replace: true });
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to verify OTP');
        } finally {
            setLoading(false);
        }
    };

    const handleResend = async () => {
        setError('');
        setResending(true);
        try {
            const response = await api.post('/auth/otp_resend.php', { pending_token: pendingToken });
            if (response.data.success) {
                const newToken = response.data.data.pending_token;
                setPendingToken(newToken);
                sessionStorage.setItem('otpPendingToken', newToken);
                sessionStorage.setItem('otpExpiresIn', '300');
                setRemaining(300);
                setDigits(Array(OTP_LENGTH).fill(''));
                inputs.current[0]?.focus();
            } else {
                setError(response.data.message || 'Unable to resend OTP');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Unable to resend OTP');
        } finally {
            setResending(false);
        }
    };

    const minutes = Math.floor(remaining / 60);
    const seconds = String(remaining % 60).padStart(2, '0');

    return (
        <div className="login-container">
            <div className="login-card otp-card">
                <div className="login-header">
                    <h2>Verify your login</h2>
                    <p>Enter the 6-digit OTP for your account.</p>
                </div>
                {error && <div className="alert alert-danger">{error}</div>}
                {remaining === 0 && <div className="alert alert-danger">OTP has expired. Please resend it.</div>}
                <form onSubmit={handleVerify}>
                    <div className="otp-inputs" onPaste={(event) => {
                        event.preventDefault();
                        handleDigit(0, event.clipboardData.getData('text'));
                    }}>
                        {digits.map((digit, index) => (
                            <input
                                key={index}
                                ref={(element) => { inputs.current[index] = element; }}
                                className="otp-digit"
                                type="text"
                                inputMode="numeric"
                                autoComplete={index === 0 ? 'one-time-code' : 'off'}
                                maxLength={index === 0 ? OTP_LENGTH : 1}
                                value={digit}
                                onChange={(event) => handleDigit(index, event.target.value)}
                                onKeyDown={(event) => handleKeyDown(index, event)}
                                aria-label={`OTP digit ${index + 1}`}
                            />
                        ))}
                    </div>
                    <div className="otp-timer">Expires in <strong>{minutes}:{seconds}</strong></div>
                    <button type="submit" className="btn btn-primary login-btn" disabled={!complete || remaining <= 0 || loading}>
                        {loading ? 'Verifying...' : 'Verify OTP'}
                    </button>
                    <button type="button" className="btn btn-outline otp-resend" disabled={resending} onClick={handleResend}>
                        {resending ? 'Resending...' : 'Resend OTP'}
                    </button>
                </form>
            </div>
        </div>
    );
};

export default OtpVerify;
