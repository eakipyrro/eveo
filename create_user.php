<?php
require __DIR__.'/include/db.php';
$email = 'tomi.hakola@eveo.fi';
$hash = password_hash('#3Ve0T00l5', PASSWORD_DEFAULT);
db()->prepare('INSERT INTO users(email,password_hash,role) VALUES (?,?,?)')->execute([$email,$hash,'eveo']);
echo "ok\n";
?>