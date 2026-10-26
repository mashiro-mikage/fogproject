<?php
/**
 * Target-v2 operation selection is pure policy: it never calls a native
 * directory API. Keep these checks on the real selector and the repository's
 * ordinary fake-DB harness so absence, lookup failure and authority stay
 * distinct at the decision boundary.
 *
 * Usage: php tests/agent-directory-operation.test.php
 */

require __DIR__ . '/lib/fog-test-harness.php';

FogTestHarness::boot('agent-directory-operation');
FogTestHarness::fakeDb();

$t = new FogChecks();

function directoryOperationObservation(array $overrides = [])
{
    return array_replace([
        'role' => 'workstation',
        'dc_reachable' => true,
        'secure_channel' => 'true',
        'current_name' => 'A',
        'pending_name' => 'A',
        'domain' => 'corp.example',
        'netbios' => 'CORP',
        'dc' => 'dc1.corp.example',
        'boot' => ['marker' => 'boot-1', 'started' => '2026-10-25T00:00:00Z']
    ], $overrides);
}

function directoryOperationTarget($status, array $record = [])
{
    $target = ['status' => $status];
    if ('found' === $status) {
        $target['record'] = $record + [
            'guid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'sid' => 'S-1-5-21-1-1001',
            'account' => 'C$'
        ];
    }
    return $target;
}

function directoryOperationBlocked(callable $call)
{
    try {
        $call();
        return null;
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

$select = function ($o, $name, $clone, $reassigned, $target, $allowCreate, $confirmed, $authorized) {
    return \FOG\Agent\DirectoryOperation::select(
        $o, $name, $clone, $reassigned, $target, $allowCreate, $confirmed, $authorized
    );
};

// R1: an online, healthy member may use the existing Rename path only when
// the requested AD name is absent and no other FOG host owns the old name.
$renameR1 = $select(
    directoryOperationObservation(), 'C', 'none', false,
    directoryOperationTarget('absent'), false, false, false
);
$t->check('R1 selects a normal rename for healthy, unclaimed host with absent target',
    ['operation' => 'rename', 'mode' => 'rename', 'target' => null] === $renameR1);

// R2: a locally pending desired name is compatible with the same Rename
// operation; the selector does not call it complete or verified.
$renameR2 = $select(
    directoryOperationObservation(['pending_name' => 'C']), 'C', 'none', false,
    directoryOperationTarget('absent'), false, false, false
);
$t->check('R2 keeps pending desired rename on the normal rename operation',
    'rename' === ($renameR2['operation'] ?? null));

// A reused existing account remains an explicitly confirmed Target Rebind.
$accountA = [
    'guid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    'sid' => 'S-1-5-21-1-1002',
    'account' => 'A$'
];
$reuseA = $select(
    directoryOperationObservation(['current_name' => 'B']), 'A', 'none', false,
    directoryOperationTarget('found', $accountA), false, true, false
);
$t->check('existing A account is selected as confirmed Target Rebind',
    'rebind' === ($reuseA['operation'] ?? null)
        && 'existing' === ($reuseA['mode'] ?? null)
        && $accountA === ($reuseA['target'] ?? null));

// R3/R4: once old name A is assigned to another FOG host, even a healthy
// machine currently called A must not rename that account to C.
$handoffC = $select(
    directoryOperationObservation(), 'C', 'none', true,
    directoryOperationTarget('absent'), true, false, false
);
$t->check('reassigned old name forces Rebind to C even with healthy trust',
    'rebind' === ($handoffC['operation'] ?? null)
        && 'create' === ($handoffC['mode'] ?? null));

// D3: target-v2 selection has no Source identity input. Same-name, authorized
// recovery can select its confirmed Target without rejecting Source=Target.
$selfTarget = [
    'guid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
    'sid' => 'S-1-5-21-1-1003',
    'account' => 'A$'
];
$d3 = $select(
    directoryOperationObservation(['secure_channel' => 'false']), 'A', 'none', false,
    directoryOperationTarget('found', $selfTarget), false, true, true
);
$t->check('authorized same-name D3 recovery selects Target Rebind without Source identity',
    'rebind' === ($d3['operation'] ?? null)
        && 'existing' === ($d3['mode'] ?? null)
        && $selfTarget === ($d3['target'] ?? null));

$t->check('same-name broken-trust recovery requires explicit authorization',
    'directory_recovery_confirmation_required' === directoryOperationBlocked(function () use ($select, $selfTarget) {
        $select(directoryOperationObservation(['secure_channel' => 'false']), 'A', 'none', false,
            directoryOperationTarget('found', $selfTarget), false, true, false);
    }));

// Explicit absent permits create; an omitted setting keeps the established
// default. A configured lookup error is never treated as absence.
$t->check('create permission defaults on through the existing helper',
    true === \FOG\Agent\DirectoryRejoin::allowsCreate([]));
$t->check('create permission can be explicitly disabled',
    false === \FOG\Agent\DirectoryRejoin::allowsCreate(['allow_create' => false]));
$t->check('healthy non-clone rename remains allowed without create permission',
    'rename' === ($select(directoryOperationObservation(), 'C', 'none', false,
        directoryOperationTarget('absent'), false, false, false)['operation'] ?? null));
$t->check('D1 clone with default create permission selects Rebind create',
    'create' === ($select(directoryOperationObservation(['current_name' => 'B', 'pending_name' => 'B']), 'B', 'detected', false,
        directoryOperationTarget('absent'), \FOG\Agent\DirectoryRejoin::allowsCreate([]), false, false)['mode'] ?? null));
$t->check('absent target without create permission is blocked',
    'rejoin_create_not_permitted' === directoryOperationBlocked(function () use ($select) {
        $select(directoryOperationObservation(['current_name' => 'B', 'pending_name' => 'B']), 'B', 'detected', false,
            directoryOperationTarget('absent'), false, false, false);
    }));
$t->check('unconfigured LDAP does not prevent an otherwise safe normal rename',
    'rename' === ($select(directoryOperationObservation(), 'C', 'none', false,
        directoryOperationTarget('unconfigured'), false, false, false)['operation'] ?? null));
$t->check('configured LDAP lookup error is not absence or create permission',
    'rejoin_lookup_failed' === directoryOperationBlocked(function () use ($select) {
        $select(directoryOperationObservation(), 'C', 'none', false,
            directoryOperationTarget('error'), true, false, false);
    }));

$t->check('unknown trust stops before selecting any native operation',
    'directory_observation_unknown' === directoryOperationBlocked(function () use ($select) {
        $select(directoryOperationObservation(['secure_channel' => 'unknown']), 'C', 'none', false,
            directoryOperationTarget('absent'), true, false, false);
    }));
$t->check('unreachable DC stops before treating Target as absent',
    'directory_observation_unknown' === directoryOperationBlocked(function () use ($select) {
        $select(directoryOperationObservation(['dc_reachable' => false]), 'C', 'none', false,
            directoryOperationTarget('absent'), true, false, false);
    }));
$t->check('pending name conflicting with desired name stops',
    'directory_pending_name_conflict' === directoryOperationBlocked(function () use ($select) {
        $select(directoryOperationObservation(['pending_name' => 'B']), 'C', 'none', false,
            directoryOperationTarget('absent'), true, false, false);
    }));
$t->check('existing Target without confirmation stops',
    'rejoin_target_confirmation_required' === directoryOperationBlocked(function () use ($select, $accountA) {
        $select(directoryOperationObservation(['current_name' => 'B']), 'A', 'none', false,
            directoryOperationTarget('found', $accountA), true, false, false);
    }));

$t->finish();
