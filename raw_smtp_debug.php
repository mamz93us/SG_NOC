<?php
$host = "smtp.office365.com";
$port = 587;
$user = "noc@samirgroup.com";
// THIS IS FOR DEBUG ON YOUR PRIVATE SERVER ONLY
$pass = trim(file_get_contents('/tmp/smtp_pass_debug.txt')); // I will delete this after

echo "Starting Manual SMTP Handshake to $host:$port\n";

$socket = fsockopen($host, $port, $errno, $errstr, 30);
if (!$socket) exit("Failed to open socket: $errstr ($errno)\n");

function send($socket, $cmd) {
    echo "> $cmd\n";
    fputs($socket, $cmd . "\r\n");
    $resp = "";
    while($line = fgets($socket, 512)) {
        $resp .= $line;
        if (substr($line, 3, 1) == " ") break;
    }
    echo "< $resp";
    return $resp;
}

fgets($socket, 512); // Initial greeting
send($socket, "EHLO noc.samirgroup.net");
send($socket, "STARTTLS");
stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
send($socket, "EHLO noc.samirgroup.net");
send($socket, "AUTH LOGIN");
send($socket, base64_encode($user));
send($socket, base64_encode($pass));
send($socket, "QUIT");
fclose($socket);
