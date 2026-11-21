<?php

declare(strict_types=1);

namespace NolvikoExt\Importer;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Importers\Domain\ImportCursor;
use RuntimeException;

/** Platform-owned auxiliary mappings. No BCC address lists, IPs, session keys or source executable code are read. */
final readonly class SourceAuxRows
{
    public function __construct(
        private Connection $db,
        private string $engine,
        private string $prefix,
        private string $charset,
    ) {
    }

    public function rows(string $phase, string $after, int $limit): array
    {
        $t = fn(string $name): string => $this->db->quoteIdentifier($this->prefix . $name);
        $body = static fn(string $column): string => 'CASE WHEN OCTET_LENGTH(' . $column
            . ') <= 131072 THEN ' . $column . ' ELSE NULL END AS body';
        $keys = [];
        $where = '1 = 1';
        if ($phase === 'private_inbox') {
            // Every mailbox copy with the conversation it belongs to (`chain`): SMF's head message, phpBB's root
            // message; MyBB keeps none, so its chain is worked out from the participants and subject below.
            [$select, $from, $keys, $where] = match ($this->engine) {
                'smf' => ['x.pm_id AS id, x.pm_owner AS owner, x.pm_author AS author, x.pm_name AS name, '
                    . 'x.pm_time AS time, x.pm_head AS chain_root, ' . $body('x.pm_body'),
                    '(SELECT m.id_pm AS pm_id, m.id_member_from AS pm_owner, m.id_member_from AS pm_author, '
                    . 'm.subject AS pm_name, m.msgtime AS pm_time, m.id_pm_head AS pm_head, m.body AS pm_body FROM '
                    . $t('personal_messages') . ' m WHERE m.deleted_by_sender = 0 AND m.id_member_from > 0 '
                    . 'UNION ALL SELECT r.id_pm, r.id_member, m.id_member_from, m.subject, m.msgtime, '
                    . 'm.id_pm_head, m.body FROM ' . $t('pm_recipients') . ' r INNER JOIN '
                    . $t('personal_messages') . ' m ON m.id_pm = r.id_pm WHERE r.deleted = 0 AND r.id_member > 0 '
                    . 'AND (r.id_member <> m.id_member_from OR m.deleted_by_sender = 1)) x',
                    ['x.pm_id', 'x.pm_owner'], '1 = 1'],
                'mybb' => ['m.pmid AS id, m.uid AS owner, m.fromid AS author, m.subject AS name, '
                    . 'm.dateline AS time, m.toid AS partner, ' . $body('m.message'), $t('privatemessages') . ' m',
                    ['m.pmid'], 'm.uid > 0'],
                'phpbb' => ['r.msg_id AS id, r.user_id AS owner, m.author_id AS author, '
                    . 'm.message_subject AS name, m.message_time AS time, '
                    . 'CASE WHEN m.root_level > 0 THEN m.root_level ELSE m.msg_id END AS chain_root, '
                    . $body('m.message_text'),
                    $t('privmsgs_to') . ' r INNER JOIN ' . $t('privmsgs') . ' m ON m.msg_id = r.msg_id',
                    ['r.msg_id', 'r.user_id'], 'r.pm_deleted = 0 AND r.user_id > 1'],
            };
        } elseif ($phase === 'attachments' || $phase === 'private_files') {
            [$select, $from, $keys, $where] = match ($this->engine) {
                'smf' => ['a.id_attach AS id, a.id_msg AS post, a.id_member AS user, a.filename AS name, '
                    . 'a.file_hash AS stored, a.id_folder AS folder, a.size AS bytes, a.approved AS visible, '
                    . 'COALESCE(m.poster_time, 0) AS time',
                    $t('attachments') . ' a LEFT JOIN ' . $t('messages') . ' m ON m.id_msg = a.id_msg',
                    ['a.id_attach'], 'a.attachment_type = 0'],
                'mybb' => ['a.aid AS id, a.pid AS post, a.uid AS user, a.filename AS name, '
                    . 'a.attachname AS stored, 1 AS folder, a.filesize AS bytes, a.visible AS visible, '
                    . 'a.dateuploaded AS time', $t('attachments') . ' a', ['a.aid'], '1 = 1'],
                'phpbb' => ['a.attach_id AS id, a.post_msg_id AS post, a.poster_id AS user, '
                    . 'a.real_filename AS name, a.physical_filename AS stored, 1 AS folder, '
                    . 'a.filesize AS bytes, 1 AS visible, a.filetime AS time'
                    . ($phase === 'private_files' ? ', r.user_id AS owner' : ''),
                    $t('attachments') . ' a' . ($phase === 'private_files' ? ' INNER JOIN ' . $t('privmsgs_to')
                        . ' r ON r.msg_id = a.post_msg_id' : ''),
                    $phase === 'private_files' ? ['a.attach_id', 'r.user_id'] : ['a.attach_id'],
                    $phase === 'private_files' ? 'a.in_message = 1 AND a.is_orphan = 0 AND r.pm_deleted = 0 '
                        . 'AND r.user_id > 1' : 'a.in_message = 0'],
            };
        } elseif ($phase === 'polls') {
            [$select, $from, $keys, $where] = match ($this->engine) {
                'smf' => ['t.id_topic AS id, t.id_topic AS thread, p.id_member AS user, p.question AS name, '
                    . 'p.max_votes AS choices, p.expire_time AS closes, p.voting_locked AS locked, '
                    . 'p.hide_results AS results', $t('topics') . ' t INNER JOIN ' . $t('polls')
                    . ' p ON p.id_poll = t.id_poll', ['t.id_topic'], 't.id_poll > 0'],
                'mybb' => ['p.pid AS id, p.tid AS thread, 0 AS user, p.question AS name, '
                    . 'CASE WHEN p.multiple = 0 THEN 1 WHEN p.maxoptions > 0 THEN p.maxoptions '
                    . 'ELSE p.numoptions END AS choices, CASE WHEN p.timeout > 0 '
                    . 'THEN p.dateline + p.timeout * 86400 ELSE 0 END AS closes, p.closed AS locked, 0 AS results',
                    $t('polls') . ' p', ['p.pid'], '1 = 1'],
                'phpbb' => ['t.topic_id AS id, t.topic_id AS thread, t.topic_poster AS user, '
                    . 't.poll_title AS name, t.poll_max_options AS choices, CASE WHEN t.poll_length > 0 '
                    . 'THEN t.poll_start + t.poll_length ELSE 0 END AS closes, '
                    . 'CASE WHEN t.topic_status = 1 THEN 1 ELSE 0 END AS locked, 0 AS results',
                    $t('topics') . ' t', ['t.topic_id'], "t.poll_start > 0 AND t.poll_title <> ''"],
            };
        } elseif ($phase === 'poll_options' && $this->engine === 'mybb') {
            return $this->mybbOptions($after, $limit);
        } elseif ($phase === 'poll_options') {
            [$select, $from, $keys] = $this->engine === 'smf'
                ? ['t.id_topic AS id, t.id_topic AS poll, c.id_choice AS option, c.label AS label, c.votes AS votes',
                    $t('topics') . ' t INNER JOIN ' . $t('poll_choices') . ' c ON c.id_poll = t.id_poll',
                    ['t.id_topic', 'c.id_choice']]
                : ['p.topic_id AS id, p.topic_id AS poll, p.poll_option_id AS option, p.poll_option_text AS label, '
                    . 'p.poll_option_total AS votes', $t('poll_options') . ' p', ['p.topic_id', 'p.poll_option_id']];
        } elseif ($phase === 'poll_votes') {
            [$select, $from, $keys, $where] = match ($this->engine) {
                'smf' => ['t.id_topic AS id, t.id_topic AS poll, v.id_member AS user, v.id_choice AS option',
                    $t('topics') . ' t INNER JOIN ' . $t('log_polls') . ' v ON v.id_poll = t.id_poll',
                    ['t.id_topic', 'v.id_member', 'v.id_choice'], 'v.id_member > 0'],
                'mybb' => ['v.vid AS id, v.pid AS poll, v.uid AS user, v.voteoption AS option',
                    $t('pollvotes') . ' v', ['v.vid'], '1 = 1'],
                'phpbb' => ['DISTINCT v.topic_id AS id, v.topic_id AS poll, v.vote_user_id AS user, '
                    . 'v.poll_option_id AS option', $t('poll_votes') . ' v',
                    ['v.topic_id', 'v.poll_option_id', 'v.vote_user_id'], 'v.vote_user_id > 1'],
            };
        } else {
            throw new RuntimeException('import_entity_invalid');
        }
        $select = preg_replace_callback(
            '/\bAS ([A-Za-z_][A-Za-z0-9_]*)\b/i',
            fn(array $match): string => 'AS ' . $this->db->quoteIdentifier($match[1]),
            $select
        );
        $values = ImportCursor::parts($after, count($keys));
        [$predicate, $params] = self::predicate($keys, $values);
        $rows = $this->db->fetchAllAssociative('SELECT ' . $select . ' FROM ' . $from . ' WHERE (' . $where
            . ') AND (' . $predicate . ') ORDER BY ' . implode(', ', $keys) . ' LIMIT ' . $limit, $params);
        foreach ($rows as &$row) {
            if (in_array(null, $row, true)) {
                throw new RuntimeException('import_source_row_invalid');
            }
            $row['id'] = (int) $row['id'];
            $parts = [$row['id']];
            if (count($keys) === 2) {
                $parts[] = (int) $row[str_contains($keys[1], 'user_id') || str_contains($keys[1], 'id_member')
                    || str_contains($keys[1], 'owner') ? 'owner' : 'option'];
            } elseif (count($keys) === 3) {
                $parts = $this->engine === 'smf' ? [$row['id'], (int) $row['user'], (int) $row['option']]
                    : [$row['id'], (int) $row['option'], (int) $row['user']];
            }
            $row['_key'] = ImportCursor::key($parts);
            foreach (['name', 'label', 'body'] as $field) {
                if (isset($row[$field])) {
                    $row[$field] = SourceText::decode((string) $row[$field], $this->charset);
                    if ($this->engine === 'phpbb' && $field !== 'body' && str_starts_with($phase, 'poll')) {
                        // phpBB stores poll questions and options in its s9e storage format too.
                        $row[$field] = SourceText::plain($row[$field], 'phpbb');
                    }
                }
            }
            if ($phase === 'private_inbox') {
                $row['chain'] = self::chain($this->engine, $row);
                unset($row['chain_root'], $row['partner']);
            }
        }
        unset($row);
        return $rows;
    }

    /** The conversation a mailbox copy belongs to, as a short key (1.1.0). */
    public static function chain(string $engine, array $row): string
    {
        if ($engine !== 'mybb') {
            return ($engine === 'smf' ? 'h' : 'r') . (int) ($row['chain_root'] ?? $row['id']);
        }
        $people = [(int) $row['author'], (int) ($row['partner'] ?? 0)];
        sort($people);
        $subject = mb_strtolower(trim((string) $row['name']), 'UTF-8');
        do {
            $before = $subject;
            $subject = trim((string) preg_replace('/^(?:re|fw|fwd|aw|ynt|cvp)\s*(?:\[[0-9]+\])?\s*:\s*/iu', '',
                $subject));
        } while ($subject !== $before);
        return 's' . substr(hash('sha256', implode(',', $people) . "\n" . $subject), 0, 40);
    }

    private function mybbOptions(string $after, int $limit): array
    {
        [$poll, $option] = ImportCursor::parts($after, 2);
        $rows = $this->db->fetchAllAssociative('SELECT pid, options, votes, numoptions FROM '
            . $this->db->quoteIdentifier($this->prefix . 'polls') . ' WHERE pid >= ? ORDER BY pid LIMIT 2', [$poll]);
        $result = [];
        foreach ($rows as $row) {
            if (strlen($row['options']) > 65536 || (int) $row['numoptions'] > 100) {
                throw new RuntimeException('import_poll_budget');
            }
            // MyBB 1.8 writes "||~|~||" between options; copies rewritten to "||~|" are still read.
            $labels = explode('||~|~||', $row['options']);
            $votes = explode('||~|~||', $row['votes']);
            if (count($labels) !== (int) $row['numoptions'] || count($labels) !== count($votes)) {
                $labels = explode('||~|', $row['options']);
                $votes = explode('||~|', $row['votes']);
            }
            if (count($labels) !== (int) $row['numoptions'] || count($labels) !== count($votes)) {
                throw new RuntimeException('import_poll_source_invalid');
            }
            foreach ($labels as $index => $label) {
                if ((int) $row['pid'] === $poll && $index + 1 <= $option) {
                    continue;
                }
                if (preg_match('/^[0-9]{1,18}$/D', $votes[$index]) !== 1) {
                    throw new RuntimeException('import_poll_source_invalid');
                }
                $result[] = ['id' => (int) $row['pid'], 'poll' => (int) $row['pid'], 'option' => $index + 1,
                    'label' => SourceText::decode($label, $this->charset), 'votes' => (int) $votes[$index],
                    '_key' => ImportCursor::key([(int) $row['pid'], $index + 1])];
                if (count($result) >= $limit) {
                    return $result;
                }
            }
        }
        return $result;
    }

    private static function predicate(array $keys, array $values): array
    {
        $conditions = [];
        $params = [];
        foreach ($keys as $index => $key) {
            $terms = [];
            for ($equal = 0; $equal < $index; ++$equal) {
                $terms[] = $keys[$equal] . ' = ?';
                $params[] = $values[$equal];
            }
            $terms[] = $key . ' > ?';
            $params[] = $values[$index];
            $conditions[] = '(' . implode(' AND ', $terms) . ')';
        }
        return [implode(' OR ', $conditions), $params];
    }
}
