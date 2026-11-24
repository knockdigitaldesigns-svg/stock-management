import assert from 'node:assert/strict';
import test from 'node:test';

import {
    getInstallationLocationPriority,
    formatInstallerOption,
    sortInstallersByLocation
} from '../src/utils/installationLocation.js';

test('location priority supports normalized exact values in delimited locations', () => {
    assert.equal(getInstallationLocationPriority('  Coimbatore  ', 'coimbatore'), 0);
    assert.equal(getInstallationLocationPriority(' Coimbatore ; Pollachi\nDharapuram ', 'dharapuram'), 0);
    assert.equal(getInstallationLocationPriority('Coimbatore, Pollachi', 'Coimbatore'), 0);
    assert.equal(getInstallationLocationPriority('Pollachi', 'Coimbatore'), 1);
    assert.equal(getInstallationLocationPriority('Coimbatore East', 'Coimbatore'), 1);
    assert.equal(getInstallationLocationPriority('', 'Coimbatore'), 1);
});

test('location group sorts before names and names sort alphabetically within each group', () => {
    const installers = [
        { person_id: 1, person_type: 'Onsite Dealer', person_name: 'Syscaretpr-Dharapuram', location: 'Pudukkottai' },
        { person_id: 2, person_type: 'Technician', person_name: 'alan', location: 'Chennai' },
        { person_id: 3, person_type: 'Onsite Dealer', person_name: 'dewhjd', location: 'Coimbatore' },
        { person_id: 4, person_type: 'Technician', person_name: 'Zed', location: 'Dharapuram' },
        { person_id: 5, person_type: 'Onsite Dealer', person_name: 'Aaron', location: 'Coimbatore; Dharapuram' }
    ];

    assert.deepEqual(
        sortInstallersByLocation(installers, 'Dharapuram').map((person) => person.person_id),
        [5, 4, 2, 3, 1]
    );
    assert.deepEqual(
        sortInstallersByLocation(installers, 'Dharapuram').map((person) =>
            formatInstallerOption(person, 'Dharapuram')
        ),
        [
            'Onsite Dealer — Aaron — Coimbatore; Dharapuram — Same location',
            'Technician — Zed — Dharapuram — Same location',
            'Technician — alan — Chennai — Other location',
            'Onsite Dealer — dewhjd — Coimbatore — Other location',
            'Onsite Dealer — Syscaretpr-Dharapuram — Pudukkottai — Other location'
        ]
    );
});
