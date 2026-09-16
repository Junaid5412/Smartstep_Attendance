<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once ATT_PATH . '/includes/api.php';
require_once ATT_PATH . '/includes/admin_auth.php';

attAdminLogout();
attRedirect(ATT_URL . '/admin/login.php');
