<?php
/**
 * SFTP Folder Manager Class
 * 
 * This class manages the creation and management of user folders on the SFTP server.
 */

class SFTPFolderManager {
    private $sftp_connection;

    public function __construct($sftp_connection) {
        $this->sftp_connection = $sftp_connection;
    }

    /**
     * Create user folders on the SFTP server.
     *
     * @param int $user_id The ID of the user for whom to create folders.
     * @return bool True on success, false on failure.
     */
    public function create_user_folders($user_id) {
        $user_folder = $this->get_user_folder_path($user_id);

        if ($this->folder_exists($user_folder)) {
            return true; // Folder already exists
        }

        // Create the user folder
        return $this->sftp_connection->mkdir($user_folder);
    }

    /**
     * Check if a folder exists on the SFTP server.
     *
     * @param string $folder_path The path of the folder to check.
     * @return bool True if the folder exists, false otherwise.
     */
    public function folder_exists($folder_path) {
        return $this->sftp_connection->exists($folder_path);
    }

    /**
     * Get the path for the user's folder.
     *
     * @param int $user_id The ID of the user.
     * @return string The path for the user's folder.
     */
    private function get_user_folder_path($user_id) {
        $base_directory = get_option('sftp_base_directory');
        $folder_name = sanitize_file_name(get_userdata($user_id)->user_login);
        return trailingslashit($base_directory) . $folder_name;
    }
}
?>