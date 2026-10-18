
<?php
const DRIVER_NAME = 'pdo_pgsql';

if (extension_loaded(DRIVER_NAME)) {
    echo "BERHASIL! Driver PostgreSQL aktif.";
} else {
    echo "GAGAL! Driver PostgreSQL belum aktif.";
}
