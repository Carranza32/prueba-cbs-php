<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>

<!doctype html>
<html lang="en">
<head>
    <title>Error on Sending Payment to WOAPI</title>
</head>
<body>
    <h1>There is an Error on Sending Payment to WOAPI</h1>
    <p>ORIGIN: <?php echo esc_html__($origin) ?></p>
    <p>HOST: <?php echo esc_html__($host) ?></p>
    <p>SERVER_NAME: <?php echo esc_html__($server_name) ?></p>
    <p>Message: <?php echo esc_html__($message) ?></p>
</body>
</html>
