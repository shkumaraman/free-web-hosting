<?php
$status = $_SERVER['REDIRECT_STATUS'] ?? 404;
$codes = [
    400 => ['Bad Request', 'Your browser sent a request that this server could not understand.'],
    401 => ['Authorization Required', 'This server could not verify that you are authorized to access the document requested.'],
    403 => ['Forbidden', 'You don\'t have permission to access this resource.'],
    404 => ['Not Found', 'The requested URL was not found on this server.'],
    500 => ['Internal Server Error', 'The server encountered an internal error or misconfiguration and was unable to complete your request.']
];
$errTitle = $codes[$status][0] ?? 'Error';
$errDesc = $codes[$status][1] ?? 'An error occurred while processing your request.';
$port = $_SERVER['SERVER_PORT'] ?? 80;
$portStr = ($port != 80 && $port != 443) ? " Port {$port}" : "";
?>
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title><?php echo $status; ?> <?php echo htmlspecialchars($errTitle); ?></title>
</head><body>
<h1><?php echo htmlspecialchars($errTitle); ?></h1>
<p><?php echo htmlspecialchars($errDesc); ?></p>
<p>Additionally, a <?php echo $status; ?> <?php echo htmlspecialchars($errTitle); ?>
error was encountered while trying to use an ErrorDocument to handle the request.</p>
<hr>
<address>Apache Server at <?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost'); ?><?php echo $portStr; ?></address>
</body></html>
