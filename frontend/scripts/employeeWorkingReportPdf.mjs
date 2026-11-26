import fs from 'node:fs';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

const outputPath = process.argv[2];
if (!outputPath) {
    throw new Error('A PDF output path is required.');
}

const input = [];
for await (const chunk of process.stdin) {
    input.push(chunk);
}
const report = JSON.parse(Buffer.concat(input).toString('utf8'));

const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
const pageWidth = doc.internal.pageSize.getWidth();
const pageHeight = doc.internal.pageSize.getHeight();
const left = 36;
const right = 36;
const bottom = 38;
const headerHeight = 54;
const employee = report.employee || {};
const period = report.period || {};

doc.setProperties({
    title: `Employee Working Report ${employee.employee_code || ''}`,
    author: 'Eagle Trazer CRM',
    subject: `Working activity ${period.start || ''} to ${period.end || ''}`
});

const text = (value) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }
    return String(value);
};

const reportHeader = () => {
    doc.setTextColor(15, 23, 42);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.text('EAGLE TRAZER', left, 24);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.text(
        `${text(employee.name)} · ${text(period.start)} to ${text(period.end)} (${text(period.timezone)})`,
        left,
        39,
        { maxWidth: pageWidth - left - right }
    );
    doc.setDrawColor(203, 213, 225);
    doc.line(left, 46, pageWidth - right, 46);
};

const addSection = (title, columns, rows, y) => {
    let startY = y;
    if (startY > pageHeight - 80) {
        doc.addPage();
        startY = headerHeight;
    }
    doc.setTextColor(15, 23, 42);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.text(title, left, startY);

    const body = rows.length > 0
        ? rows
        : [[{ content: 'No activity', colSpan: columns.length, styles: { halign: 'center' } }]];
    autoTable(doc, {
        head: [columns],
        body,
        startY: startY + 8,
        margin: { top: headerHeight, bottom, left, right },
        styles: {
            font: 'helvetica',
            fontSize: 7,
            cellPadding: 4,
            overflow: 'linebreak',
            valign: 'top'
        },
        headStyles: { fillColor: [30, 64, 175], textColor: [255, 255, 255] },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        rowPageBreak: 'avoid',
        willDrawPage: reportHeader
    });
    return doc.lastAutoTable.finalY + 17;
};

let y = 68;
y = addSection('Employee and Reporting Period', ['Field', 'Value'], [
    ['Employee', text(employee.name)],
    ['Employee ID', text(employee.employee_code)],
    ['Role / Designation', text(employee.role)],
    ['Reporting date', text(period.reporting_date)],
    ['Period start', `${text(period.start)} (${text(period.timezone)})`],
    ['Period end', `${text(period.end)} (${text(period.timezone)})`],
    ['PDF generated', `${text(report.generated_at)} (${text(period.timezone)})`]
], y);

const calls = report.calls || {};
y = addSection('Call Activity', ['Metric', 'Count / Value'], [
    ['Total CRM-recorded call interactions', text(calls.total || 0)],
    ['Connected (CRM follow-up classification)', text(calls.connected || 0)],
    ['Unanswered (outcome: Did not pick)', text(calls.unanswered || 0)],
    ['Other not-connected outcomes', text(calls.other_not_connected || 0)],
    ['Total call duration', 'Not recorded in CRM']
], y);

y = addSection('Call Activity Details', [
    'Time', 'Lead', 'Lead ID', 'Connection', 'Outcome', 'Remarks', 'Duration'
], (calls.rows || []).map((row) => [
    text(row.occurred_at),
    text(row.lead_name),
    text(row.lead_id),
    text(row.connection_result),
    text(row.call_outcome),
    text(row.remarks),
    'Not recorded'
]), y);

const followups = report.followups || {};
y = addSection('Follow-up Status Summary', ['Measure', 'Count', 'Definition'], [
    ['Total follow-up records created in period', text(followups.created_in_period || 0), 'Based on created_at and the stored updated_by_user_id.'],
    ['Completed during period', text(followups.completed_in_period || 0), 'completed_at is within the fixed reporting window.'],
    ['Not completed at period end', text(followups.not_completed_at_period_end || 0), 'Created in period and had no completion before period end.'],
    ['Pending at generation snapshot', text(followups.pending_snapshot || 0), 'Latest active schedule is not yet due.'],
    ['Overdue at generation snapshot', text(followups.overdue_snapshot || 0), 'Latest active schedule due time has passed.']
], y);

y = addSection('Completed Follow-up Details', [
    'Completed at', 'Lead', 'Lead ID', 'Status / Outcome', 'Next follow-up', 'Remarks'
], (followups.completed_rows || []).map((row) => [
    text(row.completed_at),
    text(row.lead_name),
    text(row.lead_id),
    text(row.call_outcome),
    row.next_followup_date
        ? `${text(row.next_followup_date)} ${text(row.next_followup_time)}`
        : '-',
    text(row.remarks)
]), y);

y = addSection('Not Completed at Period End', [
    'Follow-up ID', 'Lead', 'Lead ID', 'Due date', 'Status', 'Remarks'
], (followups.not_completed_rows || []).map((row) => [
    text(row.id),
    text(row.lead_name),
    text(row.lead_id),
    row.next_followup_date
        ? `${text(row.next_followup_date)} ${text(row.next_followup_time)}`
        : '-',
    text(row.period_status),
    text(row.remarks)
]), y);

y = addSection('Pending Follow-ups (Generation Snapshot)', [
    'Follow-up ID', 'Lead', 'Lead ID', 'Due date', 'Remarks'
], (followups.pending_rows || []).map((row) => [
    text(row.id),
    text(row.lead_name),
    text(row.lead_id),
    text(row.due_at),
    text(row.remarks)
]), y);

y = addSection('Overdue Follow-ups (Generation Snapshot)', [
    'Follow-up ID', 'Lead', 'Lead ID', 'Due date', 'Remarks'
], (followups.overdue_rows || []).map((row) => [
    text(row.id),
    text(row.lead_name),
    text(row.lead_id),
    text(row.due_at),
    text(row.remarks)
]), y);

const leads = report.leads || {};
y = addSection('Lead Activity Summary', ['Activity', 'Count'], [
    ['Distinct leads contacted in period', text(leads.contacted || 0)],
    ['New leads created by employee', text(leads.created || 0)],
    ['Lead assignments received', text(leads.assigned_to_employee || 0)],
    ['Lead assignments made', text(leads.assigned_by_employee || 0)],
    ['Transfers performed', text(leads.transfers_made || 0)],
    ['Transfers received / sent', `${text(leads.transfers_received || 0)} / ${text(leads.transfers_sent || 0)}`],
    ['Lead status/stage updates recorded', text(leads.status_updates_recorded || 0)],
    ['Distinct leads requiring follow-up now', text(leads.requiring_followup || 0)]
], y);

y = addSection('New Leads Created', ['Created at', 'Lead', 'Lead ID'], (leads.new_rows || []).map((row) => [
    text(row.created_at),
    text(row.lead_name),
    text(row.id)
]), y);

y = addSection('Lead Assignments Received', ['Assigned at', 'Lead', 'Lead ID', 'Type', 'Assigned by'], (leads.assigned_to_rows || []).map((row) => [
    text(row.assigned_at),
    text(row.lead_name),
    text(row.lead_id),
    text(row.assignment_type),
    text(row.assigned_by)
]), y);

y = addSection('Lead Assignments Made', ['Assigned at', 'Lead', 'Lead ID', 'Type', 'Assigned to'], (leads.assigned_by_rows || []).map((row) => [
    text(row.assigned_at),
    text(row.lead_name),
    text(row.lead_id),
    text(row.assignment_type),
    text(row.assigned_to)
]), y);

y = addSection('Lead Transfers Performed', ['Transferred at', 'Lead', 'Lead ID', 'From', 'To', 'Type'], (leads.transfer_rows || []).map((row) => [
    text(row.transferred_at),
    text(row.lead_name),
    text(row.lead_id),
    text(row.previous_employee),
    text(row.new_employee),
    text(row.transfer_type)
]), y);

y = addSection('Recorded Lead Status / Stage Changes', ['Changed at', 'Field', 'Lead ID'], (leads.status_update_rows || []).map((row) => [
    text(row.changed_at),
    text(row.field),
    text(row.lead_id)
]), y);

addSection('Data Notes and Limitations', ['Notes'], (report.data_notes || []).map((note) => [text(note)]), y);

const pageCount = doc.internal.getNumberOfPages();
for (let page = 1; page <= pageCount; page += 1) {
    doc.setPage(page);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7);
    doc.setTextColor(100, 116, 139);
    doc.text(
        `Eagle Trazer CRM · Generated ${text(report.generated_at)} · Page ${page} of ${pageCount}`,
        pageWidth - right,
        pageHeight - 18,
        { align: 'right', maxWidth: pageWidth - left - right }
    );
}

fs.writeFileSync(outputPath, Buffer.from(doc.output('arraybuffer')));
