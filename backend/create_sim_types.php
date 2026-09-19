<?php
$db = new mysqli('127.0.0.1','root','','stock_management');
$db->query("CREATE TABLE IF NOT EXISTS sim_types (id INT AUTO_INCREMENT PRIMARY KEY, sim_type VARCHAR(100) NOT NULL)");
$db->query("INSERT IGNORE INTO sim_types (id, sim_type) VALUES (1, 'Voice'), (2, 'Non Voice')");
echo $db->error;
