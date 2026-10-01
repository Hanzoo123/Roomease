<?php
/**
 * The audit log (who did what), and telling landlords about decisions on
 * their listings by email and on their dashboard.
 */

/** Days sign-in records are kept. */

const AUDIT_SIGNIN_DAYS = 90;

/**
 * Every action the log records: its label, badge, what it acts on, and its
 * tab (admin, landlord or signin). Room changes are filed under the listing.
 */

function audit_action_types()
{
    return [
        // What administrators decide and export.
        'listing_approve' => ['label' => 'Approved listing',    'badge' => 'badge-success',   'target' => 'listing', 'group' => 'admin'],
        'listing_reject'  => ['label' => 'Rejected listing',    'badge' => 'badge-warning',   'target' => 'listing', 'group' => 'admin'],
        'listing_remove'  => ['label' => 'Removed listing',     'badge' => 'badge-danger',    'target' => 'listing', 'group' => 'admin'],
        'listing_restore' => ['label' => 'Restored listing',    'badge' => 'badge-info',      'target' => 'listing', 'group' => 'admin'],
        'user_activate'   => ['label' => 'Activated account',   'badge' => 'badge-success',   'target' => 'user',    'group' => 'admin'],
        'user_deactivate' => ['label' => 'Deactivated account', 'badge' => 'badge-warning',   'target' => 'user',    'group' => 'admin'],
        'user_remove'     => ['label' => 'Removed account',     'badge' => 'badge-danger',    'target' => 'user',    'group' => 'admin'],
        'user_restore'    => ['label' => 'Restored account',    'badge' => 'badge-info',      'target' => 'user',    'group' => 'admin'],
        'export_users'    => ['label' => 'Exported users',      'badge' => 'badge-secondary', 'target' => 'export',  'group' => 'admin'],
        'export_listings' => ['label' => 'Exported listings',   'badge' => 'badge-secondary', 'target' => 'export',  'group' => 'admin'],

        // What a super admin does to other admins.
        'admin_add'        => ['label' => 'Added administrator',       'badge' => 'badge-success', 'target' => 'administrator', 'group' => 'admin'],
        'admin_activate'   => ['label' => 'Activated administrator',   'badge' => 'badge-success', 'target' => 'administrator', 'group' => 'admin'],
        'admin_deactivate' => ['label' => 'Deactivated administrator', 'badge' => 'badge-warning', 'target' => 'administrator', 'group' => 'admin'],
        'admin_remove'     => ['label' => 'Removed administrator',     'badge' => 'badge-danger',  'target' => 'administrator', 'group' => 'admin'],
        'admin_restore'    => ['label' => 'Restored administrator',    'badge' => 'badge-info',    'target' => 'administrator', 'group' => 'admin'],
        'admin_promote'    => ['label' => 'Made super admin',          'badge' => 'badge-primary', 'target' => 'administrator', 'group' => 'admin'],
        'admin_demote'     => ['label' => 'Removed super admin',       'badge' => 'badge-warning', 'target' => 'administrator', 'group' => 'admin'],
        'admin_password'   => ['label' => 'Reset administrator password', 'badge' => 'badge-warning', 'target' => 'administrator', 'group' => 'admin'],

        // What landlords change.
        'listing_create'  => ['label' => 'Created listing',     'badge' => 'badge-success',   'target' => 'listing', 'group' => 'landlord'],
        'listing_edit'    => ['label' => 'Edited listing',      'badge' => 'badge-info',      'target' => 'listing', 'group' => 'landlord'],
        'listing_delete'  => ['label' => 'Deleted listing',     'badge' => 'badge-danger',    'target' => 'listing', 'group' => 'landlord'],
        // An approved listing edited, so back to pending.
        'listing_resubmit' => ['label' => 'Sent back for review', 'badge' => 'badge-warning', 'target' => 'listing', 'group' => 'landlord'],
        'room_create'     => ['label' => 'Added room',          'badge' => 'badge-success',   'target' => 'listing', 'group' => 'landlord'],
        'room_edit'       => ['label' => 'Edited room',         'badge' => 'badge-info',      'target' => 'listing', 'group' => 'landlord'],
        'room_delete'     => ['label' => 'Deleted room',        'badge' => 'badge-danger',    'target' => 'listing', 'group' => 'landlord'],
        'room_open'       => ['label' => 'Opened room',         'badge' => 'badge-success',   'target' => 'listing', 'group' => 'landlord'],
        'room_close'      => ['label' => 'Closed room',         'badge' => 'badge-warning',   'target' => 'listing', 'group' => 'landlord'],
        'room_slot_taken' => ['label' => 'Tenant moved in',     'badge' => 'badge-secondary', 'target' => 'listing', 'group' => 'landlord'],
        'room_slot_freed' => ['label' => 'Tenant moved out',    'badge' => 'badge-secondary', 'target' => 'listing', 'group' => 'landlord'],
        'photos_add'      => ['label' => 'Added photos',        'badge' => 'badge-secondary', 'target' => 'listing', 'group' => 'landlord'],
        'photo_remove'    => ['label' => 'Removed photo',       'badge' => 'badge-secondary', 'target' => 'listing', 'group' => 'landlord'],

        // Sign-ins, passwords and emails.
        'signin'          => ['label' => 'Signed in',           'badge' => 'badge-success',   'target' => 'account', 'group' => 'signin'],
        'signin_failed'   => ['label' => 'Failed sign-in',      'badge' => 'badge-danger',    'target' => 'account', 'group' => 'signin'],
        'signout'         => ['label' => 'Signed out',          'badge' => 'badge-secondary', 'target' => 'account', 'group' => 'signin'],
        'signup'          => ['label' => 'Created account',     'badge' => 'badge-info',      'target' => 'account', 'group' => 'signin'],
        'password_change' => ['label' => 'Changed password',    'badge' => 'badge-warning',   'target' => 'account', 'group' => 'signin'],
        'email_change'    => ['label' => 'Changed email',       'badge' => 'badge-warning',   'target' => 'account', 'group' => 'signin'],
        'password_reset'  => ['label' => 'Reset password',      'badge' => 'badge-warning',   'target' => 'account', 'group' => 'signin'],
    ];
}

/** The actions in one tab (admin, landlord or signin). */

function audit_actions_in_group($group)
{
    return array_keys(array_filter(audit_action_types(), function ($type) use ($group) {
        return $type['group'] === $group;
    }));
}

/**
 * Write one entry to the audit log. The actor is the signed-in user, unless
 * $actor gives another user row ([] for nobody). $targetLabel keeps the name
 * as it is now, so the entry still makes sense after a rename. A failed write
 * is logged but never blocks the action.
 */

function audit_log($action, $targetId, $targetLabel, $detail = null, ?array $actor = null)
{
    global $pdo;

    $types = audit_action_types();
    if (!isset($types[$action])) {
        error_log('RoomEase: unknown audit log action ' . $action);
        return;
    }

    if ($actor === null) {
        $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $actorRole = $_SESSION['role'] ?? null;
    } else {
        $actorId = isset($actor['user_id']) ? (int) $actor['user_id'] : null;
        $actorRole = $actor['role'] ?? null;
    }

    $isSignin = $types[$action]['group'] === 'signin';

    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_id, actor_role, action, target_type, target_id, target_label, detail,
                                     ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorId,
            $actorRole,
            $action,
            $types[$action]['target'],
            $targetId === null ? null : (int) $targetId,
            mb_substr((string) $targetLabel, 0, 200),
            ($detail === null || trim((string) $detail) === '') ? null : mb_substr(trim((string) $detail), 0, 500),
            $isSignin ? client_ip() : null,
            $isSignin ? short_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '') : null,
        ]);
    } catch (PDOException $e) {
        error_log('RoomEase: could not write the audit log - ' . $e->getMessage());
    }
}

/** What was typed in a failed login, for the log. Not saved unless it is an email (it might be a password). */

function audit_typed_login($loginId)
{
    $loginId = trim((string) $loginId);
    return filter_var($loginId, FILTER_VALIDATE_EMAIL) ? $loginId : '(not an email address)';
}

/**
 * "Changed: rent, house rules" (field names only, not values), or null if
 * nothing changed. "500.00" and "500" count as the same.
 */

function audit_changed_fields(array $before, array $after, array $labels)
{
    $changed = [];
    foreach ($labels as $key => $label) {
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;
        // Ignore \r\n vs \n line-break differences.
        $old = $old === null ? '' : trim(str_replace("\r\n", "\n", (string) $old));
        $new = $new === null ? '' : trim(str_replace("\r\n", "\n", (string) $new));
        $same = (is_numeric($old) && is_numeric($new)) ? (float) $old === (float) $new : $old === $new;
        if (!$same) {
            $changed[] = $label;
        }
    }
    return $changed ? 'Changed: ' . implode(', ', $changed) : null;
}

/** "Chrome on Windows" from a User-Agent string (not the full string, for privacy). */

function short_user_agent($ua)
{
    $ua = (string) $ua;
    if ($ua === '') {
        return null;
    }
    $browser = 'Browser';
    foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox',
              'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $browser = $name;
            break;
        }
    }
    $system = 'another system';
    foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows',
              'Mac OS' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $system = $name;
            break;
        }
    }
    return $browser . ' on ' . $system;
}

/** Delete sign-in records older than AUDIT_SIGNIN_DAYS (Privacy Policy). Runs when the Audit Log opens. */

function audit_purge_old_signins()
{
    global $pdo;
    $actions = audit_actions_in_group('signin');
    try {
        $pdo->prepare(
            'DELETE FROM audit_logs
              WHERE action IN (' . sql_placeholders(count($actions)) . ')
                AND created_at < NOW() - INTERVAL ' . AUDIT_SIGNIN_DAYS . ' DAY'
        )->execute($actions);
    } catch (PDOException $e) {
        error_log('RoomEase: could not purge old sign-in records - ' . $e->getMessage());
    }
}

/** Log entries for one listing or account, newest first. $group limits it to one tab. */

function audit_entries_for($targetType, $targetId, $limit = 20, $group = null)
{
    global $pdo;
    $params = [$targetType, (int) $targetId];
    $groupSql = '';
    if ($group !== null) {
        $actions = audit_actions_in_group($group);
        $groupSql = ' AND a.action IN (' . sql_placeholders(count($actions)) . ')';
        $params = array_merge($params, $actions);
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, " . account_name_sql('u') . " AS admin_name, u.avatar_path
               FROM audit_logs a
               LEFT JOIN users u ON u.user_id = a.actor_id
              WHERE a.target_type = ? AND a.target_id = ?$groupSql
              ORDER BY a.created_at DESC, a.log_id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Admin decisions on a landlord's listings in the last $days days, removed listings included. */

function landlord_recent_decisions($landlordId, $days = 30, $limit = 5)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT a.action, a.detail, a.created_at, bh.boarding_house_id, bh.name, bh.deleted_at
               FROM audit_logs a
               JOIN boarding_houses bh ON bh.boarding_house_id = a.target_id
              WHERE a.target_type = 'listing' AND bh.landlord_id = ?
                AND a.action IN ('listing_approve', 'listing_reject', 'listing_remove', 'listing_restore')
                AND a.created_at > NOW() - INTERVAL " . (int) $days . " DAY
              ORDER BY a.created_at DESC, a.log_id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([(int) $landlordId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** The page a log entry links to, or null. */

function admin_target_url($targetType, $targetId)
{
    if ($targetId === null) {
        return null;
    }
    switch ($targetType) {
        case 'listing':
            return base_url('admin/listing.php?id=' . (int) $targetId);
        case 'user':
        case 'account':
            return base_url('admin/user.php?id=' . (int) $targetId);
        // Only super admins can open the admins list.
        case 'administrator':
            return is_super_admin() ? base_url('admin/admins.php') : null;
        default:
            return null;
    }
}

/** If a pending listing was approved before and then edited, the entry saying what changed. Else null. */

function listing_resubmission($listingId)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT action, detail, created_at FROM audit_logs
              WHERE target_type = 'listing' AND target_id = ?
                AND action IN ('listing_resubmit', 'listing_approve', 'listing_reject')
              ORDER BY created_at DESC, log_id DESC
              LIMIT 1"
        );
        $stmt->execute([(int) $listingId]);
        $latest = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    return $latest && $latest['action'] === 'listing_resubmit' ? $latest : null;
}

/** Email the landlord about a decision. True (sent), false (failed), null (their account is off). */

function notify_landlord_of_decision($listingId, $action, $reason = null)
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT bh.name, bh.moderation_status, u.email, u.first_name, u.is_active, u.deleted_at
           FROM boarding_houses bh
           JOIN users u ON u.user_id = bh.landlord_id
          WHERE bh.boarding_house_id = ?'
    );
    $stmt->execute([(int) $listingId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['is_active'] !== 1 || $row['deleted_at'] !== null) {
        return null;
    }

    $name = '"' . $row['name'] . '"';
    $reason = trim((string) $reason);

    switch ($action) {
        case 'listing_approve':
            $subject = 'Your listing is approved';
            $paragraphs = [$name . ' is approved. Boarders can now find it on RoomEase.'];
            break;
        case 'listing_reject':
            $subject = 'Your listing needs changes';
            $paragraphs = [
                $name . ' was not approved yet.',
                'What to change: ' . $reason,
                'Edit the listing from your dashboard. When you save your changes, it goes back for review.',
            ];
            break;
        case 'listing_remove':
            $subject = 'Your listing was removed';
            $paragraphs = array_values(array_filter([
                'An administrator removed ' . $name . ' from RoomEase, so boarders can no longer see it.',
                $reason !== '' ? 'Reason: ' . $reason : null,
                'If you think this is a mistake, contact the RoomEase administrator.',
            ]));
            break;
        case 'listing_restore':
            $subject = 'Your listing is back';
            // A listing the landlord deleted is restored as pending.
            $after = [
                'approved' => ' Boarders can see it again.',
                'pending'  => ' It is waiting for an administrator\'s approval before boarders can see it.',
            ][$row['moderation_status']] ?? '';
            $paragraphs = [$name . ' was restored and is on your dashboard again.' . $after];
            break;
        default:
            return false;
    }

    $dashboard = absolute_url('landlord/dashboard.php');

    $text = 'Hi ' . $row['first_name'] . ",\r\n\r\n"
        . implode("\r\n\r\n", $paragraphs) . "\r\n\r\n"
        . 'Your dashboard: ' . $dashboard . "\r\n\r\n"
        . "RoomEase\r\n";

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:32px 16px;background:#FAF8F3;">'
        . '<div style="max-width:480px;margin:0 auto;font-family:\'IBM Plex Sans\',\'Segoe UI\',Helvetica,Arial,sans-serif;'
        . 'font-size:15px;line-height:1.6;color:#1F2A28;">'
        . '<p style="margin:0 0 28px;font-family:Georgia,\'Times New Roman\',serif;font-size:20px;color:#184A3F;">RoomEase</p>'
        . '<p style="margin:0 0 16px;">Hi ' . h($row['first_name']) . ',</p>';
    foreach ($paragraphs as $paragraph) {
        $html .= '<p style="margin:0 0 16px;">' . h($paragraph) . '</p>';
    }
    $html .= '<p style="margin:24px 0 0;"><a href="' . h($dashboard) . '" style="display:inline-block;padding:10px 18px;'
        . 'border-radius:8px;background:#184A3F;color:#FFFFFF;text-decoration:none;font-weight:600;">Open your dashboard</a></p>'
        . '</div></body></html>';

    return send_mail($row['email'], $subject, $text, $html);
}
