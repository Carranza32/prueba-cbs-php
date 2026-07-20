

<?php

require($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');

$total_products = count(get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => '-1')));

$data[0] = $total_products;


echo json_encode($data);
