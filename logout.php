<?php
session_start();
session_destroy();
header("Location: Uni_bruger.php");
exit();
?>
