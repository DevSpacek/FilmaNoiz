<?php
namespace WooSFTPImporter;

/**
 * Class WooSFTPConnection
 *
 * This class manages the connection to the SFTP server, including authentication and file transfers.
 */
class WooSFTPConnection {
    private $connection;
    private $sftp;

    /**
     * Constructor to initialize the SFTP connection.
     *
     * @param string $host The SFTP server host.
     * @param int $port The SFTP server port.
     * @param string $username The username for authentication.
     * @param string $password The password for authentication.
     */
    public function __construct($host, $port, $username, $password) {
        $this->connect($host, $port, $username, $password);
    }

    /**
     * Establish a connection to the SFTP server.
     *
     * @param string $host The SFTP server host.
     * @param int $port The SFTP server port.
     * @param string $username The username for authentication.
     * @param string $password The password for authentication.
     */
    private function connect($host, $port, $username, $password) {
        $this->connection = ssh2_connect($host, $port);
        if (!$this->connection) {
            throw new Exception('Could not connect to SFTP server.');
        }

        if (!ssh2_auth_password($this->connection, $username, $password)) {
            throw new Exception('Authentication failed.');
        }

        $this->sftp = ssh2_sftp($this->connection);
        if (!$this->sftp) {
            throw new Exception('Could not initialize SFTP subsystem.');
        }
    }

    /**
     * Upload a file to the SFTP server.
     *
     * @param string $localFile The path to the local file.
     * @param string $remoteFile The path on the SFTP server.
     * @return bool True on success, false on failure.
     */
    public function uploadFile($localFile, $remoteFile) {
        return ssh2_scp_send($this->connection, $localFile, $remoteFile);
    }

    /**
     * Download a file from the SFTP server.
     *
     * @param string $remoteFile The path on the SFTP server.
     * @param string $localFile The path to save the downloaded file.
     * @return bool True on success, false on failure.
     */
    public function downloadFile($remoteFile, $localFile) {
        return ssh2_scp_recv($this->connection, $remoteFile, $localFile);
    }

    /**
     * Close the SFTP connection.
     */
    public function close() {
        $this->connection = null;
        $this->sftp = null;
    }
}
?>