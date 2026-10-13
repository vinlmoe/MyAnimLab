<?php
/**
 * User Registration (disabled)
 *
 * Self-registration is disabled: accounts are created by an administrator
 * from manage_users.php. Any request to this page is sent back to the login page.
 */
header("Location: index.php");
exit;
