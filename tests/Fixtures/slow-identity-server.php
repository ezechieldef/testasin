<?php

$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if ($server === false) {
    throw new RuntimeException($errorMessage, $errorNumber);
}
fwrite(STDOUT, stream_socket_get_name($server, false)."\n");
fflush(STDOUT);
$connection = stream_socket_accept($server, 10);
if ($connection !== false) {
    /** Simule un service qui accepte la connexion, mais ne renvoie jamais ses en-têtes à temps. */
    sleep(8);
    fclose($connection);
}
fclose($server);
