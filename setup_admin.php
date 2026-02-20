<?php

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// Generate admin password hash
require '/home/abrookstgz/ashchan/services/api-gateway/vendor/autoload.php';

$password = 'ChangeMe123!';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

echo "Generated hash for password '$password':\n";
echo "$hash\n\n";

// Update database
$dsn = 'pgsql:host=localhost;port=5432;dbname=ashchan';
$user = 'ashchan';
$pass = 'ashchan';

try {
    $pdo = new PDO($dsn, $user, $pass);
    $stmt = $pdo->prepare("UPDATE staff_users SET password_hash = ?, is_active = true, is_locked = false, failed_login_attempts = 0 WHERE username = 'admin'");
    $stmt->execute([$hash]);
    echo "Updated admin user in database.\n";
    echo "Rows affected: " . $stmt->rowCount() . "\n";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
