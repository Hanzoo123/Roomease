<?php
require __DIR__ . '/../includes/functions.php';

$_SESSION = [];
session_destroy();
session_start();
flash_set('You have been logged out.', 'success');
redirect('index.php');
