<?php
$db = new mysqli('127.0.0.1','root','','stock_management');
$res=$db->query("SELECT DISTINCT sim_type FROM sims");
if ($res) {
    while($row = $res->fetch_assoc()) print_r($row);
}
