<?php
/**
 * Admin actions on a listing: approve, reject (with a reason), remove or
 * restore. Remove hides it (deleted_at) instead of deleting, so it can be
 * restored. Each action is logged and the landlord is emailed.
 *
 * Replies with JSON for fetch() on Manage Listings (listing_actions_js.php):
 * the updated table row, whether it still belongs in the tab being shown,
 * and the new tab counts. Otherwise flashes and redirects back.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/components/admin_listing_row.php';

$wantsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);
$action = $_POST['action'] ?? '';

// Where to go afterwards: back where the admin was. 'next' is worked out
// at the end, after this action.
$returnStatus = in_array($_POST['return_status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_POST['return_status'] : '';
$returnRemoved = ($_POST['return_view'] ?? '') === 'removed';
$goToNext = ($_POST['return_to'] ?? '') === 'next' && !$wantsJson;
if ($goToNext || (($_POST['return_to'] ?? '') === 'review' && $boardingHouseId > 0)) {
    $returnTo = 'admin/listing.php?id=' . $boardingHouseId;
} elseif ($returnRemoved) {
    $returnTo = 'admin/manage_listings.php?view=removed';
} elseif ($returnStatus !== '') {
    $returnTo = 'admin/manage_listings.php?status=' . $returnStatus;
} else {
    $returnTo = 'admin/manage_listings.php';
}

/** JSON for fetch() (no flash, or it would pop up on the next page), else flash and redirect. */
function listing_action_reply($status, array $payload, $path)
{
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
    flash_set($payload['message'] ?? 'Done.', empty($payload['ok']) ? 'error' : 'success');
    redirect($path);
}

/** A refusal: nothing changed. */
function listing_action_fail($status, $message, $path)
{
    listing_action_reply($status, ['ok' => false, 'message' => $message], $path);
}

// Signed out (or no longer an admin) while the page was open: fetch() is sent
// to the sign-in page instead of getting its HTML.
if ($wantsJson && (!is_logged_in() || !in_array(current_role(), ADMIN_ROLES, true))) {
    listing_action_reply(401, ['ok' => false, 'redirect' => base_url(ADMIN_LOGIN_PATH),
        'message' => 'Please sign in again.'], ADMIN_LOGIN_PATH);
}
require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_listings.php');
}
if ($wantsJson && !csrf_ok()) {
    listing_action_reply(403, ['ok' => false, 'reload' => true,
        'message' => 'Your session expired. Please refresh the page and try again.'], $returnTo);
}
verify_csrf();

$stmt = $pdo->prepare(
    'SELECT bh.boarding_house_id, bh.name, bh.moderation_status, bh.deleted_at,
            bh.deleted_by, bh.landlord_id,
            u.is_active AS landlord_active, u.deleted_at AS landlord_deleted_at
       FROM boarding_houses bh
       JOIN users u ON u.user_id = bh.landlord_id
      WHERE bh.boarding_house_id = ?'
);
$stmt->execute([$boardingHouseId]);
$listing = $stmt->fetch();

if (!$listing) {
    listing_action_fail(404, 'Listing not found.',
        strpos($returnTo, 'listing.php') !== false ? 'admin/manage_listings.php' : $returnTo);
}

// The name is whatever the landlord typed. The toast escapes its message, but
// stripping tags here as well keeps it safe anywhere a flash is ever rendered.
$name = strip_tags($listing['name']);
$adminId = (int) $_SESSION['user_id'];
$archived = $listing['deleted_at'] !== null;

/** What the message adds about the landlord's email. */
$emailNote = function ($sent) {
    if ($sent === null) {
        return ' The landlord\'s account is off, so no email was sent.';
    }
    if ($sent) {
        return ' The landlord was emailed.';
    }
    return mail_enabled()
        ? ' The email to the landlord could not be sent (see storage/mail.log); they will still see it on their dashboard.'
        : ' No email was sent because Gmail is not set up; the landlord will see it on their dashboard.';
};

if (($action === 'approve' || $action === 'reject') && $archived) {
    listing_action_fail(409, '"' . $name . '" is removed. Restore it before changing its approval.', $returnTo);
}

// Each UPDATE below only matches a listing not already in the new state, so
// a double click or two admins at once log and email the landlord once; the
// repeat just hears it's already done.
if ($action === 'approve') {
    // A listing with no rooms has nothing for a boarder to see, and the public
    // site would not show it anyway, so it is not approved yet.
    $rooms = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE boarding_house_id = ?');
    $rooms->execute([$boardingHouseId]);
    if ((int) $rooms->fetchColumn() === 0) {
        listing_action_fail(409, '"' . $name . '" has no rooms yet. The landlord needs to add at least one before it can be approved.', $returnTo);
    }

    // Not while the landlord's account is off: it would go public when restored.
    if ($listing['landlord_deleted_at'] !== null || (int) $listing['landlord_active'] !== 1) {
        listing_action_fail(409, '"' . $name . '" cannot be approved while its landlord\'s account is removed or deactivated. Restore the account first.', $returnTo);
    }

    $update = $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'approved', rejection_reason = NULL, moderated_at = NOW(), moderated_by = ?,
                updated_by = ?
          WHERE boarding_house_id = ? AND moderation_status <> 'approved' AND deleted_at IS NULL"
    );
    $update->execute([$adminId, $adminId, $boardingHouseId]);
    if ($update->rowCount() > 0) {
        audit_log('listing_approve', $boardingHouseId, $listing['name']);
        $sent = notify_landlord_of_decision($boardingHouseId, 'listing_approve');
        $message = '"' . $name . '" is now approved and visible to boarders.' . $emailNote($sent);
    } else {
        $message = '"' . $name . '" is already approved.';
    }

} elseif ($action === 'reject') {
    $reason = trim($_POST['rejection_reason'] ?? '');
    if ($reason === '') {
        listing_action_fail(422, 'Please give a reason so the landlord knows what to fix.', $returnTo);
    }
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }
    $update = $pdo->prepare(
        "UPDATE boarding_houses
            SET moderation_status = 'rejected', rejection_reason = ?, moderated_at = NOW(), moderated_by = ?,
                updated_by = ?
          WHERE boarding_house_id = ? AND moderation_status <> 'rejected' AND deleted_at IS NULL"
    );
    $update->execute([$reason, $adminId, $adminId, $boardingHouseId]);
    if ($update->rowCount() > 0) {
        audit_log('listing_reject', $boardingHouseId, $listing['name'], $reason);
        $sent = notify_landlord_of_decision($boardingHouseId, 'listing_reject', $reason);
        $message = '"' . $name . '" was rejected.' . $emailNote($sent);
    } else {
        $message = '"' . $name . '" is already rejected.';
    }

} elseif ($action === 'remove' || $action === 'delete') {
    // 'delete' is what older copies of the Manage Listings page post.
    $reason = mb_substr(trim($_POST['removal_reason'] ?? ''), 0, 500);

    $update = $pdo->prepare('UPDATE boarding_houses SET deleted_at = NOW(), deleted_by = ?, updated_by = ? WHERE boarding_house_id = ? AND deleted_at IS NULL');
    $update->execute([current_user_id(), current_user_id(), $boardingHouseId]);
    if ($update->rowCount() > 0) {
        audit_log('listing_remove', $boardingHouseId, $listing['name'], $reason);
        $sent = notify_landlord_of_decision($boardingHouseId, 'listing_remove', $reason);
        $message = '"' . $name . '" was removed from the site. It can be restored from the Removed tab.' . $emailNote($sent);
    } else {
        $message = '"' . $name . '" is already removed.';
    }
    if (strpos($returnTo, 'listing.php') === false) {
        $returnTo = 'admin/manage_listings.php?view=removed';
    }

} elseif ($action === 'restore') {
    // If the landlord deleted it, restore as pending: the rooms may no longer be for rent.
    $deletedByLandlord = $listing['deleted_by'] !== null
        && (int) $listing['deleted_by'] === (int) $listing['landlord_id'];
    $backToPending = $deletedByLandlord && $listing['moderation_status'] === 'approved';

    $update = $pdo->prepare('UPDATE boarding_houses SET deleted_at = NULL, deleted_by = NULL, updated_by = ?'
        . ($backToPending
            ? ", moderation_status = 'pending', rejection_reason = NULL, moderated_at = NULL, moderated_by = NULL"
            : '')
        . ' WHERE boarding_house_id = ? AND deleted_at IS NOT NULL');
    $update->execute([current_user_id(), $boardingHouseId]);
    if ($update->rowCount() > 0) {
        audit_log('listing_restore', $boardingHouseId, $listing['name'],
            $backToPending ? 'Back to pending, because its landlord had deleted it' : null);
        $sent = notify_landlord_of_decision($boardingHouseId, 'listing_restore');
        if ($backToPending) {
            $where = ' Its landlord had deleted it, so it is back with the pending listings,'
                . ' and boarders will see it once it is approved again.';
        } elseif ($listing['moderation_status'] === 'approved') {
            $where = ' It is visible to boarders again.';
        } else {
            $where = ' It is back with the ' . $listing['moderation_status'] . ' listings.';
        }
        $message = '"' . $name . '" was restored.' . $where . $emailNote($sent);
    } else {
        $message = '"' . $name . '" is already restored.';
    }
    if (strpos($returnTo, 'listing.php') === false) {
        $returnTo = 'admin/manage_listings.php';
    }

} else {
    listing_action_fail(400, 'Unknown listing action.', $returnTo);
}

// Manage Listings: the row as it is now, if it still belongs in the tab
// being shown (otherwise the page takes it out), and the new tab counts.
if ($wantsJson) {
    $row = $pdo->prepare(admin_listings_select() . ' WHERE bh.boarding_house_id = ?');
    $row->execute([$boardingHouseId]);
    $fresh = $row->fetch();
    $inView = $fresh && admin_listing_in_view($fresh, $returnRemoved, $returnStatus);
    listing_action_reply(200, [
        'ok'      => true,
        'message' => $message,
        'in_view' => $inView,
        'row'     => $inView ? admin_listing_row($fresh, $returnRemoved, $returnStatus) : null,
        'tally'   => admin_listing_tally(),
    ], $returnTo);
}

// "Approve & next": go to the oldest pending listing, or the empty Pending tab.
if ($goToNext) {
    $next = pending_queue_after($boardingHouseId)['next'];
    $returnTo = $next === null
        ? 'admin/manage_listings.php?status=pending'
        : 'admin/listing.php?id=' . (int) $next['boarding_house_id'];
}

listing_action_reply(200, ['ok' => true, 'message' => $message], $returnTo);
