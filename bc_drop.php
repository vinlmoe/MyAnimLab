<?php

/**
 * Kept for existing links: retiring a cage now goes through cage_retire.php,
 * where each living animal must be given a fate (move to another cage, or
 * exit: sacrificed / died / exported) before the cage is retired.
 */

header("Location: cage_retire.php?id=" . rawurlencode($_GET['id'] ?? ''));
exit;
