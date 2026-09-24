-- Some SMB/NAS mounts deliberately do not expose or preserve Unix ownership and modes.
-- The compatibility switch never relaxes path, identity, read/write/rename/delete checks.
ALTER TABLE storage_locations ADD COLUMN enforce_file_attributes BOOLEAN NOT NULL DEFAULT TRUE AFTER directory_mode;
