<?php

function buildValidationErrorDetails(array $errors): array
{
    $details = [];
    foreach (array_values(array_unique(array_map('strval', $errors))) as $error) {
        $rowNumber = null;
        $field = null;
        $value = null;
        $message = $error;

        if (preg_match('/^Row\s+(\d+):\s*(.*)$/i', $error, $rowMatch)) {
            $rowNumber = (int) $rowMatch[1];
            $message = $rowMatch[2];
            if (preg_match('/^(.+?)\s+-\s+(.*)$/', $message, $fieldMatch)) {
                $field = trim($fieldMatch[1]);
            } elseif (preg_match('/^([A-Za-z][A-Za-z0-9 #\/_-]{0,60}):\s+(.*)$/', $message, $fieldMatch)) {
                $field = trim($fieldMatch[1]);
            } elseif (preg_match('/^(SIM No 1|SIM No 2|SIM Number|SIM No|Device IMEI|IMEI No|Device Date|SIM Date|Purchase Date|Activation Date|Validity|Technician Name|Technician|Dealer Name|Dealer|Vehicle No|Vehicle Type|Device Model|Installation Person Type|Installation Person|Lead Closure By|Email|Pincode|Primary Mobile No|Secondary Mobile No|Transaction ID|Payment Mode|Allocation Type|Software|Total Amount|Amount Paid)\b/i', $message, $fieldMatch)) {
                $field = $fieldMatch[1];
            }
            if (preg_match('/[\'"]([^\'"]+)[\'"]/', $message, $valueMatch)) {
                $value = $valueMatch[1];
            }
        }

        $details[] = [
            'row_number' => $rowNumber,
            'field' => $field,
            'value' => $value,
            'message' => $error
        ];
    }
    return $details;
}

function sendValidationErrorResponse(string $message, array $errors, int $statusCode = 400, array $extraData = []): void
{
    $uniqueErrors = array_values(array_unique(array_map('strval', $errors)));
    $responseMessage = $uniqueErrors ? $message . "\n" . implode("\n", $uniqueErrors) : $message;
    sendResponse(false, $responseMessage, array_merge($extraData, [
        'errors' => $uniqueErrors,
        'validation_error_details' => buildValidationErrorDetails($uniqueErrors),
        'validation_error_count' => count($uniqueErrors)
    ]), [], $statusCode);
}
