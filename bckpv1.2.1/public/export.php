<?php include 'session.php'; include 'db.php';
header("Content-Type: application/xls");
header("Content-Disposition: attachment; filename=users.xls");
header("Pragma: no-cache");
header("Expires: 0");
$output = "<table border='1'><tr><th>ID</th><th>Nama</th><th>Email</th></tr>";
$data = $conn->query("SELECT * FROM users");
while ($row = $data->fetch_assoc()) {
  $output .= "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['email']}</td></tr>";
}
$output .= "</table>";
echo $output;
?>