<?php

class FTP_Handler {
    private $ftp_server;
    private $ftp_user;
    private $ftp_pass;
    private $ftp_conn;

    public function __construct($server, $user, $pass) {
        $this->ftp_server = $server;
        $this->ftp_user = $user;
        $this->ftp_pass = $pass;
        $this->connect();
    }

    private function connect() {
        $this->ftp_conn = ftp_connect($this->ftp_server) or die("Could not connect to $this->ftp_server");
        ftp_login($this->ftp_conn, $this->ftp_user, $this->ftp_pass) or die("Could not log in to $this->ftp_server");
    }

    public function read_file($remote_file) {
        $temp_handle = fopen('php://temp', 'r+');
        if (ftp_fget($this->ftp_conn, $temp_handle, $remote_file, FTP_BINARY, 0)) {
            rewind($temp_handle);
            $contents = stream_get_contents($temp_handle);
            fclose($temp_handle);
            return $contents;
        }
        fclose($temp_handle);
        return false;
    }

    public function write_file($remote_file, $data) {
        $temp_handle = fopen('php://temp', 'r+');
        fwrite($temp_handle, $data);
        rewind($temp_handle);
        $result = ftp_fput($this->ftp_conn, $remote_file, $temp_handle, FTP_BINARY);
        fclose($temp_handle);
        return $result;
    }

    public function __destruct() {
        ftp_close($this->ftp_conn);
    }
}