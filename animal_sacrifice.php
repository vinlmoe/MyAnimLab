<?php

/**
 * Kept for existing links: exits (sacrifice, death, export...) are recorded
 * with an exit code on animal_exit.php.
 */

header("Location: animal_exit.php?" . http_build_query(['id' => (int) ($_GET['id'] ?? 0)]));
exit;
