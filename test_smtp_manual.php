<?php
$user = 'noc@samirgroup.com';
$pass = 'gxqfdbmdfxhmmhgy';

$user64 = base64_encode($user);
$pass64 = base64_encode($pass);

echo "Testing SMTP manually via openssl...\n";

$descriptorspec = [
   0 => ["pipe", "r"], 
   1 => ["pipe", "w"], 
   2 => ["pipe", "w"]
];

$process = proc_open('openssl s_client -connect smtp.office365.com:587 -starttls smtp -quiet', $descriptorspec, $pipes);

if (is_resource($process)) {
    fwrite($pipes[0], "EHLO localhost\n");
    usleep(500000);
    fwrite($pipes[0], "AUTH LOGIN\n");
    usleep(500000);
    fwrite($pipes[0], "$user64\n");
    usleep(500000);
    fwrite($pipes[0], "$pass64\n");
    usleep(1000000);
    fwrite($pipes[0], "QUIT\n");
    
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    echo stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
