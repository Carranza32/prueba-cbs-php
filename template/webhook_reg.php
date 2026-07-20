<?php /* Template Name: WebhookRegistration */ ?>

<?php


$json = file_get_contents('php://input');
$body = json_decode($json, true);

if (is_null($body) or !isset($body['eventName'])) {
    // When something goes wrong, return an invalid status code
    // such as 400 BadRequest.
    header('HTTP/1.1 400 Bad Request');
    return;
}

switch ($body['eventName']) {
    case 'order.completed':
        // This is an order:completed event
        // do what needs to be done here.
        break;
}

// Return a valid status code such as 200 OK.
header('HTTP/1.1 200 OK');