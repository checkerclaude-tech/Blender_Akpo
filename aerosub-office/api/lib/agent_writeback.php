<?php
declare(strict_types=1);
// Turns an approved agent proposal into real pipeline rows (companies, contacts, tasks) with source 'agent'.
// Called only from agent_owner.php after the owner has approved. Everything one item writes happens in ONE
// transaction, so a failure leaves nothing half-written and a retry cannot duplicate anything (PRD N4).

const AGENT_DEFAULT_LAWFUL_BASIS = 'legitimate interest (business contact)';
const AGENT_DEFAULT_RETENTION_MONTHS = 24;

function agent_host(?string $url): ?string {
    if (!$url) return null;
    $h = parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://' . $url, PHP_URL_HOST);
    return $h ? preg_replace('/^www\./i', '', strtolower($h)) : null;
}

/** Existing company by name (as the app's own import does) or by website address. Never creates. */
function agent_find_company(?string $name, ?string $website): ?int {
    $name = s($name ?? '', 190);
    if ($name !== '') { $r = one('SELECT id FROM companies WHERE LOWER(name) = LOWER(?)', [$name]); if ($r) return (int)$r['id']; }
    $host = agent_host($website);
    if ($host) { $r = one('SELECT id FROM companies WHERE website LIKE ? LIMIT 1', ['%' . $host . '%']); if ($r) return (int)$r['id']; }
    return null;
}

function agent_company_for(array $p, int $ownerId, array &$made): ?int {
    if (!empty($p['company_id'])) { $r = one('SELECT id FROM companies WHERE id = ?', [(int)$p['company_id']]); if ($r) return (int)$r['id']; }
    $name = s($p['company_name'] ?? ($p['name'] ?? ''), 190);
    if ($name === '' && empty($p['website'])) return null;
    $found = agent_find_company($name, $p['website'] ?? null);
    if ($found) return $found;
    if ($name === '') return null;
    q("INSERT INTO companies (name, sector, location, website, stage, priority, owner_id, notes, source, created_by) VALUES (?,?,?,?,'researching','medium',?,?, 'agent', ?)",
      [$name, s($p['sector'] ?? '', 80) ?: null, s($p['location'] ?? '', 120) ?: null, s($p['website'] ?? '', 190) ?: null, $ownerId, s($p['notes'] ?? '', 5000) ?: null, $ownerId]);
    $id = (int)db()->lastInsertId(); $made['companies'][] = $id;
    return $id;
}

function agent_insert_task(array $t, int $ownerId, ?int $companyId, array &$made, string $type = 'general'): int {
    $assignee = !empty($t['assignee_id']) && one('SELECT id FROM users WHERE id = ? AND active = 1', [(int)$t['assignee_id']]) ? (int)$t['assignee_id'] : $ownerId;
    $prio = in((string)($t['priority'] ?? ''), ['low', 'medium', 'high', 'urgent'], 'medium');
    q("INSERT INTO tasks (title, description, status, priority, assignee_id, creator_id, due_date, company_id, task_type, source) VALUES (?,?, 'todo', ?,?,?,?,?,?, 'agent')",
      [s($t['title'] ?? '', 200), s($t['description'] ?? ($t['why'] ?? ''), 5000) ?: null, $prio, $assignee, $ownerId, d($t['due_date'] ?? null), $companyId, $type]);
    $id = (int)db()->lastInsertId(); $made['tasks'][] = $id;
    if ($assignee !== $ownerId) notify($assignee, 'task_assigned', s($t['title'] ?? '', 150), 'Suggested by the agent, approved by the owner', '#/tasks');
    return $id;
}

/**
 * Writes the pipeline rows for an approved item. $p is the (possibly edited) payload, $assignments maps task index
 * to ['assignee_id' => .., 'due_date' => ..]. Returns ids created, e.g. ['companies' => [3], 'tasks' => [9, 10]].
 */
function agent_write_back(string $kind, array $p, array $assignments, int $ownerId): array {
    $made = ['companies' => [], 'contacts' => [], 'tasks' => []];

    if ($kind === 'company') {
        agent_company_for($p, $ownerId, $made);
    } elseif ($kind === 'contact') {
        $name = s($p['name'] ?? '', 160); if ($name === '') throw new RuntimeException('Contact has no name');
        $cid = agent_company_for($p, $ownerId, $made);
        // Never store a guessed value: an email or phone is kept only if the proposal shows where it came from.
        $email = !empty($p['email_evidence']) ? strtolower(s($p['email'] ?? '', 190)) : '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $phone = !empty($p['phone_evidence']) ? s($p['phone'] ?? '', 60) : '';
        if ($email !== '' && one('SELECT id FROM contacts WHERE email = ?', [$email])) return $made; // already in the pipeline
        $months = max(1, min(120, (int)($p['retention_months'] ?? AGENT_DEFAULT_RETENTION_MONTHS)));
        q("INSERT INTO contacts (company_id, name, position, email, phone, linkedin, notes, owner_id, source, source_note, lawful_basis, retain_until) VALUES (?,?,?,?,?,?,?,?, 'agent', ?,?,?)",
          [$cid, $name, s($p['position'] ?? '', 160) ?: null, $email ?: null, $phone ?: null, s($p['linkedin'] ?? '', 300) ?: null, s($p['notes'] ?? '', 3000) ?: null, $ownerId,
           s($p['source_note'] ?? '', 300) ?: null, s($p['lawful_basis'] ?? AGENT_DEFAULT_LAWFUL_BASIS, 60), date('Y-m-d', strtotime("+$months months"))]);
        $made['contacts'][] = (int)db()->lastInsertId();
    } elseif ($kind === 'task') {
        if (s($p['title'] ?? '', 200) === '') throw new RuntimeException('Task has no title');
        agent_insert_task(array_merge($p, $assignments[0] ?? []), $ownerId, agent_company_for($p, $ownerId, $made), $made);
    } elseif ($kind === 'research') {
        $title = s($p['title'] ?? '', 200); if ($title === '') throw new RuntimeException('Research entry has no title');
        $tid = agent_insert_task(['title' => $title] + $p, $ownerId, agent_company_for($p, $ownerId, $made), $made, 'research');
        q("INSERT INTO task_updates (task_id, user_id, kind, data) VALUES (?,?, 'research', ?)", [$tid, $ownerId, json_encode(['body' => s($p['body'] ?? '', 20000), 'from' => 'agent'])]);
    } elseif ($kind === 'signal') {
        $cid = agent_company_for($p, $ownerId, $made);
        foreach (array_values($p['tasks'] ?? []) as $i => $t) {
            if (s($t['title'] ?? '', 200) === '') continue;
            agent_insert_task(($assignments[$i] ?? []) + $t, $ownerId, $cid, $made);
        }
    } else {
        throw new RuntimeException('Unknown kind');
    }
    return $made;
}

function agent_item_row(int $id): array {
    $it = one('SELECT * FROM agent_items WHERE id = ?', [$id]);
    if (!$it) fail('Not found', 404);
    $it['payload'] = json_decode($it['payload'], true) ?: [];
    return $it;
}
/** Phase 2: write the pipeline rows. One transaction; marks the item synced inside it. Returns ids created. */
function agent_sync_item(int $itemId, int $ownerId, array $assignments): array {
    $it = agent_item_row($itemId);
    $assignments = $assignments ?: (array)($it['payload']['_assignments'] ?? []);
    if ($it['sync_state'] === 'synced') return ['already' => true];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $made = agent_write_back($it['kind'], $it['payload'], $assignments, $ownerId);
        foreach (['companies' => 'company', 'contacts' => 'contact', 'tasks' => 'task'] as $k => $type) {
            foreach ($made[$k] ?? [] as $subjectId) q('REPLACE INTO agent_origins (subject_type, subject_id, item_id, score, approved_by) VALUES (?,?,?,?,?)', [$type, $subjectId, $itemId, $it['score'], $ownerId]);
        }
        q("UPDATE agent_items SET sync_state = 'synced' WHERE id = ?", [$itemId]);
        $pdo->commit();
        log_act($ownerId, 'agent_approved', mb_substr($it['headline'], 0, 250));
        return $made;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_act($ownerId, 'agent_sync_failed', mb_substr($it['headline'] . ': ' . $e->getMessage(), 0, 280));
        throw $e;
    }
}


/** Called by cron.php: retry approved items whose pipeline write failed earlier. Returns how many were retried. */
function agent_retry_pending(): int {
    $n = 0;
    foreach (all("SELECT id, decided_by FROM agent_items WHERE status = 'approved' AND sync_state = 'syncing' AND decided_by IS NOT NULL ORDER BY id LIMIT 20") as $row) {
        try { agent_sync_item((int)$row['id'], (int)$row['decided_by'], []); } catch (Throwable $e) { /* logged by agent_sync_item; tried again next run */ }
        $n++;
    }
    return $n;
}
