import { MessageCircle } from 'lucide-react';
import { normalizeWhatsAppNumber } from '../../utils/whatsapp';
import './WhatsAppButton.css';

const WhatsAppButton = ({ mobileNumber, recordName, className = '' }) => {
    const phone = normalizeWhatsAppNumber(mobileNumber);
    const buttonClassName = `${className} whatsapp-icon-button`.trim();
    const accessibleName = recordName ? `WhatsApp ${recordName}` : 'WhatsApp';

    if (!phone) {
        return (
            <button
                type="button"
                className={buttonClassName}
                title="No valid mobile number"
                aria-label={`WhatsApp unavailable for ${recordName || 'this record'}`}
                disabled
            >
                <MessageCircle size={16} aria-hidden="true" />
            </button>
        );
    }

    return (
        <a
            className={buttonClassName}
            href={`https://wa.me/${phone}`}
            target="_blank"
            rel="noopener noreferrer"
            title="WhatsApp"
            aria-label={accessibleName}
        >
            <MessageCircle size={16} aria-hidden="true" />
        </a>
    );
};

export default WhatsAppButton;
