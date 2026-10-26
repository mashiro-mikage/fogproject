<?php
/** Target-only Windows directory operations, using the existing MJ-1 latch. */
namespace FOG\Agent;

use FOG\Items\Host;
use FOG\Items\TaskType;

class DirectoryOperation extends \FOG\Base\FOGBase
{
    const FEATURE = 'directory-join-target-v2';

    public static function requested(array $body)
    {
        return in_array(self::FEATURE, (array)($body['features'] ?? []), true);
    }

    public static function legacyPending(array $s)
    {
        return !empty($s['attempt_id']) && 2 !== ($s['protocol'] ?? 1)
            && 'verified' !== ($s['phase'] ?? '');
    }

    public static function revision(Host $host, array $c, $dc)
    {
        return substr(hash('sha256', json_encode([(int)$host->get('id'),
            (string)$host->get('name'), strtolower(trim((string)$host->get('ADDomain'))),
            (string)$host->get('agentFingerprint'), $c, $dc])), 0, 32);
    }

    public static function check(Host $host)
    {
        if (!$host->get('useAD') || !State::factsEnabled()) {
            return null;
        }
        $saved = DirectoryRejoin::settings($host);
        $s = $saved['state'];
        if (2 !== ($s['protocol'] ?? 1) || self::legacyPending($s) || !empty($s['workgroup'])) {
            return null;
        }
        $dc = $s['check_dc'] ?? '';
        return ['name' => (string)$host->get('name'), 'domain' => trim((string)$host->get('ADDomain')),
            'dc' => $dc, 'revision' => self::revision($host, $saved['config'], $dc)];
    }

    /** Before desired state and OU side effects; observations are never credentials. */
    public static function observe(Host $host, array $body)
    {
        DirectoryRejoin::change($host, function (&$c, &$s) use ($host, $body) {
            if (self::legacyPending($s)) {
                return;
            }
            $contextChanged = strcasecmp($c['name'] ?? '', (string)$host->get('name'))
                || strcasecmp($c['domain'] ?? '', trim((string)$host->get('ADDomain')));
            if (2 !== ($c['protocol'] ?? 1) || ($contextChanged && (empty($s['attempt_id']) || 'verified' === ($s['phase'] ?? '')))) {
                $next = ['version' => 1, 'protocol' => 2, 'allow_create' => DirectoryRejoin::allowsCreate($c),
                    'name' => (string)$host->get('name'), 'domain' => strtolower(trim((string)$host->get('ADDomain'))),
                    'config_revision' => bin2hex(random_bytes(16))];
                if (!strcasecmp($c['name'] ?? '', $next['name']) && !strcasecmp($c['domain'] ?? '', $next['domain']) && !empty($c['target'])) {
                    $next['target'] = $c['target'];
                }
                $c = $next;
            }
            $s['protocol'] = 2;
            if ('verified' === ($s['phase'] ?? '')) {
                unset($s['source']);
            }
            $clone = $body['clone_status'] ?? 'unknown';
            if (!in_array($clone, ['none','detected','unknown'], true)) {
                throw new \RuntimeException('clone_status_required', 409);
            }
            $s['clone_status'] = $clone;
            $fresh = $body['directory'] ?? [];
            $workgroup = false === ($fresh['joined'] ?? null) && 'workgroup' === ($fresh['kind'] ?? '');
            if (!empty($fresh)) {
                $s['workgroup'] = $workgroup;
            }
            if ($workgroup && (empty($s['attempt_id']) || 'verified' === ($s['phase'] ?? ''))) {
                $s['clone_status'] = 'none';
                $s['placement_hold'] = false;
                return;
            }
            $o = $body['directory_observation'] ?? null;
            if (is_array($o)) {
                if (array_diff(array_keys($o), ['revision','current_name','pending_name','domain','netbios','role','secure_channel','dc','dc_reachable','boot'])
                    || (isset($o['boot']) && is_array($o['boot']) && array_diff(array_keys($o['boot']), ['marker','started']))) {
                    throw new \RuntimeException('directory_observation_invalid', 400);
                }
                $dc = $s['check_dc'] ?? '';
                if (($o['revision'] ?? '') !== self::revision($host, $c, $dc)) {
                    $s['placement_hold'] = true;
                    return;
                }
                foreach (['current_name','pending_name','domain','netbios','role','secure_channel','dc'] as $key) {
                    if (!is_string($o[$key] ?? null) || strlen($o[$key]) > 255) {
                        throw new \RuntimeException('directory_observation_invalid', 400);
                    }
                }
                if (!in_array($o['secure_channel'], ['true','false','unknown'], true)
                    || !is_bool($o['dc_reachable'] ?? null) || !is_array($o['boot'] ?? null)
                    || !is_string($o['boot']['marker'] ?? null) || strlen($o['boot']['marker']) > 128
                    || !is_string($o['boot']['started'] ?? null) || strlen($o['boot']['started']) > 64) {
                    throw new \RuntimeException('directory_observation_invalid', 400);
                }
                $s['observation'] = $o;
                $s['observed_at'] = time();
            }
            $active = !empty($s['attempt_id']) && 'verified' !== ($s['phase'] ?? '');
            $o = $s['observation'] ?? [];
            $healthy = 'none' === $clone && 'true' === ($o['secure_channel'] ?? '')
                && 0 === strcasecmp($o['current_name'] ?? '', (string)$host->get('name'))
                && 0 === strcasecmp($o['pending_name'] ?? '', (string)$host->get('name'))
                && self::sameDomain($o, (string)$host->get('ADDomain'));
            $s['placement_hold'] = $active || !$healthy;
        });
    }

    public static function sameDomain(array $o, $want)
    {
        return '' !== $want && (0 === strcasecmp($o['domain'] ?? '', $want)
            || ('' !== ($o['netbios'] ?? '') && 0 === strcasecmp($o['netbios'], $want)));
    }

    /** Pure selector shared by DB and contract tests. Never treats errors as absence. */
    public static function select(array $o, $name, $clone, $reassigned, array $target, $allowCreate, $confirmed, $authorized)
    {
        if (!in_array($o['role'] ?? '', ['workstation','member_server'], true)
            || empty($o['dc_reachable']) || 'unknown' === ($o['secure_channel'] ?? 'unknown')) {
            throw new \RuntimeException('directory_observation_unknown', 409);
        }
        if (!in_array($clone, ['none','detected'], true)) {
            throw new \RuntimeException('directory_clone_unknown', 409);
        }
        $current = $o['current_name'] ?? '';
        $pending = $o['pending_name'] ?? '';
        if ('' === $current || '' === $pending || (strcasecmp($pending, $current) && strcasecmp($pending, $name))) {
            throw new \RuntimeException('directory_pending_name_conflict', 409);
        }
        $rename = 0 !== strcasecmp($current, $name);
        if (!$rename && 'none' === $clone && 'true' === $o['secure_channel']) {
            return null;
        }
        if (!$rename && 'none' === $clone && !$authorized) {
            throw new \RuntimeException('directory_recovery_confirmation_required', 409);
        }
        $status = $target['status'] ?? 'error';
        if ('none' === $clone && $rename && !$reassigned && 'true' === $o['secure_channel']
            && in_array($status, ['absent','unconfigured'], true)) {
            return ['operation' => 'rename', 'mode' => 'rename', 'target' => null];
        }
        if ('found' === $status) {
            $record = $target['record'] ?? [];
            if (!DirectoryRejoin::guid($record['guid'] ?? null) || !is_string($record['sid'] ?? null)
                || '' === $record['sid'] || strcasecmp($record['account'] ?? '', $name.'$')) {
                throw new \RuntimeException('rejoin_target_invalid', 409);
            }
            if (!$confirmed) {
                throw new \RuntimeException('rejoin_target_confirmation_required', 409);
            }
            return ['operation' => 'rebind', 'mode' => 'existing', 'target' => $target['record']];
        }
        if ('absent' === $status && $allowCreate) {
            return ['operation' => 'rebind', 'mode' => 'create', 'target' => null];
        }
        throw new \RuntimeException('absent' === $status ? 'rejoin_create_not_permitted' : 'rejoin_lookup_failed', 409);
    }

    public static function candidate(Host $host)
    {
        $config = DirectoryPlacement::connection($host);
        if (empty($config['uri'])) {
            return ['status' => 'unconfigured'];
        }
        $domain = trim((string)$host->get('ADDomain'));
        if (false === strpos($domain, '.')) {
            $saved = DirectoryRejoin::settings($host);
            $o = $saved['state']['observation'] ?? [];
            if (self::sameDomain($o, $domain) && false !== strpos($o['domain'] ?? '', '.')) {
                $domain = $o['domain'];
            }
        }
        list($ldap, $base, $dc) = DirectoryRejoin::connection($host, $domain);
        try {
            return DirectoryRejoin::lookup($ldap, $base, (string)$host->get('name')) + ['dc' => $dc];
        } finally {
            $ldap->close();
        }
    }

    public static function deploy(Host $host, array $s, array $o)
    {
        $types = implode(',', array_unique(array_map('intval', array_merge(TaskType::DEPLOYTASKS, TaskType::CAPTURETASKS))));
        $q = self::$DB->link()->prepare('SELECT taskID,taskStateID,taskTypeID,taskStateChangedTime FROM tasks WHERE taskHostID=? AND taskTypeID IN ('.$types.') ORDER BY taskID DESC LIMIT 1');
        $q->execute([(int)$host->get('id')]);
        $task = $q->fetch(\PDO::FETCH_ASSOC);
        $q->closeCursor();
        if (!$task || !in_array((int)$task['taskTypeID'], TaskType::DEPLOYTASKS, true)
            || (int)$task['taskStateID'] !== 4 || (int)$task['taskID'] <= (int)($s['last_deploy_task_id'] ?? 0)
            || empty($task['taskStateChangedTime'])) {
            return 0;
        }
        try {
            $end = new \DateTimeImmutable($task['taskStateChangedTime'], \FOG\Base\FOGBase::storageTimeZone());
            $boot = new \DateTimeImmutable($o['boot']['started'] ?? 'invalid');
        } catch (\Throwable $e) {
            return 0;
        }
        $age = time() - $end->getTimestamp();
        return $age >= 0 && $age <= 86400 && $boot->getTimestamp() >= $end->getTimestamp()
            ? (int)$task['taskID'] : 0;
    }

    public static function plan(Host $host)
    {
        $saved = DirectoryRejoin::settings($host);
        $c = $saved['config'];
        $s = $saved['state'];
        if (self::legacyPending($s)) {
            return DirectoryRejoin::publicState($s);
        }
        if (!$host->get('useAD') || !State::factsEnabled()) {
            return !empty($s['attempt_id']) && 'verified' !== ($s['phase'] ?? '')
                ? ('ready' === $s['phase'] ? ['phase' => 'blocked', 'reason' => 'rejoin_disabled'] : DirectoryRejoin::publicState($s)) : null;
        }
        if (!empty($s['workgroup']) && (empty($s['attempt_id']) || 'verified' === ($s['phase'] ?? ''))) {
            return null;
        }
        if (!empty($s['attempt_id']) && 'verified' !== ($s['phase'] ?? '')) {
            return DirectoryRejoin::publicState($s);
        }
        $o = $s['observation'] ?? [];
        if (time() - ($s['observed_at'] ?? 0) > 600 || ($o['revision'] ?? '') !== self::revision($host, $c, $s['check_dc'] ?? '')) {
            return ['phase' => 'blocked', 'reason' => 'directory_observation_required'];
        }
        if (!self::sameDomain($o, trim((string)$host->get('ADDomain')))) {
            throw new \RuntimeException('directory_domain_mismatch', 409);
        }
        $name = (string)$host->get('name');
        if (!$host->isHostnameSafe()) {
            throw new \RuntimeException('rejoin_name_invalid', 409);
        }
        $task = self::deploy($host, $s, $o);
        $same = !strcasecmp($o['current_name'], $name) && !strcasecmp($o['pending_name'], $name);
        if ($same && 'true' === $o['secure_channel'] && 'none' === ($s['clone_status'] ?? '')) {
            if ($task) {
                DirectoryRejoin::change($host, function (&$config, &$state) use ($task) {
                    $state['last_deploy_task_id'] = max($task, (int)($state['last_deploy_task_id'] ?? 0));
                });
            }
            return null;
        }
        $q = self::$DB->link()->prepare('SELECT hostID FROM hosts WHERE LOWER(hostName)=LOWER(?) AND hostID<>? LIMIT 1');
        $q->execute([$o['current_name'], (int)$host->get('id')]);
        $reassigned = (bool)$q->fetchColumn();
        $q->closeCursor();
        $target = self::candidate($host);
        if (!empty($target['dc']) && 0 !== strcasecmp($target['dc'], $o['dc'])) {
            DirectoryRejoin::change($host, function (&$config, &$state) use ($target) {
                $state['check_dc'] = $target['dc'];
            });
            return ['phase' => 'blocked', 'reason' => 'directory_observation_required'];
        }
        $confirmed = !empty($c['target']) && 'found' === $target['status']
            && !strcasecmp($c['name'] ?? '', $name) && !strcasecmp($c['domain'] ?? '', (string)$host->get('ADDomain'))
            && DirectoryRejoin::sameObject($c['target'], $target['record']);
        $choice = self::select(
            $o,
            $name,
            $s['clone_status'] ?? 'unknown',
            $reassigned,
            $target,
            DirectoryRejoin::allowsCreate($c),
            $confirmed,
            $task > 0 || !empty($c['retry_authorized'])
        );
        if (null === $choice) {
            return null;
        }
        if ('rebind' === $choice['operation']) {
            DirectoryRejoin::validName($name);
        }
        $p = $choice + ['version' => 1, 'protocol' => 2, 'host_id' => (int)$host->get('id'),
            'attempt_id' => bin2hex(random_bytes(16)), 'config_revision' => $o['revision'],
            'fingerprint' => (string)$host->get('agentFingerprint'), 'name' => $name,
            'domain' => strtolower($o['domain']), 'dc' => $o['dc'], 'phase' => 'ready',
            'expected_current' => $o['current_name'], 'expected_pending' => $o['pending_name'],
            'retry_authorized' => !empty($c['retry_authorized']),
            'placement_hold' => true, 'clone_status' => $s['clone_status'],
            'trigger_task_id' => $task, 'last_deploy_task_id' => (int)($s['last_deploy_task_id'] ?? 0),
            'observation' => $o, 'observed_at' => $s['observed_at'], 'check_dc' => $s['check_dc'] ?? ''];
        return DirectoryRejoin::change($host, function (&$config, &$state, $principal) use ($saved, $p) {
            if ($config !== $saved['config'] || $state !== $saved['state'] || $principal['hostAgentFingerprint'] !== $p['fingerprint']) {
                throw new \RuntimeException('rejoin_plan_changed', 409);
            }
            $state = $p;
            return DirectoryRejoin::publicState($p);
        });
    }

    public static function start(Host $host, array $body)
    {
        $saved = DirectoryRejoin::settings($host);
        $s = $saved['state'];
        if (!$host->get('useAD') || !State::factsEnabled()) {
            throw new \RuntimeException('rejoin_disabled', 409);
        }
        self::claimable($host, $saved, $body);
        if ('rebind' === $s['operation']) {
            $t = self::candidate($host);
            if ($s['mode'] === 'existing' ? ('found' !== $t['status'] || !DirectoryRejoin::sameObject($s['target'], $t['record']))
                : ('absent' !== $t['status'] || !DirectoryRejoin::allowsCreate($saved['config']))) {
                throw new \RuntimeException('rejoin_target_changed', 409);
            }
        } else {
            $t = self::candidate($host);
            if (!in_array($t['status'], ['absent','unconfigured'], true)) {
                throw new \RuntimeException('rename_target_changed', 409);
            }
            $q = self::$DB->link()->prepare('SELECT COUNT(*) FROM hosts WHERE LOWER(hostName)=LOWER(?) AND hostID<>?');
            $q->execute([$s['expected_current'], (int)$host->get('id')]);
            $assigned = (int)$q->fetchColumn();
            $q->closeCursor();
            if ($assigned) {
                throw new \RuntimeException('rename_name_reassigned', 409);
            }
        }
        $credential = DirectoryJoin::rejoinCredential($host);
        if ('' === $credential['username'] || '' === $credential['password']) {
            throw new \RuntimeException('rejoin_credential_missing', 409);
        }
        self::claim($host, $saved, $body);
        return ['status' => 'ok', 'directory' => $credential + ['rejoin' => DirectoryRejoin::publicState($s)]];
    }

    /** The DB-only authority boundary; LDAP and credentials stay outside the lock. */
    public static function claim(Host $host, array $saved, array $body)
    {
        DirectoryRejoin::change($host, function (&$c, &$state, $principal) use ($saved, $body, $host) {
            if ($c !== $saved['config'] || $state !== $saved['state'] || !$principal['hostUseAD']
                || $principal['hostAgentFingerprint'] !== $state['fingerprint']
                || strcasecmp($principal['hostName'], $state['name'])
                || !self::sameDomain($state['observation'], $principal['hostADDomain'])) {
                throw new \RuntimeException('rejoin_plan_changed', 409);
            }
            self::claimable($host, ['config' => $c, 'state' => $state], $body);
            if (!empty($state['trigger_task_id'])) {
                if ($state['trigger_task_id'] <= ($state['last_deploy_task_id'] ?? 0)) {
                    throw new \RuntimeException('deploy_already_consumed', 409);
                }
                $state['last_deploy_task_id'] = $state['trigger_task_id'];
            }
            $c['retry_authorized'] = false;
            $state['phase'] = 'started';
            $state['reason'] = 'mutation_may_have_run';
        });
    }

    public static function claimable(Host $host, array $saved, array $body)
    {
        $s = $saved['state'];
        if ('ready' !== ($s['phase'] ?? '') || 2 !== ($s['protocol'] ?? 1)
            || ($body['attempt_id'] ?? '') !== ($s['attempt_id'] ?? null)
            || ($body['config_revision'] ?? '') !== ($s['config_revision'] ?? null)
            || ($body['operation'] ?? '') !== ($s['operation'] ?? null)
            || ($body['target_guid'] ?? null) !== ($s['target']['guid'] ?? null)
            || (string)$host->get('agentFingerprint') !== ($s['fingerprint'] ?? null)
            || strcasecmp((string)$host->get('name'), $s['name'] ?? '')
            || time() - ($s['observed_at'] ?? 0) > 600
            || self::revision($host, $saved['config'], $s['check_dc'] ?? '') !== ($s['config_revision'] ?? null)) {
            throw new \RuntimeException('rejoin_start_refused', 409);
        }
    }

    public static function report(Host $host, array $body)
    {
        $saved = DirectoryRejoin::settings($host);
        $s = $saved['state'];
        if (($body['attempt_id'] ?? '') !== ($s['attempt_id'] ?? null)
            || ($body['config_revision'] ?? '') !== ($s['config_revision'] ?? null)
            || (int)($body['id'] ?? 0) !== (int)$host->get('id')
            || (string)$host->get('agentFingerprint') !== ($s['fingerprint'] ?? null)) {
            throw new \RuntimeException('rejoin_result_mismatch', 409);
        }
        $phase = $body['status'] ?? '';
        if (!in_array($phase, ['awaiting_reboot','verified','failed','unknown'], true)) {
            throw new \RuntimeException('rejoin_result_invalid', 400);
        }
        $target = null;
        if (in_array($phase, ['awaiting_reboot','verified'], true)) {
            if (($body['native_status'] ?? null) !== 0) {
                throw new \RuntimeException('rejoin_native_success_required', 409);
            }
            if ('rebind' === $s['operation']) {
                $t = self::candidate($host);
                if ('found' !== $t['status'] || (!empty($s['target']) && !DirectoryRejoin::sameObject($s['target'], $t['record']))) {
                    throw new \RuntimeException('rejoin_target_changed', 409);
                }
                $target = $t['record'];
            } elseif ('rename' === $s['operation']) {
                $t = self::candidate($host);
                if ('found' === $t['status']) {
                    $target = $t['record'];
                } elseif ('unconfigured' !== $t['status']) {
                    throw new \RuntimeException('rename_target_unverified', 409);
                }
            }
            if ('verified' === $phase && (($body['secure_channel'] ?? '') !== 'healthy'
                || true !== ($body['boot_changed'] ?? false) || strcasecmp($body['current_name'] ?? '', $s['name'])
                || strcasecmp($body['domain'] ?? '', $s['domain']))) {
                throw new \RuntimeException('rejoin_verification_incomplete', 409);
            }
        }
        DirectoryRejoin::change($host, function (&$c, &$state, $principal) use ($saved, $phase, $body, $target) {
            if ($state !== $saved['state'] || $c !== $saved['config'] || $principal['hostAgentFingerprint'] !== $state['fingerprint']) {
                throw new \RuntimeException('rejoin_result_changed', 409);
            }
            DirectoryRejoin::transition($state['phase'], $phase);
            $state['phase'] = $phase;
            $state['reason'] = $phase;
            $state['native_status'] = $body['native_status'] ?? null;
            $state['secure_channel'] = $body['secure_channel'] ?? 'unknown';
            $state['updated_at'] = gmdate('c');
            if ($target) {
                $state['target'] = $target;
            }
            if ('verified' === $phase) {
                $state['placement_hold'] = false;
                if ($target) {
                    $c['target'] = $target;
                    $c['name'] = $state['name'];
                    $c['domain'] = strtolower(trim($principal['hostADDomain']));
                }
            }
        });
        return '';
    }
}
