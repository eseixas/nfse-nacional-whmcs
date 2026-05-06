<?php
echo "Current Time: " . date('Y-m-d\TH:i:sP') . "\n";
echo "Current Time - 600: " . date('Y-m-d\TH:i:sP', time() - 600) . "\n";
echo "Current Time - 3600 (1h): " . date('Y-m-d\TH:i:sP', time() - 3600) . "\n";
echo "Current Time - 10800 (3h): " . date('Y-m-d\TH:i:sP', time() - 10800) . "\n";
