<?php
function normalizeEmployeeUsername(string $employeeName): string
{
    return strtolower(preg_replace('/\s+/', '', trim($employeeName)));
}
?>
