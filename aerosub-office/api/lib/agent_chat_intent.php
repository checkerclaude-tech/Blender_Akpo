<?php
declare(strict_types=1);
// Research chat: understands the owner's starter-list messages and answers them from the agent's own data.
// No model is involved: a fixed list of requests is read with plain patterns, and every answer is built from rows the agent
// already produced (checked documents, their claims, the inbox) with the source named. When nothing matches the answer is
// "I don't know", never a guess. Instructions that DO something (research a company, check a link) are returned as an action
// for the owner to approve; nothing here runs them. Routing and storage are in agent_chat.php.
//
// Starter list (PRD Decision 16):  research [company] · check this link <url> · check this file · what did you find about [company]
//                                  explain claim [n] · what's waiting for me

const AGENT_REFUSAL = "I can't do that yet. I can research companies, check links and files, and explain claims.";

// Greetings. A message that is ONLY a greeting gets a greeting and "What would you like to do today?". The reply is chosen from fixed wording
// by the kind of greeting, so nothing the owner typed is ever repeated. A greeting in front of a real request is ignored and the request is
// handled. (The Personal chat on the laptop, agent-box/personal/app/greeting.py, follows the same rules.)
const AGENT_GREET_OPEN = '(?:hi|hello|hey|hiya|howdy|greetings|hullo|heya|yo)';
const AGENT_GREET_TIME = '(?:good\s(?:morning|afternoon|evening|day)|morning|afternoon|evening)';
const AGENT_GREET_HOW = '(?:how\s(?:are|r)\s(?:you|u)(?:\sdoing)?(?:\stoday)?|how\sis\sit\sgoing|how\'s\sit\sgoing|how\sdo\syou\sdo|what\'s\sup|whats\sup|sup)';
const AGENT_GREET_FILL = '(?:\s(?:there|again|agent|friend|team|everyone|all))*';
const AGENT_GREET_ONE = '(?:' . AGENT_GREET_OPEN . '|' . AGENT_GREET_TIME . '|' . AGENT_GREET_HOW . ')' . AGENT_GREET_FILL;
const AGENT_GREETING_MENU = "What would you like to do today? I can research a company, check a link or a file, explain a claim, or tell you what's waiting. For example: research Acme Offshore.";

function agent_greet_normal(string $t): string {
    $t = mb_strtolower(str_replace("\u{2019}", "'", $t));
    return trim((string)preg_replace('/\s+/', ' ', (string)preg_replace("/[^a-z' ]+/", ' ', $t)));
}
function agent_chat_is_greeting(string $text): bool {
    return (bool)preg_match('/^' . AGENT_GREET_ONE . '(?:\s(?:and\s)?' . AGENT_GREET_ONE . ')?$/', agent_greet_normal($text));
}
/** "hello, research Acme" -> "research Acme". Anything that is only a greeting, or has no greeting in front, comes back unchanged. */
function agent_chat_strip_greeting(string $text): string {
    if (agent_chat_is_greeting($text)) return $text;
    if (preg_match('/^\s*(?:' . AGENT_GREET_OPEN . '|' . AGENT_GREET_TIME . ')' . AGENT_GREET_FILL . '[\s,!.:;\-]+(\S.*)$/is', $text, $m)) return trim($m[1]);
    return $text;
}
function agent_chat_greeting_reply(string $text): string {
    $t = agent_greet_normal($text);
    $tod = preg_match('/\b(?:good\s)?(morning|afternoon|evening|day)\b/', $t, $m) ? $m[1] : null;
    $how = (bool)preg_match('/' . AGENT_GREET_HOW . '/', $t);
    $hello = (bool)preg_match('/\b' . AGENT_GREET_OPEN . '\b/', $t);
    $parts = [];
    if ($tod !== null) $parts[] = 'Good ' . $tod . '!';
    elseif ($hello || !$how) $parts[] = 'Hello!';
    if ($how) $parts[] = "I'm well, thank you.";
    return implode(' ', $parts) . ' ' . AGENT_GREETING_MENU;
}
const AGENT_LABEL_WORDS = [
    'supported' => ['Supported', 'We opened the source and found a passage that backs this claim.'],
    'partly_supported' => ['Partly supported', 'The source backs most of it, but a number, date or name differs.'],
    'not_supported' => ['Not supported', 'We opened the source and found nothing that backs this claim.'],
    'cannot_check' => ["Couldn't check", "We couldn't open the source. This is not a pass."],
    'cited_not_online' => ['Cited, not online', "The source is named, but we couldn't find it online."],
    'source_suggested' => ['Source suggested', 'The document gave no source. We found a possible one, so please review it.'],
    'no_source' => ['No source', 'No source is given and none was found.'],
    'author_flagged' => ['Author unsure', 'The author marked this as unconfirmed or conflicting.'],
];
const AGENT_REASON_WORDS = ['dead' => 'the link is dead', 'blocked' => 'the site blocked us', 'paywalled' => 'it is behind a paywall', 'login' => 'it needs a login'];
const AGENT_TIER_WORDS = ['primary' => 'Primary source', 'news' => 'News', 'weak' => 'Weak source (forum, job board, aggregator)'];

/** A web address the agent may be asked to open. The laptop applies its own stricter rules again before opening anything. */
function agent_chat_url_ok(string $u): bool { return strlen($u) <= 500 && (bool)preg_match('#^https?://[^\s<>"\']+$#i', $u); }
function agent_like(string $s): string { return '%' . addcslashes($s, '\\%_') . '%'; }
function agent_clean_name(string $s): string { return trim(mb_substr(preg_replace('/[\x00-\x1f<>]+/u', ' ', $s), 0, 80)); }

/** What does the message ask for? Returns ['intent' => ..., ...arguments]. */
function agent_chat_parse(string $text): array {
    $t = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($t === '') return ['intent' => 'empty'];
    if (agent_chat_is_greeting($t)) return ['intent' => 'greeting', 'text' => $t];
    $t = agent_chat_strip_greeting($t);                      // "hello, research Acme" is the request "research Acme"
    if (preg_match('~(https?://[^\s<>"\']+)~i', $t, $m) && preg_match('/\b(check|verify|validate|open|read|look at)\b/i', $t)) {
        $url = rtrim($m[1], '.,);:');
        return agent_chat_url_ok($url) ? ['intent' => 'check_link', 'url' => $url] : ['intent' => 'unknown'];
    }
    if (preg_match('/\bcheck\b.*\b(file|document|pdf|report|attachment)\b/i', $t)) return ['intent' => 'check_file'];
    if (preg_match('/^(?:please )?explain (?:me )?(?:the )?claim\s*#?(\d{1,4})(?:\s+(?:of|in|from)\s+(.{2,80}?))?\s*[?.!]*$/i', $t, $m)) return ['intent' => 'explain_claim', 'n' => (int)$m[1], 'doc' => isset($m[2]) ? agent_clean_name($m[2]) : ''];
    if (preg_match('/what(?:\'s| is| are| did| have)?\s+(?:you\s+)?(?:find|found|know|got)\s+(?:out\s+)?(?:about|on|for)\s+(.{2,80}?)\s*[?.!]*$/i', $t, $m)) return ['intent' => 'find', 'topic' => agent_clean_name($m[1])];
    if (preg_match('/what(?:\'?s| is| are)?\s+(?:waiting|pending|new|outstanding)|what needs me|anything (?:waiting|new) for me/i', $t)) return ['intent' => 'waiting'];
    if (preg_match('/^(?:please )?(?:research|investigate|look into|look up|dig into)\s+(.{2,80}?)\s*[?.!]*$/i', $t, $m)) return ['intent' => 'research', 'company' => agent_clean_name($m[1])];
    return ['intent' => 'unknown'];
}

/** The reply to a parsed request: ['body' => text, 'sources' => [['text', 'href']], 'action' => null | ['kind', 'args']]. */
function agent_chat_reply(array $p): array {
    $r = ['body' => AGENT_REFUSAL, 'sources' => [], 'action' => null];
    switch ($p['intent']) {
        case 'greeting': return ['body' => agent_chat_greeting_reply($p['text']), 'sources' => [], 'action' => null];
        case 'waiting': return agent_chat_waiting();
        case 'find': return agent_chat_find($p['topic']);
        case 'explain_claim': return agent_chat_explain($p['n'], $p['doc']);
        case 'check_file':
            return ['body' => "To check a file, upload it in Files (PDF or Word, up to the size shown there). I'll check each claim's sources and show the result there.", 'sources' => [['text' => 'Files', 'href' => '#/agents/research/files']], 'action' => null];
        case 'research':
            return ['body' => 'Research ' . $p['company'] . ': restarts, sales, incidents and tenders. Approve this and I will send it to the research agent.', 'sources' => [], 'action' => ['kind' => 'research_company', 'args' => ['company' => $p['company']]]];
        case 'check_link':
            return ['body' => 'Check this link: ' . $p['url'] . ". Approve this and I will open it and tell you what it says and how reliable the source is.", 'sources' => [], 'action' => ['kind' => 'check_link', 'args' => ['url' => $p['url']]]];
    }
    return $r;
}

function agent_chat_waiting(): array {
    $review = (int)one("SELECT COUNT(*) c FROM agent_items WHERE status = 'pending' AND created_at >= (NOW() - INTERVAL 14 DAY)")['c'];
    $stale = (int)one("SELECT COUNT(*) c FROM agent_items WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 14 DAY)")['c'];
    $files = (int)one("SELECT COUNT(*) c FROM documents WHERE status IN ('queued','checking')")['c'];
    $reqs = (int)one("SELECT COUNT(*) c FROM research_requests WHERE status IN ('waiting','researching')")['c'];
    $acts = (int)one("SELECT COUNT(*) c FROM agent_chat_messages WHERE action_state = 'pending'")['c'];
    $lines = []; $src = [];
    if ($review) { $lines[] = $review . ($review === 1 ? ' opportunity' : ' opportunities') . ' to review'; $src[] = ['text' => 'Inbox', 'href' => '#/agents/research/inbox']; }
    if ($stale) { $lines[] = $stale . ' older than 14 days'; }
    if ($files) { $lines[] = $files . ($files === 1 ? ' file' : ' files') . ' being checked'; $src[] = ['text' => 'Files', 'href' => '#/agents/research/files']; }
    if ($reqs) $lines[] = $reqs . ($reqs === 1 ? ' research request' : ' research requests') . ' with the agent';
    if ($acts) $lines[] = $acts . ($acts === 1 ? ' request' : ' requests') . ' in this chat waiting for your approval';
    return ['body' => $lines ? 'Waiting: ' . implode(', ', $lines) . '.' : 'Nothing is waiting for you right now.', 'sources' => $src, 'action' => null];
}

function agent_chat_find(string $topic): array {
    $like = agent_like($topic);
    $claims = all("SELECT c.position, c.claim_text, c.label, d.id AS doc_id, d.original_name
                   FROM document_claims c JOIN documents d ON d.id = c.document_id
                   WHERE c.claim_text LIKE ? ORDER BY d.id DESC, c.position LIMIT 6", [$like]);
    $items = all("SELECT id, headline, status FROM agent_items WHERE headline LIKE ? ORDER BY id DESC LIMIT 3", [$like]);
    if (!$claims && !$items) return ['body' => "I don't know. I couldn't find anything about " . $topic . ' in my checked documents or inbox.', 'sources' => [], 'action' => null];
    $lines = []; $src = [];
    if ($claims) {
        $total = (int)one('SELECT COUNT(*) c FROM document_claims WHERE claim_text LIKE ?', [$like])['c'];
        $lines[] = 'In your checked documents I found ' . $total . ($total === 1 ? ' statement' : ' statements') . ' about ' . $topic . ($total > count($claims) ? ' (showing ' . count($claims) . ')' : '') . ':';
        foreach ($claims as $c) {
            $word = (AGENT_LABEL_WORDS[$c['label']] ?? [$c['label']])[0];
            $lines[] = '• ' . $c['original_name'] . ', claim ' . $c['position'] . ' (' . $word . '): ' . mb_substr((string)$c['claim_text'], 0, 220);
            $src[] = ['text' => $c['original_name'] . ', claim ' . $c['position'], 'href' => '#/agents/research/files/' . $c['doc_id']];
        }
    }
    if ($items) {
        $lines[] = 'In your inbox:';
        foreach ($items as $i) { $lines[] = '• ' . $i['headline'] . ' (' . ($i['status'] === 'pending' ? 'to review' : $i['status']) . ')'; $src[] = ['text' => 'Inbox: ' . mb_substr($i['headline'], 0, 50), 'href' => '#/agents/research/inbox/' . $i['id']]; }
    }
    return ['body' => implode("\n", $lines), 'sources' => array_slice($src, 0, 8), 'action' => null];
}

function agent_chat_explain(int $n, string $docName): array {
    $d = $docName !== ''
        ? one("SELECT id, original_name FROM documents WHERE status = 'ready' AND original_name LIKE ? ORDER BY id DESC LIMIT 1", [agent_like($docName)])
        : one("SELECT id, original_name FROM documents WHERE status = 'ready' ORDER BY id DESC LIMIT 1");
    if (!$d) return ['body' => $docName !== '' ? "I don't know. I have no checked document called " . $docName . '.' : "I don't know. No document has been checked yet.", 'sources' => [], 'action' => null];
    $c = one('SELECT * FROM document_claims WHERE document_id = ? AND position = ?', [$d['id'], $n]);
    if (!$c) {
        $max = (int)one('SELECT COUNT(*) c FROM document_claims WHERE document_id = ?', [$d['id']])['c'];
        return ['body' => "I don't know. " . $d['original_name'] . ' has ' . $max . ($max === 1 ? ' statement' : ' statements') . ' and none is numbered ' . $n . '.', 'sources' => [['text' => $d['original_name'], 'href' => '#/agents/research/files/' . $d['id']]], 'action' => null];
    }
    [$word, $tip] = AGENT_LABEL_WORDS[$c['label']] ?? [(string)$c['label'], ''];
    $lines = ['Claim ' . $n . ' in ' . $d['original_name'] . ': “' . mb_substr((string)$c['claim_text'], 0, 400) . '”', 'Verdict: ' . $word . '. ' . $tip];
    if ($c['label'] === 'cannot_check' && $c['reason']) $lines[] = 'Why: ' . ucfirst(AGENT_REASON_WORDS[$c['reason']] ?? (string)$c['reason']) . '.';
    if ($c['source_tier']) $lines[] = 'Kind of source: ' . (AGENT_TIER_WORDS[$c['source_tier']] ?? $c['source_tier']) . '.';
    if ($c['quote']) $lines[] = 'The source says: “' . mb_substr((string)$c['quote'], 0, 400) . '”';
    $src = [['text' => $d['original_name'] . ', claim ' . $n, 'href' => '#/agents/research/files/' . $d['id']]];
    if ($c['source_url'] && agent_chat_url_ok((string)$c['source_url'])) $src[] = ['text' => 'Open the source', 'href' => $c['source_url']];
    return ['body' => implode("\n", $lines), 'sources' => $src, 'action' => null];
}
