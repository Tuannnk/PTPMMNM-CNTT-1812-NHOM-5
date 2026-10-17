<?php
require __DIR__ . '/../src/bootstrap.php';
requirePost();
unset($_SESSION['user_id']);
session_regenerate_id(true);
redirect('/login.php');
