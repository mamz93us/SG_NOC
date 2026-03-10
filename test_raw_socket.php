<?php
require '/home/azureuser/phonebook2/vendor/autoload.php';
$app = require_once '/home/azureuser/phonebook2/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$s = App\Models\Setting::first();
if (!$s) die("NO SETTINGS FOUND\n");

$pass = $s->smtp_password;
if (!$pass) die("NO PASSWORD FOUND\n");

echo "HANDSHAKE START\n";
$host = $s->smtp_host;
$port = $s->smtp_port ?? 587;
$user = $s->smtp_username;

$socket = fsockopen($host, $port, $errno, $errstr, 20);
if (!$socket) die("SOCKET FAILED: $errstr ($errno)\n");

echo "< " . fgets($socket, 512);

function talk($socket, $cmd) {
    echo "> $cmd\n";
    fputs($socket, $cmd . "\r\n");
    $resp = "";
    while ($line = fgets($socket, 512)) {
        $resp .= $line;
        if (substr($line, 3, 1) == " ") break;
    }
    echo "< $resp";
    return $resp;
}

talk($socket, "EHLO noc.samirgroup.net");
talk($socket, "STARTTLS");
stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
talk($socket, "EHLO noc.samirgroup.net");
talk($socket, "AUTH LOGIN");
talk($socket, base64_encode($user));
talk($socket, base64_encode($pass));
talk($socket, "QUIT");
fclose($socket);
