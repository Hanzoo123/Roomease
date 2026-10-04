<?php
/**
 * Find places near me. The browse page posts the location its script got
 * from the browser, already rounded to about 100 m, with the search as it
 * was. The location goes into the visitor's session (see "Find places near
 * me" in includes/core/listings.php), never into a link or the database, and
 * the visitor goes back to browse with the nearest boarding houses first.
 */
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('boarder/browse.php');
}
verify_csrf();

$filters = array_diff_key(browse_filters($_POST, room_type_options()), ['page' => 1]);

$lat = $_POST['lat'] ?? '';
$lng = $_POST['lng'] ?? '';
if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
    flash_set('Your location could not be read. Please try again.', 'error');
    redirect(browse_path(array_diff_key($filters, ['near' => 1, 'within' => 1]), 'results'));
}

remember_near_location((float) $lat, (float) $lng);
redirect(browse_path(array_merge($filters, ['near' => 1, 'sort' => 'nearest']), 'results'));
