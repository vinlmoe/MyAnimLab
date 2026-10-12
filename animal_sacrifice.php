<?php

/**
 * Kept for existing links: sacrifice is now one of the exit types handled by
 * animal_exit.php (sacrificed / died / exported).
 */

header("Location: animal_exit.php?" . http_build_query(['id' => (int) ($_GET['id'] ?? 0), 'type' => 'sacrificed']));
exit;
