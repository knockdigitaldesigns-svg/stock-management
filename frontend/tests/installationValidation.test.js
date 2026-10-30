import assert from 'node:assert/strict';
import test from 'node:test';

import {
    isInstallationDetailsComplete,
    isInstallationPersonRequired,
    mergeVehicleDraft,
    resolveInstallationFormValues
} from '../src/utils/installationValidation.js';

test('Direct installation is complete without an Installation Person', () => {
    const installation = {
        installation_person_type: 'Direct',
        installation_person_id: '',
        lead_closure_id: '4',
        installation_date: '2026-10-28'
    };

    assert.equal(isInstallationPersonRequired('Direct'), false);
    assert.equal(isInstallationDetailsComplete(installation), true);
});

test('non-Direct installation requires an Installation Person', () => {
    assert.equal(isInstallationPersonRequired('Technician'), true);
    assert.equal(isInstallationDetailsComplete({
        installation_person_type: 'Technician',
        installation_person_id: '',
        lead_closure_id: '4',
        installation_date: '2026-10-28'
    }), false);
    assert.equal(isInstallationDetailsComplete({
        installation_person_type: 'Onsite Dealer',
        installation_person_id: '12',
        lead_closure_id: '4',
        installation_date: '2026-10-28'
    }), true);
});

test('edit hydration uses saved type, person, lead closure, and date', () => {
    assert.deepEqual(resolveInstallationFormValues({
        stored: {},
        installation: {
            installation_person_type: 'Dealer',
            installation_person_id: 12,
            installation_type: 'Offsite Dealer',
            lead_closure_id: 4,
            installation_date: '2026-10-28'
        },
        ownerType: 'Technician',
        ownerId: 99
    }), {
        installation_person_type: 'Offsite Dealer',
        installation_person_id: 12,
        lead_closure_id: 4,
        installation_date: '2026-10-28'
    });
});

test('Direct edit hydration keeps its person empty', () => {
    assert.deepEqual(resolveInstallationFormValues({
        installation: {
            installation_person_type: 'Direct',
            installation_person_id: null,
            installation_type: 'Direct',
            lead_closure_id: 4,
            installation_date: '2026-10-28'
        },
    }), {
        installation_person_type: 'Direct',
        installation_person_id: '',
        lead_closure_id: 4,
        installation_date: '2026-10-28'
    });
});

test('unrelated edit drafts do not replace saved installation values', () => {
    const persistedVehicle = {
        vehicle_installation_type: 'Onsite Dealer',
        vehicle_installation_person_id: 12,
        vehicle_lead_closure_id: 4,
        vehicle_installation_date: '2026-10-28'
    };

    const merged = mergeVehicleDraft(persistedVehicle, {
        vehicle_installation_type: '',
        vehicle_installation_person_id: '',
        vehicle_lead_closure_id: '',
        vehicle_installation_date: '',
        changed: { paymentPart1: true }
    });

    assert.equal(merged.vehicle_installation_type, persistedVehicle.vehicle_installation_type);
    assert.equal(merged.vehicle_installation_person_id, persistedVehicle.vehicle_installation_person_id);
    assert.equal(merged.vehicle_lead_closure_id, persistedVehicle.vehicle_lead_closure_id);
    assert.equal(merged.vehicle_installation_date, persistedVehicle.vehicle_installation_date);
    assert.deepEqual(merged.changed, { paymentPart1: true });
});

test('installation drafts preserve intentional Direct values', () => {
    assert.deepEqual(mergeVehicleDraft({
        vehicle_installation_type: 'Technician',
        vehicle_installation_person_id: 12
    }, {
        vehicle_installation_type: 'Direct',
        vehicle_installation_person_id: '',
        changed: { installation: true }
    }), {
        vehicle_installation_type: 'Direct',
        vehicle_installation_person_id: '',
        changed: { installation: true }
    });
});
