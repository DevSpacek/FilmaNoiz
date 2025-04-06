<?php
/**
 * Class SFTPConnection
 * 
 * This class handles the connection to the SFTP server.
 */
class SFTPConnection {
    private $connection;
    private $sftp;

    /**
     * Connect to the SFTP server.
     *
     * @param string $host
     * @param int $port
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function connect($host, $port, $username, $password) {
        $this->connection = ssh2_connect($host, $port);
        if (!$this->connection) {
            return false;
        }

        if (!ssh2_auth_password($this->connection, $username, $password)) {
            return false;
        }

        $this->sftp = ssh2_sftp($this->connection);
        return $this->sftp !== false;
    }

    /**
     * Disconnect from the SFTP server.
     */
    public function disconnect() {
        $this->connection = null;
        $this->sftp = null;
    }

    /**
     * Get the SFTP resource.
     *
     * @return resource
     */
    public function getSFTP() {
        return $this->sftp;
    }
}
?>