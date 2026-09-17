<?php
require_once __DIR__ . '/../inc/auth.php';
nc_logout();
header('Location: index.php');
