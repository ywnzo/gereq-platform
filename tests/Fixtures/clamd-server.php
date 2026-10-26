<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
$path = $arguments[1] ?? null;
$encodedVersion = $arguments[2] ?? null;
$encodedReply = $arguments[3] ?? null;
if (!is_string($path) || !is_string($encodedVersion) || !is_string($encodedReply)) {
    exit(1);
}
$version = base64_decode($encodedVersion, true);
$reply = base64_decode($encodedReply, true);
if ($version === false || $reply === false) {
    exit(1);
}
$server = stream_socket_server('unix://' . $path);
if ($server === false) {
    exit(1);
}
echo "READY\n";
flush();
for ($i = 0; $i < 2; ++$i) {
    $client = stream_socket_accept($server, 5);
    if ($client === false) {
        break;
    }
    stream_set_timeout($client, 3);
    $command = '';
    while (($byte = fread($client, 1)) !== '' && $byte !== "\0") {
        $command .= $byte;
    }
    if ($command === 'zVERSION') {
        fwrite($client, $version . "\0");
    } elseif ($command === 'zINSTREAM') {
        $content = '';
        while (true) {
            $header = '';
            while (strlen($header) < 4 && !feof($client)) {
                $header .= fread($client, 4 - strlen($header));
            }
            if (strlen($header) !== 4) {
                break;
            }
            $size = unpack('Nsize', $header)['size'];
            if ($size === 0) {
                break;
            }
            while ($size > 0 && !feof($client)) {
                $chunk = fread($client, min($size, 65536));
                $content .= $chunk;
                $size -= strlen($chunk);
            }
        }
        file_put_contents($path . '.hash', hash('sha256', $content));
        if ($reply === '__STALL__') {
            usleep(1500000);
        }
        @fwrite($client, $reply . "\0");
    }
    fclose($client);
}
fclose($server);
