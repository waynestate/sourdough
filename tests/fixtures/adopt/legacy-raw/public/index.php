<?php

// A hand-built front controller from before Sourdough: no SESAME, no
// WayneUI, one page. Fixture for sourdough adopt; it shares no bytes with
// any template, so every template path it has comes out "yours".

$permits = array('A lot', 'B lot', 'Structure 2');

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head><title>Parking permits</title></head>
<body>
<h1>Parking permits</h1>
<ul>
<?php foreach ($permits as $permit) { ?>
    <li><?php echo htmlspecialchars($permit, ENT_QUOTES, 'UTF-8'); ?></li>
<?php } ?>
</ul>
</body>
</html>
